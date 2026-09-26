<?php

namespace App\Services\Raids;

use App\DataTransferObjects\MarketPriceSet;
use App\DataTransferObjects\Raids\RaidAttacker;
use App\DataTransferObjects\Raids\RaidValuation;
use App\DataTransferObjects\Raids\RaidWarContext;
use App\Models\RaidAllianceProfile;
use App\Models\RaidTargetProfile;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\RaidStockpileEstimator;
use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\WarSimulator\Support\WarSimModifiers;
use Carbon\CarbonImmutable;

/**
 * Closed-form expected return of a raid. The finder, the Discord endpoint, and
 * prediction capture all use this one model, so members see the number that is assessed.
 */
final class RaidValuationService
{
    public const MODEL_VERSION = 'raid-valuation-2026-10';

    private const GROUND_LOOT_FLOOR = 1_000_000.0;

    /** @var array<string, float> */
    private array $armyValues = [];

    public function __construct(
        private GroundBattleOdds $odds,
        private RaidStockpileEstimator $estimator,
        private MilitaryCostCalculator $militaryCosts,
    ) {}

    public function evaluate(
        RaidAttacker $attacker,
        RaidTargetProfile $target,
        ?RaidAllianceProfile $alliance,
        RaidWarContext $context,
        MarketPriceSet $prices,
        CarbonImmutable $at,
    ): RaidValuation {
        $assumptions = ['Bounties are not included.', 'Air superiority is not modelled.'];

        $projection = $this->estimator->project($target, $at);
        $projected = $projection['resources'];
        $projectedValue = RaidResources::liquidationValue($projected, $prices);
        $interval = $this->estimator->intervalFactors($target, $at);
        $activity = RaidActivity::bucket($target->last_active, $at);

        $activeProbability = (float) (config('raids.valuation.active_probability')[$activity] ?? 0.0);
        $retained = 1 - $activeProbability * (float) config('raids.valuation.active_deposit_fraction');
        $stock = array_map(fn (float $amount): float => $amount * $retained, $projected);

        if ($activeProbability > 0.2) {
            $assumptions[] = 'Active targets may deposit or spend resources during the war.';
        }

        $startingMap = (int) config('raids.valuation.starting_map');
        $maxAttacks = intdiv($startingMap + (int) config('raids.valuation.turns_per_war'), 3);
        $munitionsPerAttack = $attacker->soldiers * 0.0002 + $attacker->tanks * 0.01;
        $gasolinePerAttack = $attacker->tanks * 0.01;
        $armed = $attacker->resources === null
            || (float) ($attacker->resources['munitions'] ?? 0.0) >= $munitionsPerAttack * $maxAttacks;

        $attSoldierValue = $attacker->soldiers * ($armed ? 1.75 : 1.0);
        $attTankValue = $attacker->tanks * 40.0;
        $defSoldierValue = ($target->soldiers + $target->highest_city_population / 400) * 1.75;
        $defTankValue = $target->tanks * 40.0;

        $p = $this->odds->rollWinProbability($attSoldierValue, $attTankValue, $defSoldierValue, $defTankValue);
        $distribution = $this->odds->outcomeDistribution($p);
        $winProbability = $distribution[1] + $distribution[2] + $distribution[3];
        $expectedDamage = 4 * $distribution[1] + 7 * $distribution[2] + 10 * $distribution[3];
        $damageVariance = (16 * $distribution[1] + 49 * $distribution[2] + 100 * $distribution[3]) - $expectedDamage ** 2;

        $expectedAttacks = $expectedDamage > 0 ? min($maxAttacks, (int) ceil(100 / $expectedDamage)) : $maxAttacks;
        $victoryProbability = $expectedDamage <= 0
            ? 0.0
            : GroundBattleOdds::normalCdf(($maxAttacks * $expectedDamage - 100) / sqrt(max(1e-9, $maxAttacks * $damageVariance)));
        $durationHours = max(0, 3 * $expectedAttacks - $startingMap) * (float) config('raids.valuation.turn_hours');

        $lootModifiers = WarSimModifiers::forLoot(
            'RAID',
            $attacker->warPolicy,
            (string) $target->war_policy,
            $attacker->pirateEconomy,
            $attacker->advancedPirateEconomy,
        );

        $groundPerAttack = ($attacker->soldiers * 1.1 + $attacker->tanks * 25.15) * (3 * $p) * 0.95 * $lootModifiers->lootMultiplier();
        $money = $stock['money'];
        $groundLoot = 0.0;

        for ($attack = 0; $attack < $expectedAttacks; $attack++) {
            $take = min($groundPerAttack, max(0.0, min(0.75 * $money, $money - self::GROUND_LOOT_FLOOR)));
            $groundLoot += $take;
            $money -= $take;
        }

        $stock['money'] = $money;
        $beigeShare = 1 / (1 + max(0, $context->otherAttackers));
        $victoryFraction = RaidLootFormula::victoryLootFraction($lootModifiers->victoryLootMultiplier());
        $aligned = $target->alliance_id > 0 && strtoupper((string) $target->alliance_position) !== 'APPLICANT';
        $bank = $aligned ? $alliance?->bankResources() : null;
        $bankFraction = $bank === null
            ? 0.0
            : (float) RaidLootFormula::expectedBankLootFraction($attacker->score, (float) $alliance->alliance_score, $lootModifiers->bankLootMultiplier());

        if ($aligned && $bank === null) {
            $assumptions[] = 'Alliance bank loot is unavailable.';
        }

        $base = $this->loot($stock, 1.0, $bank, $bankFraction, $victoryFraction, $victoryProbability, $beigeShare, $prices);
        $low = $this->loot($stock, $interval['low'], $bank, $bankFraction, $victoryFraction, $victoryProbability, $beigeShare, $prices);
        $high = $this->loot($stock, $interval['high'], $bank, $bankFraction, $victoryFraction, $victoryProbability, $beigeShare, $prices);

        $costResources = [
            'munitions' => (($armed ? $attacker->soldiers * 0.0002 : 0.0) + $attacker->tanks * 0.01) * $expectedAttacks,
            'gasoline' => $gasolinePerAttack * $expectedAttacks,
        ];
        $consumables = RaidResources::acquisitionValue($costResources, $prices);
        $militaryLosses = $this->militaryLosses($attacker, $p, $defSoldierValue, $defTankValue, $expectedAttacks, $prices);
        $counterProbability = $aligned
            ? $context->counterRate
            : (float) config('raids.valuation.unaligned_counter_rate');
        $counterRisk = $counterProbability * (float) config('raids.valuation.counter_loss_fraction') * $this->armyValue($attacker, $prices);
        $costs = $consumables + $militaryLosses + $counterRisk;

        $grossLoot = $groundLoot + $base['nation_loot'] + $base['bank_loot'];
        $expectedNet = $grossLoot - $costs;
        $expectedNetLow = $groundLoot + $low['nation_loot'] + $low['bank_loot'] - $costs;
        $expectedNetHigh = $groundLoot + $high['nation_loot'] + $high['bank_loot'] - $costs;

        $lootResources = $base['resources'];
        $lootResources['money'] += $groundLoot;
        $ageHours = $target->baseline_at === null ? 0.0 : max(0, $at->getTimestamp() - $target->baseline_at->getTimestamp()) / 3600;

        return new RaidValuation(
            expectedNet: round($expectedNet, 2),
            expectedNetLow: round(min($expectedNetLow, $expectedNet), 2),
            expectedNetHigh: round(max($expectedNetHigh, $expectedNet), 2),
            grossLoot: round($grossLoot, 2),
            winProbability: round($winProbability, 4),
            victoryProbability: round($victoryProbability, 4),
            beigeShare: round($beigeShare, 4),
            expectedAttacks: $expectedAttacks,
            durationHours: round($durationHours, 2),
            confidence: $this->confidence($target, $ageHours),
            counterProbability: round($counterProbability, 4),
            components: array_map(fn (float $value): float => round($value, 2), [
                'gross_loot' => $grossLoot,
                'nation_loot' => $base['nation_loot'],
                'ground_loot' => $groundLoot,
                'bank_loot' => $base['bank_loot'],
                'bounty' => 0.0,
                'consumables' => $consumables,
                'military_losses' => $militaryLosses,
                'infrastructure_losses' => 0.0,
                'counter_risk' => $counterRisk,
            ]),
            lootResources: RaidResources::rounded($lootResources),
            costResources: RaidResources::rounded($costResources),
            stockpile: [
                'resources' => RaidResources::rounded($projected),
                'value' => round($projectedValue, 2),
                'low_value' => round($projectedValue * $interval['low'], 2),
                'high_value' => round($projectedValue * $interval['high'], 2),
                'evidence_kind' => (string) $target->baseline_kind,
                'evidence_at' => $target->baseline_at?->toIso8601String(),
                'evidence_age_hours' => round($ageHours, 2),
                'retention' => round($projection['retention'], 4),
                'activity_bucket' => $activity,
            ],
            plan: array_fill(0, $expectedAttacks, 'ground'),
            assumptions: $assumptions,
        );
    }

    /**
     * Expected victory and bank loot for the stock scaled by a stockpile multiplier.
     *
     * @param  array<string, float>  $stock
     * @param  array<string, float>|null  $bank
     * @return array{resources: array<string, float>, nation_loot: float, bank_loot: float}
     */
    private function loot(
        array $stock,
        float $stockMultiplier,
        ?array $bank,
        float $bankFraction,
        float $victoryFraction,
        float $victoryProbability,
        float $beigeShare,
        MarketPriceSet $prices,
    ): array {
        $chance = $victoryProbability * $beigeShare;
        $nationResources = array_map(
            fn (float $amount): float => $victoryFraction * $amount * $stockMultiplier * $chance,
            $stock,
        );
        $bankResources = $bank === null
            ? []
            : array_map(fn (float $amount): float => $bankFraction * $amount * $chance, $bank);
        $resources = $nationResources;

        foreach ($bankResources as $resource => $amount) {
            $resources[$resource] = ($resources[$resource] ?? 0.0) + $amount;
        }

        return [
            'resources' => $resources,
            'nation_loot' => RaidResources::liquidationValue($nationResources, $prices),
            'bank_loot' => RaidResources::liquidationValue($bankResources, $prices),
        ];
    }

    private function militaryLosses(
        RaidAttacker $attacker,
        float $p,
        float $defSoldierValue,
        float $defTankValue,
        int $expectedAttacks,
        MarketPriceSet $prices,
    ): float {
        $soldierRoll = 0.7 * $defSoldierValue;
        $tankRoll = 0.7 * $defTankValue;
        $soldierLoss = min($attacker->soldiers, 3 * ($soldierRoll * 0.0084 + $tankRoll * 0.0092));
        $tankLoss = min($attacker->tanks, 3 * (
            $p * ($soldierRoll * 0.0004060606 + $tankRoll * 0.00066666666)
            + (1 - $p) * ($soldierRoll * 0.00043225806 + $tankRoll * 0.00070967741)
        ));

        return $this->purchaseValue(
            $attacker,
            (int) round(min($attacker->soldiers, $soldierLoss * $expectedAttacks)),
            (int) round(min($attacker->tanks, $tankLoss * $expectedAttacks)),
            $prices,
        );
    }

    private function armyValue(RaidAttacker $attacker, MarketPriceSet $prices): float
    {
        $key = $attacker->nationId.':'.$attacker->soldiers.':'.$attacker->tanks.':'.spl_object_id($prices);

        return $this->armyValues[$key] ??= $this->purchaseValue($attacker, $attacker->soldiers, $attacker->tanks, $prices);
    }

    private function purchaseValue(RaidAttacker $attacker, int $soldiers, int $tanks, MarketPriceSet $prices): float
    {
        if ($soldiers <= 0 && $tanks <= 0) {
            return 0.0;
        }

        $result = $this->militaryCosts->calculate(
            ['soldiers' => max(0, $soldiers), 'tanks' => max(0, $tanks)],
            $attacker->militaryResearch,
            true,
            strtoupper($attacker->domesticPolicy) === 'IMPERIALISM',
            $attacker->governmentSupportAgency,
            $attacker->bureauOfDomesticAffairs,
            $prices,
        );

        return (float) ($result->breakdowns['purchase']->marketValue ?? 0.0);
    }

    private function confidence(RaidTargetProfile $target, float $ageHours): string
    {
        if ($target->baseline_kind !== RaidTargetProfile::BASELINE_LOOT) {
            return 'low';
        }

        return match (true) {
            $ageHours < 168 => 'high',
            $ageHours < 720 => 'medium',
            default => 'low',
        };
    }
}
