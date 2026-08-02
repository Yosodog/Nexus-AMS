<?php

namespace App\Services\Audit;

use App\Models\City;
use App\Services\PWHelperService;

final class CityAuditMapper
{
    private const IMPROVEMENT_COLUMNS = [
        'oil_power', 'wind_power', 'coal_power', 'nuclear_power', 'coal_mine', 'oil_well', 'uranium_mine',
        'farm', 'barracks', 'police_station', 'hospital', 'recycling_center', 'subway', 'supermarket',
        'bank', 'shopping_mall', 'stadium', 'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery',
        'aluminum_refinery', 'steel_mill', 'munitions_factory', 'factory', 'hangar', 'drydock',
    ];

    /**
     * Build the flat, typed context used by the guided audit evaluator.
     *
     * @return array<string, mixed>
     */
    public function buildContext(City $city): array
    {
        $nation = $city->nation;
        $improvementValues = collect(self::IMPROVEMENT_COLUMNS)->mapWithKeys(
            fn (string $column): array => [$column => $city->{$column}],
        );
        $hasCompleteImprovementData = $improvementValues->every(
            static fn (mixed $value): bool => $value !== null && is_numeric($value),
        );
        $improvementCount = $hasCompleteImprovementData ? (int) $improvementValues->sum() : null;
        $capacity = $city->infrastructure !== null ? (int) floor((float) $city->infrastructure / 50) : null;

        $context = [
            'nation.id' => $nation?->id,
            'nation.nation_name' => $nation?->nation_name,
            'nation.leader_name' => $nation?->leader_name,
            'nation.score' => $nation?->score,
            'nation.num_cities' => $nation?->num_cities,
            'nation.color' => $nation?->color,
            'nation.projects' => $nation?->project_bits === null
                ? null
                : PWHelperService::getNationProjects($nation->project_bits),
            'city.id' => $city->id,
            'city.name' => $city->name,
            'city.infrastructure' => $city->infrastructure,
            'city.land' => $city->land,
            'city.powered' => $city->getRawOriginal('powered') === null ? null : (bool) $city->powered,
            'city.improvement_count' => $improvementCount,
            'city.improvement_capacity' => $capacity,
            'city.improvement_capacity_exceeded' => $improvementCount !== null && $capacity !== null
                ? $improvementCount > $capacity
                : null,
            'city.infrastructure_aligned' => $city->infrastructure !== null ? $city->isInfrastructureAligned() : null,
            'city.land_aligned' => $city->land !== null ? $city->isLandAligned() : null,
            'city.infrastructure_and_land_aligned' => $city->infrastructure !== null && $city->land !== null
                ? $city->isInfrastructureAligned() && $city->isLandAligned()
                : null,
            'city.land_at_least_infrastructure' => $city->infrastructure !== null && $city->land !== null
                ? (float) $city->land >= (float) $city->infrastructure
                : null,
        ];

        foreach (self::IMPROVEMENT_COLUMNS as $column) {
            $context["city.{$column}"] = $city->{$column};
        }

        return $context;
    }
}
