<?php

namespace App\Services\WarSimulator\Simulators;

use App\DataTransferObjects\WarSim\WarSimNationData;
use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Services\WarSimulator\Support\WarSimModifiers;
use App\Services\WarSimulator\Support\WarSimRng;

final class AirstrikeSimulator
{
    /**
     * @return array<string, mixed>
     */
    public function simulate(WarSimRequestData $request, WarSimModifiers $modifiers, WarSimRng $rng): array
    {
        $action = $request->action;
        $defender = $request->nationDefender;

        $attackingAircraft = max(0, $action->attackingAircraft);
        if ($attackingAircraft === 0) {
            return $this->emptyResult();
        }

        $defendingAircraft = max(0, $defender->aircraft);
        $attackerAirValue = $attackingAircraft * 3;
        $defenderAirValue = $defendingAircraft * 3;

        $attackerAircraftLosses = 0.0;
        $defenderAircraftLosses = 0.0;
        $attackerWins = 0;

        $isDogfight = $action->target === 'aircraft';

        for ($roll = 0; $roll < 3; $roll++) {
            $attRoll = $rng->nextFloat(0.4 * $attackerAirValue, $attackerAirValue);
            $defRoll = $rng->nextFloat(0.4 * $defenderAirValue, $defenderAirValue);

            if ($attRoll > $defRoll) {
                $attackerWins++;
            }

            if ($isDogfight) {
                $attackerAircraftLosses += $defRoll * 0.01;
                $defenderAircraftLosses += $attRoll * 0.018337;
            } else {
                $attackerAircraftLosses += $defRoll * 0.015385;
                $defenderAircraftLosses += $attRoll * 0.009091;
            }
        }

        $attackerAircraftLosses *= $modifiers->attackerCasualtyFactor;
        $defenderAircraftLosses *= $modifiers->defenderCasualtyFactor;

        $infraDestroyed = $this->calculateInfra(
            attackerAircraft: $attackingAircraft,
            defenderAircraft: $defendingAircraft,
            outcomeTier: $attackerWins,
            highestCityInfra: $defender->highestCityInfra,
            modifiers: $modifiers,
            rng: $rng,
            target: $action->target,
        );

        [$defenderSoldierLosses, $defenderTankLosses, $defenderShipLosses] = $this->calculateTargetLosses(
            target: $action->target,
            attackerAircraft: $attackingAircraft,
            defenderAircraft: $defendingAircraft,
            defender: $defender,
            outcomeTier: $attackerWins,
            rng: $rng,
            defenderCasualtyFactor: $modifiers->defenderCasualtyFactor,
        );

        $attackerAircraftLosses = min(max($attackerAircraftLosses, 0.0), (float) $attackingAircraft);
        $defenderAircraftLosses = min(max($defenderAircraftLosses, 0.0), (float) $defendingAircraft);

        return [
            'outcome' => $attackerWins,
            'attacker_losses' => [
                'soldiers' => 0.0,
                'tanks' => 0.0,
                'aircraft' => $attackerAircraftLosses,
                'ships' => 0.0,
            ],
            'defender_losses' => [
                'soldiers' => max($defenderSoldierLosses, 0.0),
                'tanks' => max($defenderTankLosses, 0.0),
                'aircraft' => $defenderAircraftLosses,
                'ships' => max($defenderShipLosses, 0.0),
            ],
            'infra_destroyed' => $infraDestroyed,
            'money_looted' => null,
            'money_destroyed' => null,
            'improvement_destroy_chance' => null,
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
            'improvement_destroy_chance' => null,
        ];
    }

    private function calculateInfra(
        int $attackerAircraft,
        int $defenderAircraft,
        int $outcomeTier,
        float $highestCityInfra,
        WarSimModifiers $modifiers,
        WarSimRng $rng,
        string $target,
    ): float {
        if ($outcomeTier === 0) {
            return 0.0;
        }

        $raw = ($attackerAircraft - ($defenderAircraft * 0.5))
            * 0.35353535
            * $rng->nextFloat(0.85, 1.05)
            * ($outcomeTier / 3);
        $cap = ($highestCityInfra * 0.5) + 100;
        $infra = min($raw, $cap);
        $infra = max($infra, 0.0);

        if ($target !== 'infra') {
            $infra /= 3;
        }

        return $infra * $modifiers->infraMultiplier();
    }

    /**
     * @return array<int, float>
     */
    private function calculateTargetLosses(
        string $target,
        int $attackerAircraft,
        int $defenderAircraft,
        WarSimNationData $defender,
        int $outcomeTier,
        WarSimRng $rng,
        float $defenderCasualtyFactor,
    ): array {
        if ($outcomeTier === 0) {
            return [0.0, 0.0, 0.0];
        }

        $multiplier = match ($outcomeTier) {
            3 => 1.0,
            2 => 0.7,
            1 => 0.4,
            default => 0.0,
        };

        $soldierLosses = 0.0;
        $tankLosses = 0.0;
        $shipLosses = 0.0;

        if ($target === 'soldiers') {
            $soldierLosses = $this->calculateAirTarget(
                enemyUnits: $defender->soldiers,
                softCap: $defender->soldiers * 0.75 + 1000,
                baseFactor: 35,
                attackerAircraft: $attackerAircraft,
                defenderAircraft: $defenderAircraft,
                rng: $rng,
            );
        }

        if ($target === 'tanks') {
            $tankLosses = $this->calculateAirTarget(
                enemyUnits: $defender->tanks,
                softCap: $defender->tanks * 0.75 + 10,
                baseFactor: 1.25,
                attackerAircraft: $attackerAircraft,
                defenderAircraft: $defenderAircraft,
                rng: $rng,
            );
        }

        if ($target === 'ships') {
            $shipLosses = $this->calculateAirTarget(
                enemyUnits: $defender->ships,
                softCap: $defender->ships * 0.5 + 4,
                baseFactor: 0.0285,
                attackerAircraft: $attackerAircraft,
                defenderAircraft: $defenderAircraft,
                rng: $rng,
            );
        }

        $soldierLosses = round($soldierLosses * $multiplier * $defenderCasualtyFactor);
        $tankLosses = round($tankLosses * $multiplier * $defenderCasualtyFactor);
        $shipLosses = round($shipLosses * $multiplier * $defenderCasualtyFactor);

        return [
            (float) min($soldierLosses, $defender->soldiers),
            (float) min($tankLosses, $defender->tanks),
            (float) min($shipLosses, $defender->ships),
        ];
    }

    private function calculateAirTarget(
        int $enemyUnits,
        float $softCap,
        float $baseFactor,
        int $attackerAircraft,
        int $defenderAircraft,
        WarSimRng $rng,
    ): float {
        if ($enemyUnits <= 0) {
            return 0.0;
        }

        $base = ($attackerAircraft - ($defenderAircraft * 0.5))
            * $baseFactor
            * $rng->nextFloat(0.85, 1.05);

        $raw = min($enemyUnits, $softCap, $base);

        return round(max($raw, 0.0));
    }
}
