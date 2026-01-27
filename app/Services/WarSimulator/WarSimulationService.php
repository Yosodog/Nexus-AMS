<?php

namespace App\Services\WarSimulator;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Services\TradePriceService;
use App\Services\WarSimulator\Simulators\AirstrikeSimulator;
use App\Services\WarSimulator\Simulators\GroundAttackSimulator;
use App\Services\WarSimulator\Simulators\NavalAttackSimulator;
use App\Services\WarSimulator\Support\PercentileCalculator;
use App\Services\WarSimulator\Support\WarSimModifiers;
use App\Services\WarSimulator\Support\WarSimRng;

final class WarSimulationService
{
    public function __construct(
        private TradePriceService $tradePriceService,
        private GroundAttackSimulator $groundAttackSimulator,
        private AirstrikeSimulator $airstrikeSimulator,
        private NavalAttackSimulator $navalAttackSimulator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(WarSimRequestData $request): array
    {
        $iterations = $this->clampIterations($request->iterations);
        $rng = new WarSimRng($request->seed);
        $modifiers = $this->buildModifiers($request);
        $prices = $this->tradePriceService->get24hAverage();
        $priceMap = [
            'gasoline' => (float) ($prices->gasoline ?? 0),
            'munitions' => (float) ($prices->munitions ?? 0),
            'steel' => (float) ($prices->steel ?? 0),
            'aluminum' => (float) ($prices->aluminum ?? 0),
        ];

        $resourceUsageAttacker = $this->calculateConsumablesForSide($request, 'attacker');
        $resourceUsageDefender = $this->calculateConsumablesForSide($request, 'defender');
        $consumablesValueAttacker = ($resourceUsageAttacker['gasoline'] * $priceMap['gasoline'])
            + ($resourceUsageAttacker['munitions'] * $priceMap['munitions']);
        $consumablesValueDefender = ($resourceUsageDefender['gasoline'] * $priceMap['gasoline'])
            + ($resourceUsageDefender['munitions'] * $priceMap['munitions']);

        $outcomeCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        $attackerLosses = $this->initializeLossBuckets();
        $defenderLosses = $this->initializeLossBuckets();
        $infraDestroyed = [];
        $moneyLooted = [];
        $moneyDestroyed = [];
        $gasolineUsedAttacker = [];
        $munitionsUsedAttacker = [];
        $gasolineUsedDefender = [];
        $munitionsUsedDefender = [];
        $consumablesValuesAttacker = [];
        $consumablesValuesDefender = [];
        $unitLossValues = [];
        $unitLossValuesDefender = [];
        $totalValues = [];
        $totalValuesDefender = [];
        $improvementDestroyChances = [];

        $trackLoot = $request->action->type === 'ground';
        $trackMoneyDestroyed = false;
        $moneyDestroyedUnavailable = $request->action->type === 'air' && $request->action->target === 'money';
        $trackImprovementChance = in_array($request->action->type, ['naval', 'ground'], true);

        for ($i = 0; $i < $iterations; $i++) {
            $result = $this->simulateIteration($request, $modifiers, $rng);

            $outcomeCounts[$result['outcome']]++;

            foreach (['soldiers', 'tanks', 'aircraft', 'ships'] as $unit) {
                $attackerLosses[$unit][] = $result['attacker_losses'][$unit];
                $defenderLosses[$unit][] = $result['defender_losses'][$unit];
            }

            $infraDestroyed[] = $result['infra_destroyed'];

            if ($trackLoot) {
                $moneyLooted[] = (float) $result['money_looted'];
            }

            if ($trackMoneyDestroyed) {
                $moneyDestroyed[] = $result['money_destroyed'] === null ? 0.0 : (float) $result['money_destroyed'];
            }

            if ($trackImprovementChance) {
                $improvementDestroyChances[] = (float) ($result['improvement_destroy_chance'] ?? 0.0);
            }

            $gasolineUsedAttacker[] = $resourceUsageAttacker['gasoline'];
            $munitionsUsedAttacker[] = $resourceUsageAttacker['munitions'];
            $gasolineUsedDefender[] = $resourceUsageDefender['gasoline'];
            $munitionsUsedDefender[] = $resourceUsageDefender['munitions'];
            $consumablesValuesAttacker[] = $consumablesValueAttacker;
            $consumablesValuesDefender[] = $consumablesValueDefender;

            $unitLossValue = $this->calculateUnitLossValue($result['attacker_losses'], $priceMap);
            $unitLossValueDefender = $this->calculateUnitLossValue($result['defender_losses'], $priceMap);
            $unitLossValues[] = $unitLossValue;
            $unitLossValuesDefender[] = $unitLossValueDefender;
            $totalValues[] = $consumablesValueAttacker + $unitLossValue;
            $totalValuesDefender[] = $consumablesValueDefender + $unitLossValueDefender;
        }

        $probabilities = [];
        foreach ($outcomeCounts as $tier => $count) {
            $probabilities[$this->tierToLabel($tier)] = $iterations > 0 ? round(($count / $iterations) * 100, 4) : 0.0;
        }

        return [
            'meta' => [
                'iterations' => $iterations,
                'seed' => $request->seed,
                'generated_at' => now()->toIso8601String(),
                'prices' => $priceMap,
            ],
            'outcomes' => [
                'probabilities' => $probabilities,
            ],
            'metrics' => [
                'attacker_losses' => [
                    'soldiers' => PercentileCalculator::summarize($attackerLosses['soldiers']),
                    'tanks' => PercentileCalculator::summarize($attackerLosses['tanks']),
                    'aircraft' => PercentileCalculator::summarize($attackerLosses['aircraft']),
                    'ships' => PercentileCalculator::summarize($attackerLosses['ships']),
                ],
                'defender_losses' => [
                    'soldiers' => PercentileCalculator::summarize($defenderLosses['soldiers']),
                    'tanks' => PercentileCalculator::summarize($defenderLosses['tanks']),
                    'aircraft' => PercentileCalculator::summarize($defenderLosses['aircraft']),
                    'ships' => PercentileCalculator::summarize($defenderLosses['ships']),
                ],
                'infra_destroyed' => PercentileCalculator::summarize($infraDestroyed),
                'money_looted' => $trackLoot ? PercentileCalculator::summarize($moneyLooted) : null,
                'money_destroyed' => $trackMoneyDestroyed ? PercentileCalculator::summarize($moneyDestroyed) : null,
                'resources_consumed_attacker' => [
                    'gasoline' => PercentileCalculator::summarize($gasolineUsedAttacker),
                    'munitions' => PercentileCalculator::summarize($munitionsUsedAttacker),
                ],
                'resources_consumed_defender' => [
                    'gasoline' => PercentileCalculator::summarize($gasolineUsedDefender),
                    'munitions' => PercentileCalculator::summarize($munitionsUsedDefender),
                ],
                'cost_estimates' => [
                    'consumables_value' => PercentileCalculator::summarize($consumablesValuesAttacker),
                    'unit_losses_value' => PercentileCalculator::summarize($unitLossValues),
                    'infra_value' => null,
                    'total_value' => PercentileCalculator::summarize($totalValues),
                ],
                'cost_estimates_defender' => [
                    'consumables_value' => PercentileCalculator::summarize($consumablesValuesDefender),
                    'unit_losses_value' => PercentileCalculator::summarize($unitLossValuesDefender),
                    'infra_value' => null,
                    'total_value' => PercentileCalculator::summarize($totalValuesDefender),
                ],
                'improvement_destroy_chance' => $trackImprovementChance ? PercentileCalculator::summarize($improvementDestroyChances) : null,
            ],
            'assumptions' => $this->buildAssumptions($request, $trackLoot, $moneyDestroyedUnavailable),
        ];
    }

    private function clampIterations(int $iterations): int
    {
        return max(100, min(20000, $iterations));
    }

    /**
     * @return array<string, array<int, float>>
     */
    private function initializeLossBuckets(): array
    {
        return [
            'soldiers' => [],
            'tanks' => [],
            'aircraft' => [],
            'ships' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulateIteration(WarSimRequestData $request, WarSimModifiers $modifiers, WarSimRng $rng): array
    {
        return match ($request->action->type) {
            'air' => $this->airstrikeSimulator->simulate($request, $modifiers, $rng),
            'naval' => $this->navalAttackSimulator->simulate($request, $modifiers, $rng),
            default => $this->groundAttackSimulator->simulate($request, $modifiers, $rng),
        };
    }

    private function buildModifiers(WarSimRequestData $request): WarSimModifiers
    {
        $warType = $request->context->warType;
        [$infraFactor, $lootFactor] = match ($warType) {
            'ATTRITION' => [1.0, 0.25],
            'RAID' => [0.25, 1.0],
            default => [0.5, 0.5],
        };

        $attackerPolicy = $request->context->attackerPolicy;
        $defenderPolicy = $request->context->defenderPolicy;

        $attackerLootPolicyFactor = $attackerPolicy === 'PIRATE' ? 1.4 : 1.0;
        $defenderLootPolicyFactor = $defenderPolicy === 'MONEYBAGS' ? 0.6 : 1.0;

        $attackerInfraPolicyFactor = $attackerPolicy === 'ATTRITION' ? 1.1 : 1.0;
        $defenderInfraPolicyFactor = 1.0;

        if ($defenderPolicy === 'TURTLE') {
            $defenderInfraPolicyFactor *= 0.9;
        }

        if ($defenderPolicy === 'MONEYBAGS') {
            $defenderInfraPolicyFactor *= 1.05;
        }

        if (in_array($defenderPolicy, ['COVERT', 'ARCANE'], true)) {
            $defenderInfraPolicyFactor *= 1.05;
        }

        $attackerBlitzFactor = $request->context->blitzActiveAttacker ? 1.1 : 1.0;
        $defenderBlitzFactor = $request->context->blitzActiveDefender ? 1.1 : 1.0;

        $airSuperiorityOwner = $request->context->airSuperiorityOwner;
        $attackerTankStrengthFactor = $airSuperiorityOwner === 'defender' ? 0.5 : 1.0;
        $defenderTankStrengthFactor = $airSuperiorityOwner === 'attacker' ? 0.5 : 1.0;

        $attackerCasualtyFactor = $defenderBlitzFactor;
        if ($request->nationDefender->isFortified) {
            $attackerCasualtyFactor *= 1.25;
        }

        $defenderCasualtyFactor = $attackerBlitzFactor;

        return new WarSimModifiers(
            warTypeInfraFactor: $infraFactor,
            warTypeLootFactor: $lootFactor,
            attackerLootPolicyFactor: $attackerLootPolicyFactor,
            defenderLootPolicyFactor: $defenderLootPolicyFactor,
            attackerInfraPolicyFactor: $attackerInfraPolicyFactor,
            defenderInfraPolicyFactor: $defenderInfraPolicyFactor,
            attackerBlitzFactor: $attackerBlitzFactor,
            defenderBlitzFactor: $defenderBlitzFactor,
            attackerTankStrengthFactor: $attackerTankStrengthFactor,
            defenderTankStrengthFactor: $defenderTankStrengthFactor,
            attackerCasualtyFactor: $attackerCasualtyFactor,
            defenderCasualtyFactor: $defenderCasualtyFactor,
        );
    }

    /**
     * @return array<string, float>
     */
    private function calculateConsumablesForSide(WarSimRequestData $request, string $side): array
    {
        $action = $request->action;

        if ($side === 'defender') {
            $defender = $request->nationDefender;

            return match ($action->type) {
                'air' => [
                    'gasoline' => $defender->aircraft * 0.25,
                    'munitions' => $defender->aircraft * 0.25,
                ],
                'naval' => [
                    'gasoline' => $defender->ships * 2,
                    'munitions' => $defender->ships * 3,
                ],
                default => [
                    'gasoline' => $defender->tanks * 0.01,
                    'munitions' => ($defender->soldiers * 0.0002) + ($defender->tanks * 0.01),
                ],
            };
        }

        return match ($action->type) {
            'air' => [
                'gasoline' => $action->attackingAircraft * 0.25,
                'munitions' => $action->attackingAircraft * 0.25,
            ],
            'naval' => [
                'gasoline' => $action->attackingShips * 2,
                'munitions' => $action->attackingShips * 3,
            ],
            default => [
                'gasoline' => $action->attackingTanks * 0.01,
                'munitions' => ($action->armSoldiersWithMunitions ? $action->attackingSoldiers * 0.0002 : 0)
                    + ($action->attackingTanks * 0.01),
            ],
        };
    }

    /**
     * @param  array<string, float|int>  $losses
     * @param  array<string, float>  $priceMap
     */
    private function calculateUnitLossValue(array $losses, array $priceMap): float
    {
        $soldierValue = 5;
        $tankValue = 60 + (0.5 * $priceMap['steel']);
        $aircraftValue = 4000 + (5 * $priceMap['aluminum']);
        $shipValue = 50000 + (30 * $priceMap['steel']);

        return ($losses['soldiers'] * $soldierValue)
            + ($losses['tanks'] * $tankValue)
            + ($losses['aircraft'] * $aircraftValue)
            + ($losses['ships'] * $shipValue);
    }

    private function tierToLabel(int $tier): string
    {
        return match ($tier) {
            3 => 'IT',
            2 => 'MS',
            1 => 'PV',
            default => 'UF',
        };
    }

    /**
     * @return array<int, string>
     */
    private function buildAssumptions(WarSimRequestData $request, bool $trackLoot, bool $moneyDestroyedUnavailable): array
    {
        $assumptions = [
            'Each battle uses three RNG rolls with uniform 40%-100% force multipliers.',
            'Defender soldiers are treated as armed; population resistance uses highest-infra city population.',
            'Fortified defenders increase attacker casualties by 25% for the action.',
            'Blitz bonuses apply a +10% multiplier to casualties and infra dealt by the acting side.',
            'Control-state changes are not simulated beyond specified aircraft-loss effects.',
            'Infra cost value is not modeled in total cost estimates.',
        ];
        $assumptions[] = 'City population is estimated from nation population and city infrastructure share when per-city population data is unavailable.';
        $assumptions[] = 'Defender resource usage assumes all defending units participate in the action type.';

        if ($request->nationDefender->money === null && $trackLoot) {
            $assumptions[] = 'Defender cash was unknown; money_looted reflects max potential loot.';
        }

        if ($moneyDestroyedUnavailable) {
            $assumptions[] = 'Airstrike money-destroy formula is not specified; money_destroyed is null.';
        }

        if ($request->context->airSuperiorityOwner !== 'none') {
            $assumptions[] = 'Air superiority halves opposing tank strength for calculations.';
        }

        return $assumptions;
    }
}
