<?php

namespace App\Services\WarSimulator\Simulators;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Services\WarSimulator\Support\WarSimModifiers;
use App\Services\WarSimulator\Support\WarSimRng;

final class NavalAttackSimulator
{
    /**
     * @return array<string, mixed>
     */
    public function simulate(WarSimRequestData $request, WarSimModifiers $modifiers, WarSimRng $rng): array
    {
        $action = $request->action;
        $defender = $request->nationDefender;

        $attackingShips = max(0, $action->attackingShips);
        if ($attackingShips === 0) {
            return $this->emptyResult();
        }

        $defendingShips = max(0, $defender->ships);
        $attackerNavyValue = $attackingShips * 4;
        $defenderNavyValue = $defendingShips * 4;

        $attackerShipLosses = 0.0;
        $defenderShipLosses = 0.0;
        $attackerWins = 0;

        for ($roll = 0; $roll < 3; $roll++) {
            $attRoll = $rng->nextFloat(0.4 * $attackerNavyValue, $attackerNavyValue);
            $defRoll = $rng->nextFloat(0.4 * $defenderNavyValue, $defenderNavyValue);

            if ($attRoll > $defRoll) {
                $attackerWins++;
            }

            $attackerShipLosses += $defRoll * 0.01375;
            $defenderShipLosses += $attRoll * 0.01375;
        }

        $attackerShipLosses *= $modifiers->attackerCasualtyFactor;
        $defenderShipLosses *= $modifiers->defenderCasualtyFactor;

        $infraDestroyed = $this->calculateInfra(
            attackerShips: $attackingShips,
            defenderShips: $defendingShips,
            outcomeTier: $attackerWins,
            highestCityInfra: $defender->highestCityInfra,
            modifiers: $modifiers,
            rng: $rng,
        );

        $improvementDestroyChance = $this->calculateImprovementDestroyChance(
            outcomeTier: $attackerWins,
            attackerPolicy: $request->context->attackerPolicy,
            defenderPolicy: $request->context->defenderPolicy,
        );

        return [
            'outcome' => $attackerWins,
            'attacker_losses' => [
                'soldiers' => 0.0,
                'tanks' => 0.0,
                'aircraft' => 0.0,
                'ships' => min(max($attackerShipLosses, 0.0), (float) $attackingShips),
            ],
            'defender_losses' => [
                'soldiers' => 0.0,
                'tanks' => 0.0,
                'aircraft' => 0.0,
                'ships' => min(max($defenderShipLosses, 0.0), (float) $defendingShips),
            ],
            'infra_destroyed' => $infraDestroyed,
            'money_looted' => null,
            'money_destroyed' => null,
            'improvement_destroy_chance' => $improvementDestroyChance,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
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
            'money_looted' => null,
            'money_destroyed' => null,
            'improvement_destroy_chance' => 0.0,
        ];
    }

    private function calculateInfra(
        int $attackerShips,
        int $defenderShips,
        int $outcomeTier,
        float $highestCityInfra,
        WarSimModifiers $modifiers,
        WarSimRng $rng,
    ): float {
        if ($outcomeTier === 0) {
            return 0.0;
        }

        $raw = ($attackerShips - ($defenderShips * 0.5))
            * 2.625
            * $rng->nextFloat(0.85, 1.05)
            * ($outcomeTier / 3);
        $cap = ($highestCityInfra * 0.5) + 25;
        $infra = min($raw, $cap);
        $infra = max($infra, 0.0);

        return $infra * $modifiers->infraMultiplier();
    }

    private function calculateImprovementDestroyChance(
        int $outcomeTier,
        string $attackerPolicy,
        string $defenderPolicy,
    ): float {
        if ($outcomeTier !== 3) {
            return 0.0;
        }

        $chance = 15.0;

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
