<?php

namespace App\Services;

use App\GraphQL\Models\Nation;
use App\Models\NationSignIns;

class SignInService
{
    /**
     * @param Nation $nation
     * @return void
     */
    public function snapshotNation(Nation $nation): void
    {
        NationSignIns::create([
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

            // Resources
            'money' => $nation->money,
            'coal' => $nation->coal,
            'oil' => $nation->oil,
            'uranium' => $nation->uranium,
            'iron' => $nation->iron,
            'bauxite' => $nation->bauxite,
            'lead' => $nation->lead,
            'gasoline' => $nation->gasoline,
            'munitions' => $nation->munitions,
            'steel' => $nation->steel,
            'aluminum' => $nation->aluminum,
            'food' => $nation->food,
            'credits' => $nation->credits,
        ]);
    }
}