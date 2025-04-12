<?php

namespace App\Services;

use App\GraphQL\Models\Nation;
use App\Models\NationSignIn;

class SignInService
{
    /**
     * @param Nation $nation
     * @return void
     */
    public function snapshotNation(Nation $nation): void
    {
        $accounts = AccountService::getAccountsByNid($nation->id);

        $resourceFields = PWHelperService::resources();

        $combined = [];

        foreach ($resourceFields as $field) {
            $nationValue = $nation->{$field} ?? 0;
            $accountValue = $accounts->sum($field);
            $combined[$field] = $nationValue + $accountValue;
        }

        NationSignIn::create([
            'nation_id' => $nation->id,
            'num_cities' => $nation->num_cities,
            'score' => $nation->score,
            'wars_won' => $nation->wars_won,
            'wars_lost' => $nation->wars_lost,
            'total_infrastructure_destroyed' => $nation->total_infrastructure_destroyed,
            'total_infrastructure_lost' => $nation->total_infrastructure_lost,

            // Military - current
            'soldiers' => $nation->soldiers,
            'tanks' => $nation->tanks,
            'aircraft' => $nation->aircraft,
            'ships' => $nation->ships,
            'missiles' => $nation->missiles,
            'nukes' => $nation->nukes,
            'spies' => $nation->spies,

            // Military - stats
            'soldier_kills' => $nation->soldier_kills,
            'soldier_casualties' => $nation->soldier_casualties,
            'tank_kills' => $nation->tank_kills,
            'tank_casualties' => $nation->tank_casualties,
            'aircraft_kills' => $nation->aircraft_kills,
            'aircraft_casualties' => $nation->aircraft_casualties,
            'ship_kills' => $nation->ship_kills,
            'ship_casualties' => $nation->ship_casualties,
            'missile_kills' => $nation->missile_kills,
            'missile_casualties' => $nation->missile_casualties,
            'nuke_kills' => $nation->nuke_kills,
            'nuke_casualties' => $nation->nuke_casualties,
            'spy_kills' => $nation->spy_kills,
            'spy_casualties' => $nation->spy_casualties,

            // Combined Nation + Account
            'money' => $combined['money'],
            'coal' => $combined['coal'],
            'oil' => $combined['oil'],
            'uranium' => $combined['uranium'],
            'iron' => $combined['iron'],
            'bauxite' => $combined['bauxite'],
            'lead' => $combined['lead'],
            'gasoline' => $combined['gasoline'],
            'munitions' => $combined['munitions'],
            'steel' => $combined['steel'],
            'aluminum' => $combined['aluminum'],
            'food' => $combined['food'],
            'credits' => $nation->credits, // stays as-is
        ]);
    }
}