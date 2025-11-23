<?php

namespace App\Services\Audit;

use App\Models\City;

class CityAuditMapper
{
    /**
     * Build the nation.* and city.* variable maps for city-level rules.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildVariables(City $city): array
    {
        $nation = $city->nation;

        return [
            'nation' => [
                'id' => $nation?->id,
                'nation_name' => $nation?->nation_name,
                'leader_name' => $nation?->leader_name,
                'score' => $nation?->score,
                'num_cities' => $nation?->num_cities,
                'color' => $nation?->color,
            ],
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'infrastructure' => $city->infrastructure,
                'land' => $city->land,
                'powered' => (bool) $city->powered,
                'oil_power' => $city->oil_power,
                'wind_power' => $city->wind_power,
                'coal_power' => $city->coal_power,
                'nuclear_power' => $city->nuclear_power,
                'coal_mine' => $city->coal_mine,
                'oil_well' => $city->oil_well,
                'uranium_mine' => $city->uranium_mine,
                'farm' => $city->farm,
                'barracks' => $city->barracks,
                'police_station' => $city->police_station,
                'hospital' => $city->hospital,
                'recycling_center' => $city->recycling_center,
                'subway' => $city->subway,
                'supermarket' => $city->supermarket,
                'bank' => $city->bank,
                'shopping_mall' => $city->shopping_mall,
                'stadium' => $city->stadium,
                'lead_mine' => $city->lead_mine,
                'iron_mine' => $city->iron_mine,
                'bauxite_mine' => $city->bauxite_mine,
                'oil_refinery' => $city->oil_refinery,
                'aluminum_refinery' => $city->aluminum_refinery,
                'steel_mill' => $city->steel_mill,
                'munitions_factory' => $city->munitions_factory,
                'factory' => $city->factory,
                'hangar' => $city->hangar,
                'drydock' => $city->drydock,
            ],
        ];
    }
}
