<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;

class AuditVariableRegistry
{
    /**
     * @return array<int, string>
     */
    public function allowedFor(AuditTargetType $targetType): array
    {
        return match ($targetType) {
            AuditTargetType::Nation => $this->nationPaths(),
            AuditTargetType::City => $this->cityPaths(),
        };
    }

    /**
     * @return array<int, string>
     */
    private function nationPaths(): array
    {
        return [
            'nation.id',
            'nation.alliance_id',
            'nation.alliance_position',
            'nation.nation_name',
            'nation.leader_name',
            'nation.continent',
            'nation.war_policy',
            'nation.domestic_policy',
            'nation.color',
            'nation.num_cities',
            'nation.score',
            'nation.population',
            'nation.projects_count',
            'nation.project_bits',
            'nation.wars_won',
            'nation.wars_lost',
            'nation.offensive_wars_count',
            'nation.defensive_wars_count',
            'nation.gni',
            'nation.gdp',
            'nation.commendations',
            'nation.denouncements',
            'nation.money',
            'nation.coal',
            'nation.oil',
            'nation.uranium',
            'nation.iron',
            'nation.bauxite',
            'nation.lead',
            'nation.gasoline',
            'nation.munitions',
            'nation.steel',
            'nation.aluminum',
            'nation.food',
            'nation.credits',
            'nation.soldiers',
            'nation.tanks',
            'nation.aircraft',
            'nation.ships',
            'nation.missiles',
            'nation.nukes',
            'nation.spies',
            'nation.account_credits',
            'nation.last_active',
            'nation.account_discord_id',
            'nation.mmr_score',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cityPaths(): array
    {
        return [
            // nation context
            'nation.id',
            'nation.nation_name',
            'nation.leader_name',
            'nation.score',
            'nation.num_cities',
            'nation.color',
            // city context
            'city.id',
            'city.name',
            'city.infrastructure',
            'city.land',
            'city.powered',
            'city.oil_power',
            'city.wind_power',
            'city.coal_power',
            'city.nuclear_power',
            'city.coal_mine',
            'city.oil_well',
            'city.uranium_mine',
            'city.farm',
            'city.barracks',
            'city.police_station',
            'city.hospital',
            'city.recycling_center',
            'city.subway',
            'city.supermarket',
            'city.bank',
            'city.shopping_mall',
            'city.stadium',
            'city.lead_mine',
            'city.iron_mine',
            'city.bauxite_mine',
            'city.oil_refinery',
            'city.aluminum_refinery',
            'city.steel_mill',
            'city.munitions_factory',
            'city.factory',
            'city.hangar',
            'city.drydock',
        ];
    }
}
