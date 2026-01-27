<?php

namespace App\Services\WarSimulator\Simulators;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Services\WarSimulator\Support\WarSimModifiers;
use App\Services\WarSimulator\Support\WarSimRng;

final class GroundAttackSimulator
{
    /**
     * @return array<string, mixed>
     */
    public function simulate(WarSimRequestData $request, WarSimModifiers $modifiers, WarSimRng $rng): array
    {
        $action = $request->action;
        $attacker = $request->nationAttacker;
        $defender = $request->nationDefender;

        $attackingSoldiers = max(0, $action->attackingSoldiers);
        $attackingTanks = max(0, $action->attackingTanks);

        if ($attackingSoldiers === 0 && $attackingTanks === 0) {
            return $this->emptyResult();
        }

        $defendingSoldiers = max(0, $defender->soldiers);
        $defendingTanks = max(0, $defender->tanks);
        $resistingBonus = $defender->highestCityPopulation / 400;
        $defendingSoldiersEffective = $defendingSoldiers + $resistingBonus;

        $attackingSoldierValue = $attackingSoldiers * ($action->armSoldiersWithMunitions ? 1.75 : 1.0);
        $defendingSoldierValue = $defendingSoldiersEffective * 1.75;
        $attackingTankValue = $attackingTanks * 40 * $modifiers->attackerTankStrengthFactor;
        $defendingTankValue = $defendingTanks * 40 * $modifiers->defenderTankStrengthFactor;

        $attackerSoldierLosses = 0.0;
        $attackerTankLosses = 0.0;
        $defenderSoldierLosses = 0.0;
        $defenderTankLosses = 0.0;
        $attackerWins = 0;

        for ($roll = 0; $roll < 3; $roll++) {
            $attSoldierRoll = $rng->nextFloat(0.4 * $attackingSoldierValue, $attackingSoldierValue);
            $defSoldierRoll = $rng->nextFloat(0.4 * $defendingSoldierValue, $defendingSoldierValue);
            $attTankRoll = $rng->nextFloat(0.4 * $attackingTankValue, $attackingTankValue);
            $defTankRoll = $rng->nextFloat(0.4 * $defendingTankValue, $defendingTankValue);

            $attRoll = $attSoldierRoll + $attTankRoll;
            $defRoll = $defSoldierRoll + $defTankRoll;

            if ($attRoll > $defRoll) {
                $attackerWins++;
                $attackerTankLosses += ($defSoldierRoll * 0.0004060606) + ($defTankRoll * 0.00066666666);
                $defenderTankLosses += ($attSoldierRoll * 0.00043225806) + ($attTankRoll * 0.00070967741);
            } else {
                $attackerTankLosses += ($defSoldierRoll * 0.00043225806) + ($defTankRoll * 0.00070967741);
                $defenderTankLosses += ($attSoldierRoll * 0.0004060606) + ($attTankRoll * 0.00066666666);
            }

            $attackerSoldierLosses += ($defSoldierRoll * 0.0084) + ($defTankRoll * 0.0092);
            $defenderSoldierLosses += ($attSoldierRoll * 0.0084) + ($attTankRoll * 0.0092);
        }

        $attackerSoldierLosses *= $modifiers->attackerCasualtyFactor;
        $attackerTankLosses *= $modifiers->attackerCasualtyFactor;
        $defenderSoldierLosses *= $modifiers->defenderCasualtyFactor;
        $defenderTankLosses *= $modifiers->defenderCasualtyFactor;

        $defenderAircraftLosses = $this->applyGroundControlAircraftLosses(
            $request->context->groundControlOwner,
            $attackerWins,
            $attackingTanks,
        );

        $infraDestroyed = $this->calculateInfra(
            attackerSoldiers: $attackingSoldiers,
            attackerTanks: $attackingTanks,
            defenderSoldiersEffective: $defendingSoldiersEffective,
            defenderTanks: $defendingTanks,
            outcomeTier: $attackerWins,
            highestCityInfra: $defender->highestCityInfra,
            modifiers: $modifiers,
            rng: $rng,
        );

        $loot = $this->calculateLoot(
            attackerSoldiers: $attackingSoldiers,
            attackerTanks: $attackingTanks,
            outcomeTier: $attackerWins,
            defenderMoney: $defender->money,
            modifiers: $modifiers,
            rng: $rng,
        );

        $improvementDestroyChance = $this->calculateImprovementDestroyChance(
            outcomeTier: $attackerWins,
            attackerPolicy: $request->context->attackerPolicy,
            defenderPolicy: $request->context->defenderPolicy,
        );

        $attackerSoldierLosses = min(max($attackerSoldierLosses, 0.0), (float) $attackingSoldiers);
        $attackerTankLosses = min(max($attackerTankLosses, 0.0), (float) $attackingTanks);
        $defenderSoldierLosses = min(max($defenderSoldierLosses, 0.0), (float) $defendingSoldiers);
        $defenderTankLosses = min(max($defenderTankLosses, 0.0), (float) $defendingTanks);
        $defenderAircraftLosses = min(max($defenderAircraftLosses, 0.0), (float) $defender->aircraft);

        return [
            'outcome' => $attackerWins,
            'attacker_losses' => [
                'soldiers' => $attackerSoldierLosses,
                'tanks' => $attackerTankLosses,
                'aircraft' => 0.0,
                'ships' => 0.0,
            ],
            'defender_losses' => [
                'soldiers' => $defenderSoldierLosses,
                'tanks' => $defenderTankLosses,
                'aircraft' => $defenderAircraftLosses,
                'ships' => 0.0,
            ],
            'infra_destroyed' => $infraDestroyed,
            'money_looted' => $loot,
            'money_destroyed' => null,
            'improvement_destroy_chance' => $improvementDestroyChance,
        ];
    }

    private function emptyResult(): array
    {
        return [
            'outcome' => 0,
            'attacker_losses' => [
                'soldiers' => 0.0,
                'tanks' => 0.0,
                'aircraft' => 0.0,
                'ships' => 0.0,
            ],
            'defender_losses' => [
                'soldiers' => 0.0,
                'tanks' => 0.0,
                'aircraft' => 0.0,
                'ships' => 0.0,
            ],
            'infra_destroyed' => 0.0,
            'money_looted' => 0.0,
            'money_destroyed' => null,
            'improvement_destroy_chance' => 0.0,
        ];
    }

    private function applyGroundControlAircraftLosses(
        string $groundControlOwner,
        int $outcomeTier,
        int $attackingTanks,
    ): float {
        if ($groundControlOwner !== 'attacker' || $outcomeTier === 0) {
            return 0.0;
        }

        $multiplier = match ($outcomeTier) {
            3 => 0.005025,
            2 => 0.00335,
            1 => 0.001675,
            default => 0.0,
        };

        return $attackingTanks * $multiplier;
    }

    private function calculateInfra(
        int $attackerSoldiers,
        int $attackerTanks,
        float $defenderSoldiersEffective,
        int $defenderTanks,
        int $outcomeTier,
        float $highestCityInfra,
        WarSimModifiers $modifiers,
        WarSimRng $rng,
    ): float {
        if ($outcomeTier === 0) {
            return 0.0;
        }

        $raw = (($attackerSoldiers - ($defenderSoldiersEffective * 0.5)) * 0.000606061)
            + (($attackerTanks - ($defenderTanks * 0.5)) * 0.01);

        $infra = $raw * $rng->nextFloat(0.85, 1.05) * ($outcomeTier / 3);
        $cap = ($highestCityInfra * 0.2) + 25;
        $infra = min($infra, $cap);
        $infra = max($infra, 0.0);

        return $infra * $modifiers->infraMultiplier();
    }

    private function calculateLoot(
        int $attackerSoldiers,
        int $attackerTanks,
        int $outcomeTier,
        ?float $defenderMoney,
        WarSimModifiers $modifiers,
        WarSimRng $rng,
    ): float {
        if ($outcomeTier === 0) {
            return 0.0;
        }

        $base = ($attackerSoldiers * 1.1) + ($attackerTanks * 25.15);
        $loot = $base * $outcomeTier * $rng->nextFloat(0.8, 1.1) * $modifiers->lootMultiplier();

        if ($defenderMoney === null) {
            return max($loot, 0.0);
        }

        $cap = min($defenderMoney * 0.75, $defenderMoney - 1000000);
        $cap = max($cap, 0.0);

        return max(min($loot, $cap), 0.0);
    }

    private function calculateImprovementDestroyChance(
        int $outcomeTier,
        string $attackerPolicy,
        string $defenderPolicy,
    ): float {
        if ($outcomeTier !== 3) {
            return 0.0;
        }

        $chance = 10.0;

        if ($attackerPolicy === 'PIRATE') {
            $chance *= 2;
        }

        if ($attackerPolicy === 'TACTICIAN') {
            $chance *= 2;
        }

        if ($defenderPolicy === 'GUARDIAN') {
            $chance *= 0.5;
        }

        return min($chance, 100.0);
    }
}
