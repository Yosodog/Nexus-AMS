<?php

namespace App\Services\Spy;

use App\Enums\SpyOperationType;
use App\Models\Nation;

/**
 * Calculates spy success odds, impact, and synergy for candidate pairs.
 */
class SpyOddsCalculatorService
{
    /**
     * Calculate odds and derived metrics for a pairing.
     *
     * @return array{success_chance:float, expected_impact:float, safety_level:int, policy_synergy:float, low_odds:bool}
     */
    public function calculate(
        Nation $attacker,
        Nation $defender,
        SpyOperationType $opType,
        ?float $minSuccessChance = null
    ): array {
        $minSuccess = $minSuccessChance ?? 50.0;
        $operationModifier = $this->operationModifier($opType);

        $attackerSpies = (int) ($attacker->military?->spies ?? 0);
        $defenderSpies = (int) ($defender->military?->spies ?? 0);

        $policySynergy = $this->policySynergy($attacker, $defender, $opType);

        $evaluated = [];
        foreach ([1, 2, 3] as $safetyLevel) {
            $odds = ($safetyLevel * 25) + (($attackerSpies * 100) / ((($defenderSpies * 3) + 1) ?: 1));
            $finalOdds = $odds / $operationModifier;

            $finalOdds = $this->applyPolicyAdjustments($finalOdds, $attacker->war_policy, $defender->war_policy);
            $finalOdds = min(100.0, max(0.0, $finalOdds));
            $evaluated[] = [
                'safety' => $safetyLevel,
                'odds' => round($finalOdds, 2),
            ];
        }

        $selected = collect($evaluated)
            ->firstWhere(fn (array $combo) => $combo['odds'] >= $minSuccess)
            ?? collect($evaluated)->sortByDesc('odds')->first();

        $expectedImpact = $this->expectedImpact($opType, $attacker, $defender, $policySynergy, $selected['odds']);

        return [
            'success_chance' => $selected['odds'],
            'expected_impact' => $expectedImpact,
            'safety_level' => (int) $selected['safety'],
            'policy_synergy' => $policySynergy,
            'low_odds' => $selected['odds'] < $minSuccess,
        ];
    }

    protected function operationModifier(SpyOperationType $opType): float
    {
        $map = [
            SpyOperationType::GATHER_INTELLIGENCE->value => 1.0,
            SpyOperationType::ASSASSINATE_SPIES->value => 1.5,
            SpyOperationType::SABOTAGE_TANKS->value => 1.5,
            SpyOperationType::SABOTAGE_AIRCRAFT->value => 2.0,
            SpyOperationType::SABOTAGE_SHIPS->value => 3.0,
            SpyOperationType::SABOTAGE_MISSILES->value => 4.0,
            SpyOperationType::SABOTAGE_NUKES->value => 5.0,
        ];

        return $map[$opType->value] ?? 1.2;
    }

    protected function applyPolicyAdjustments(float $odds, ?string $attackerPolicy, ?string $defenderPolicy): float
    {
        $adjusted = $odds;

        if ($attackerPolicy === 'COVERT') {
            $adjusted *= 1.15;
        }

        if ($defenderPolicy === 'ARCANE') {
            $adjusted *= 0.85;
        }

        if ($defenderPolicy === 'TACTICIAN') {
            $adjusted *= 1.15;
        }

        return round($adjusted, 2);
    }

    protected function policySynergy(Nation $attacker, Nation $defender, SpyOperationType $opType): float
    {
        $synergy = 0.0;

        if ($attacker->war_policy === 'COVERT') {
            $synergy += 0.15;
        }

        if ($defender->war_policy === 'ARCANE') {
            $synergy -= 0.1;
        }

        if ($defender->war_policy === 'TACTICIAN') {
            $synergy += 0.1;
        }

        if ($opType === SpyOperationType::GATHER_INTELLIGENCE && $attacker->war_policy === 'TACTICIAN') {
            $synergy += 0.05;
        }

        return round($synergy, 2);
    }

    protected function expectedImpact(
        SpyOperationType $opType,
        Nation $attacker,
        Nation $defender,
        float $policySynergy,
        float $successChance
    ): float {
        $military = $defender->military;
        $successScalar = max(0, $successChance) / 100;
        $base = match ($opType) {
            SpyOperationType::TERRORIZE_CIVILIANS => 34.5 * $successScalar,
            SpyOperationType::SABOTAGE_SOLDIERS => ((float) ($military?->soldiers ?? 0) * 0.03) * $successScalar,
            SpyOperationType::SABOTAGE_TANKS => ((float) ($military?->tanks ?? 0) * 0.03) * $successScalar,
            SpyOperationType::SABOTAGE_AIRCRAFT => ((float) ($military?->aircraft ?? 0) * 0.03) * $successScalar,
            SpyOperationType::SABOTAGE_SHIPS => ((float) ($military?->ships ?? 0) * 0.03) * $successScalar,
            SpyOperationType::ASSASSINATE_SPIES => $this->expectedSpyKills(
                (int) ($military?->spies ?? 0),
                (int) ($attacker->military?->spies ?? 0),
                $successScalar
            ),
            SpyOperationType::SABOTAGE_MISSILES => min((float) ($military?->missiles ?? 0), 1.25) * $successScalar,
            SpyOperationType::SABOTAGE_NUKES => min((float) ($military?->nukes ?? 0), 1.0) * $successScalar,
            default => max(1.0, (float) ($defender->score ?? 0) * 0.1) * $successScalar,
        };

        if ($attacker->war_policy === 'TACTICIAN' && $this->targetsImprovements($opType)) {
            $base *= 2;
        }

        $base *= (1 + $policySynergy);

        return round(max(0, $base), 2);
    }

    protected function expectedSpyKills(int $defenderSpies, int $attackerSpies, float $successScalar): float
    {
        $raw = max(0, ($attackerSpies - ($defenderSpies * 0.4)) * 0.335);
        $expectedBase = $raw * 0.95; // average between 85–105%
        $cap = ($defenderSpies * 0.25) + 4;
        $kills = min($cap, $expectedBase);

        return $kills * $successScalar;
    }

    protected function targetsImprovements(SpyOperationType $opType): bool
    {
        return in_array($opType, [
            SpyOperationType::SABOTAGE_TANKS,
            SpyOperationType::SABOTAGE_AIRCRAFT,
            SpyOperationType::SABOTAGE_SHIPS,
            SpyOperationType::SABOTAGE_MISSILES,
            SpyOperationType::SABOTAGE_NUKES,
        ], true);
    }
}
