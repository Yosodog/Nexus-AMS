<?php

namespace App\Services;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Services\Economy\EconomyRules;
use Illuminate\Support\Str;

class CityBuildAuditService
{
    /** @var array<string, string> */
    private const RECOMMENDATION_KEYS = [
        'coal_power' => 'imp_coalpower',
        'oil_power' => 'imp_oilpower',
        'wind_power' => 'imp_windpower',
        'nuclear_power' => 'imp_nuclearpower',
        'coal_mine' => 'imp_coalmine',
        'oil_well' => 'imp_oilwell',
        'uranium_mine' => 'imp_uramine',
        'lead_mine' => 'imp_leadmine',
        'iron_mine' => 'imp_ironmine',
        'bauxite_mine' => 'imp_bauxitemine',
        'farm' => 'imp_farm',
        'oil_refinery' => 'imp_gasrefinery',
        'aluminum_refinery' => 'imp_aluminumrefinery',
        'munitions_factory' => 'imp_munitionsfactory',
        'steel_mill' => 'imp_steelmill',
        'police_station' => 'imp_policestation',
        'hospital' => 'imp_hospital',
        'recycling_center' => 'imp_recyclingcenter',
        'subway' => 'imp_subway',
        'supermarket' => 'imp_supermarket',
        'bank' => 'imp_bank',
        'shopping_mall' => 'imp_mall',
        'stadium' => 'imp_stadium',
        'barracks' => 'imp_barracks',
        'factory' => 'imp_factory',
        'hangar' => 'imp_hangars',
        'drydock' => 'imp_drydock',
    ];

    /**
     * @return array{
     *     status: string,
     *     recommendation_status: string,
     *     city_count: int,
     *     matching_city_count: int,
     *     cities_needing_changes: int,
     *     total_changes: int,
     *     has_different_city_builds: bool,
     *     different_city_build_count: int,
     *     expected_build: array<string, int>,
     *     recommendation_json: string|null,
     *     first_city: array<string, mixed>|null
     * }
     */
    public function auditNation(Nation $nation): array
    {
        $nation->loadMissing(['cities', 'buildRecommendation']);

        $cities = $nation->cities->sortBy('id')->values();
        $firstCity = $cities->first();
        $differentCityBuildCount = $firstCity === null
            ? 0
            : $cities->skip(1)->filter(
                fn (City $city): bool => ! $this->citiesShareBuild($firstCity, $city),
            )->count();
        $recommendation = $nation->buildRecommendation;
        $recommendationStatus = match (true) {
            $recommendation === null => 'missing',
            $recommendation->model_version !== EconomyRules::MODEL_VERSION => 'outdated',
            default => 'ready',
        };

        if ($recommendationStatus !== 'ready') {
            return [
                'status' => $recommendationStatus,
                'recommendation_status' => $recommendationStatus,
                'city_count' => $nation->cities->count(),
                'matching_city_count' => 0,
                'cities_needing_changes' => 0,
                'total_changes' => 0,
                'has_different_city_builds' => $differentCityBuildCount > 0,
                'different_city_build_count' => $differentCityBuildCount,
                'expected_build' => [],
                'recommendation_json' => null,
                'first_city' => null,
            ];
        }

        $expectedBuild = $this->expectedBuild($recommendation);
        $cityAudits = $cities
            ->map(fn (City $city): array => $this->auditCity($city, $recommendation, $expectedBuild))
            ->values();
        $matchingCityCount = $cityAudits->where('matches', true)->count();
        $cityCount = $cityAudits->count();

        return [
            'status' => $cityCount > 0 && $matchingCityCount === $cityCount ? 'compliant' : 'needs_changes',
            'recommendation_status' => $recommendationStatus,
            'city_count' => $cityCount,
            'matching_city_count' => $matchingCityCount,
            'cities_needing_changes' => $cityAudits->where('matches', false)->count(),
            'total_changes' => $cityAudits->sum('change_count'),
            'has_different_city_builds' => $differentCityBuildCount > 0,
            'different_city_build_count' => $differentCityBuildCount,
            'expected_build' => $expectedBuild,
            'recommendation_json' => json_encode(
                $recommendation->recommended_build_json,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) ?: null,
            'first_city' => $cityAudits->first(),
        ];
    }

    private function citiesShareBuild(City $firstCity, City $city): bool
    {
        foreach (EconomyRules::BUILD_FIELDS as $field) {
            if ((int) ($firstCity->{$field} ?? 0) !== (int) ($city->{$field} ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> */
    private function expectedBuild(NationBuildRecommendation $recommendation): array
    {
        $build = $recommendation->recommended_build_json ?? [];

        return collect(EconomyRules::BUILD_FIELDS)
            ->mapWithKeys(fn (string $field): array => [
                $field => (int) ($build[self::RECOMMENDATION_KEYS[$field]] ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, int>  $expectedBuild
     * @return array<string, mixed>
     */
    private function auditCity(
        City $city,
        NationBuildRecommendation $recommendation,
        array $expectedBuild,
    ): array {
        $differences = collect($expectedBuild)
            ->map(function (int $recommended, string $field) use ($city): ?array {
                $actual = (int) ($city->{$field} ?? 0);

                if ($actual === $recommended) {
                    return null;
                }

                return [
                    'field' => $field,
                    'label' => Str::headline($field),
                    'actual' => $actual,
                    'recommended' => $recommended,
                    'delta' => $recommended - $actual,
                ];
            })
            ->filter()
            ->values();
        $infrastructureShortfall = max(0.0, (float) $recommendation->infra_needed - (float) $city->infrastructure);
        $landShortfall = max(0.0, (float) $recommendation->land_used - (float) $city->land);
        $isPowered = (bool) $city->powered;
        $matches = $differences->isEmpty()
            && $infrastructureShortfall < 0.01
            && $landShortfall < 0.01
            && $isPowered;

        return [
            'id' => (int) $city->id,
            'name' => $city->name,
            'infrastructure' => (float) $city->infrastructure,
            'land' => (float) $city->land,
            'powered' => $isPowered,
            'matches' => $matches,
            'infrastructure_shortfall' => $infrastructureShortfall,
            'land_shortfall' => $landShortfall,
            'differences' => $differences->all(),
            'change_count' => $differences->count()
                + ($infrastructureShortfall >= 0.01 ? 1 : 0)
                + ($landShortfall >= 0.01 ? 1 : 0)
                + ($isPowered ? 0 : 1),
        ];
    }
}
