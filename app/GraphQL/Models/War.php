<?php

namespace App\GraphQL\Models;

class War
{
    public int $id;
    public string $reason;
    public string $war_type;
    public int $ground_control;
    public int $air_superiority;
    public int $naval_blockade;
    public int $winner_id;
    public int $turns_left;

    public int $att_id;
    public int $att_alliance_id;
    public string $att_alliance_position;

    public int $def_id;
    public int $def_alliance_id;
    public string $def_alliance_position;

    public int $att_points;
    public int $def_points;

    public bool $att_peace;
    public bool $def_peace;

    public int $att_resistance;
    public int $def_resistance;

    public bool $att_fortify;
    public bool $def_fortify;

    public float $att_gas_used;
    public float $def_gas_used;
    public float $att_mun_used;
    public float $def_mun_used;
    public float $att_alum_used;
    public float $def_alum_used;
    public float $att_steel_used;
    public float $def_steel_used;

    public float $att_infra_destroyed;
    public float $def_infra_destroyed;

    public float $att_money_looted;
    public float $def_money_looted;

    public int $def_soldiers_lost;
    public int $att_soldiers_lost;
    public int $def_tanks_lost;
    public int $att_tanks_lost;
    public int $def_aircraft_lost;
    public int $att_aircraft_lost;
    public int $def_ships_lost;
    public int $att_ships_lost;

    public int $att_missiles_used;
    public int $def_missiles_used;
    public int $att_nukes_used;
    public int $def_nukes_used;

    public float $att_infra_destroyed_value;
    public float $def_infra_destroyed_value;

    public string $date;      // ISO 8601 format string
    public ?string $end_date; // Nullable ISO 8601 format string

    /**
     * @param \stdClass $json
     * @return void
     */
    public function buildWithJSON(\stdClass $json): void
    {
        foreach ($json as $key => $value) {
            $this->{$key} = $value;
        }
    }
}