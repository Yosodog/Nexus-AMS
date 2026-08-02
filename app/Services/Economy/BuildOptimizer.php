<?php

namespace App\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;

final class BuildOptimizer
{
    private const MAXIMUM_TARGET_INFRASTRUCTURE = 4000;

    private const RESOURCE_GROUPS = [
        ['fields' => ['coal_mine', 'iron_mine', 'steel_mill'], 'preserve_after' => ['oil', 'uranium']],
        ['fields' => ['oil_well', 'oil_refinery'], 'preserve_after' => ['uranium']],
        ['fields' => ['uranium_mine'], 'preserve_after' => []],
        ['fields' => ['lead_mine', 'munitions_factory'], 'preserve_after' => []],
        ['fields' => ['bauxite_mine', 'aluminum_refinery'], 'preserve_after' => []],
        ['fields' => ['farm'], 'preserve_after' => []],
    ];

    public function __construct(private readonly EconomyCalculator $calculator) {}

    /**
     * @param  array<string, int>  $minimumBuild
     * @return array<string, mixed>|null
     */
    public function optimize(
        Nation $nation,
        EloquentCollection $cities,
        array $minimumBuild,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): ?array {
        if ($cities->isEmpty()) {
            return null;
        }

        $target = $this->targetProfile($cities);

        if ($target['target_infrastructure'] > self::MAXIMUM_TARGET_INFRASTRUCTURE) {
            throw new InvalidArgumentException('The recovered city target exceeds the supported infrastructure limit.');
        }

        $availableSlots = $target['available_slots'];
        $minimumBuild = $this->normalizeBuild($minimumBuild);
        $minimumSlots = $this->buildingCount($minimumBuild);

        if ($minimumSlots > $availableSlots) {
            return null;
        }

        $profiles = $cities->map(fn (City $city): City => $this->makeCity(
            $city,
            $minimumBuild,
            $target['target_infrastructure']
        ));
        $averageFoodConsumption = $profiles->avg(
            fn (City $city): float => $this->calculator->dailyFoodConsumption($city)
        );
        $resourceOptions = $this->resourceGroupOptions(
            $nation,
            $profiles,
            $radiationSnapshot,
            $availableSlots - $minimumSlots
        );
        $hasProject = $this->projectChecker($nation);
        $maximumCommerce = $hasProject('telecommunications_satellite')
            ? 125
            : ($hasProject('international_trade_center') ? 115 : 100);
        $best = null;
        $states = [];

        foreach ($this->powerSignatures($target['target_infrastructure'], $availableSlots - $minimumSlots) as $powerBuild) {
            $powerSlots = $this->buildingCount($powerBuild);
            $powerCity = $this->makeCity($profiles->first(), $powerBuild, $target['target_infrastructure']);
            $powerVector = $this->calculator->powerOperatingVector(
                $nation,
                $powerCity,
                $target['target_infrastructure']
            );
            $powerVector['food'] -= $averageFoodConsumption;
            $powerPollution = collect(EconomyRules::POWER_FIELDS)->sum(
                fn (string $field): int => EconomyRules::pollutionContribution(
                    $field,
                    (int) $powerBuild[$field],
                    $hasProject
                )
            );
            $states[] = [
                'build' => array_replace($minimumBuild, $powerBuild),
                'slots' => $minimumSlots + $powerSlots,
                'pollution' => $powerPollution,
                'commerce' => 0,
                'hospital' => 0,
                'police' => 0,
                'vector' => $powerVector,
                'value' => $prices->convert($powerVector),
            ];
        }

        foreach ($resourceOptions as $groupIndex => $groupOptions) {
            $preservedResourceKeys = self::RESOURCE_GROUPS[$groupIndex]['preserve_after'];
            $states = $this->combineOptions(
                $states,
                $groupOptions,
                $availableSlots,
                $prices,
                includeSupportSignature: false,
                resourceSignatureKeys: $preservedResourceKeys,
            );

            if ($states === []) {
                return null;
            }

            $states = $this->pruneResourceStates($states, $preservedResourceKeys);
        }

        $states = $this->pruneEconomicStates($states);

        $supportStates = [[
            'build' => [],
            'slots' => 0,
            'pollution' => 0,
            'commerce' => 0,
            'hospital' => 0,
            'police' => 0,
            'vector' => EconomyRules::emptyResourceBuffer(),
            'value' => 0.0,
        ]];

        foreach ([
            'recycling_center',
            'subway',
            'supermarket',
            'bank',
            'shopping_mall',
            'stadium',
        ] as $field) {
            $supportStates = $this->combineOptions(
                $supportStates,
                $this->supportOptions($nation, $field, $availableSlots),
                $availableSlots,
                $prices,
                includeSupportSignature: true,
                maximumCommerce: $maximumCommerce,
            );
            $supportStates = $this->pruneSupportStates($supportStates);
        }

        foreach ($states as $index => $state) {
            $states[$index]['compact_key'] = $this->compactBuildKey($state['build']);
        }

        foreach ($supportStates as $index => $state) {
            $supportStates[$index]['compact_key'] = $this->compactBuildKey($state['build']);
        }

        usort($states, fn (array $left, array $right): int => [
            -$left['value'],
            -$left['vector']['money'],
            $left['compact_key'],
        ] <=> [
            -$right['value'],
            -$right['vector']['money'],
            $right['compact_key'],
        ]);
        usort($supportStates, fn (array $left, array $right): int => [
            -$left['value'],
            -$left['vector']['money'],
            $left['compact_key'],
        ] <=> [
            -$right['value'],
            -$right['vector']['money'],
            $right['compact_key'],
        ]);

        $hospitalCap = EconomyRules::improvementCap('hospital', $hasProject);
        $policeCap = EconomyRules::improvementCap('police_station', $hasProject);
        $populationContext = $this->calculator->populationScoringContext($nation, $profiles);
        $maximumCivilContributions = collect($supportStates)
            ->map(fn (array $state): float => $this->maximumCivilContribution(
                $populationContext,
                (int) $state['commerce']
            ))
            ->all();
        $civilBuildKeys = $this->civilBuildKeys($hospitalCap, $policeCap);
        $civilCache = [];
        $civilPopulationCache = [
            'disease_adjusted' => [],
            'crime_deaths' => [],
        ];
        $bestChoice = null;

        foreach ($states as $resourceIndex => $resourceState) {
            foreach ($supportStates as $supportIndex => $supportState) {
                $baseSlots = $resourceState['slots'] + $supportState['slots'];

                if ($baseSlots > $availableSlots) {
                    continue;
                }

                if (
                    $bestChoice !== null
                    && $resourceState['value'] + $supportState['value'] + $maximumCivilContributions[$supportIndex]
                        < $bestChoice['converted'] - 0.000001
                ) {
                    continue;
                }

                $basePollution = $resourceState['pollution'] + $supportState['pollution'];
                $baseCommerce = $supportState['commerce'];
                $remainingSlots = min(
                    $hospitalCap + $policeCap,
                    $availableSlots - $baseSlots
                );
                $cacheKey = implode(':', [$basePollution, $baseCommerce]);
                $civilCache[$cacheKey] ??= $this->civilOptions(
                    $populationContext,
                    $civilBuildKeys,
                    $basePollution,
                    $baseCommerce,
                    $hospitalCap,
                    $policeCap,
                    $civilPopulationCache
                );
                $civil = $this->unpackCivilOption($civilCache[$cacheKey], $remainingSlots);
                $baseBuildKey = $resourceState['compact_key'] | $supportState['compact_key'];
                $candidateChoice = [
                    'converted' => $resourceState['value'] + $supportState['value'] + $civil['contribution'],
                    'money' => $resourceState['vector']['money'] + $supportState['vector']['money'] + $civil['contribution'],
                    'used_slots' => $baseSlots + $civil['slots'],
                    'pollution' => $civil['pollution'],
                    'build_key' => $baseBuildKey | $civil['build_key'],
                    'resource_index' => $resourceIndex,
                    'support_index' => $supportIndex,
                    'hospitals' => $civil['hospitals'],
                    'police_stations' => $civil['police_stations'],
                ];

                if ($this->isBetterChoice($candidateChoice, $bestChoice)) {
                    $bestChoice = $candidateChoice;
                }
            }
        }

        if ($bestChoice !== null) {
            $resourceState = $states[$bestChoice['resource_index']];
            $supportState = $supportStates[$bestChoice['support_index']];
            $build = array_replace($resourceState['build'], $supportState['build']);
            $build['hospital'] = $bestChoice['hospitals'];
            $build['police_station'] = $bestChoice['police_stations'];
            $best = [
                'build' => $build,
                'metrics' => $this->averageMetrics(
                    $nation,
                    $cities,
                    $build,
                    $target['target_infrastructure'],
                    $radiationSnapshot,
                    $prices
                ),
                'used_slots' => $bestChoice['used_slots'],
                'available_slots' => $availableSlots,
            ];
        }

        if ($best === null) {
            return null;
        }

        return $best + $target;
    }

    /**
     * @return array{target_infrastructure: int, available_slots: int, cities_below_target: int, infrastructure_shortfall: float, land_used: float}
     */
    public function targetProfile(EloquentCollection $cities): array
    {
        $recoveredInfrastructure = $cities->map(function (City $city): float {
            $improvements = collect(EconomyRules::BUILD_FIELDS)->sum(
                fn (string $field): int => max(0, (int) ($city->{$field} ?? 0))
            );

            return max((float) $city->infrastructure, $improvements * 50.0);
        });
        $targetInfrastructure = max(0, (int) floor($recoveredInfrastructure->max() / 50) * 50);
        $lands = $cities->pluck('land')->map(fn (mixed $land): float => (float) $land)->sort()->values();
        $middle = (int) floor(($lands->count() - 1) / 2);

        return [
            'target_infrastructure' => $targetInfrastructure,
            'available_slots' => (int) floor($targetInfrastructure / 50),
            'cities_below_target' => $cities->filter(
                fn (City $city): bool => (float) $city->infrastructure < $targetInfrastructure
            )->count(),
            'infrastructure_shortfall' => round($cities->sum(
                fn (City $city): float => max(0.0, $targetInfrastructure - (float) $city->infrastructure)
            ), 2),
            'land_used' => max(0.0, (float) ($lands->get($middle) ?? 0.0)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceGroupOptions(
        Nation $nation,
        EloquentCollection $profiles,
        ?RadiationSnapshot $radiationSnapshot,
        int $slotLimit
    ): array {
        $groups = [];
        $representativeCity = new City($profiles->first()->getAttributes());
        $representativeCity->land = (float) $profiles->avg(
            fn (City $city): float => (float) $city->land
        );

        foreach (self::RESOURCE_GROUPS as $group) {
            $fieldOptions = [];

            foreach ($group['fields'] as $field) {
                $fieldOptions[] = $this->resourceFieldOptions(
                    $nation,
                    $representativeCity,
                    $field,
                    $radiationSnapshot,
                    $slotLimit
                );
            }

            $groups[] = $this->cartesianOptions($fieldOptions, $slotLimit);
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceFieldOptions(
        Nation $nation,
        City $representativeCity,
        string $field,
        ?RadiationSnapshot $radiationSnapshot,
        int $slotLimit
    ): array {
        $hasProject = $this->projectChecker($nation);
        $cap = EconomyRules::isFieldAllowed($field, $nation->continent)
            ? min(EconomyRules::improvementCap($field, $hasProject), $slotLimit)
            : 0;
        $options = [];

        for ($count = 0; $count <= $cap; $count++) {
            $vector = $this->calculator->improvementOperatingVector(
                $nation,
                $representativeCity,
                $field,
                $count,
                $radiationSnapshot
            );

            $options[] = [
                'build' => [$field => $count],
                'slots' => $count,
                'pollution' => EconomyRules::pollutionContribution($field, $count, $hasProject),
                'commerce' => 0,
                'hospital' => 0,
                'police' => 0,
                'vector' => $vector,
            ];
        }

        return $options;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $optionSets
     * @return list<array<string, mixed>>
     */
    private function cartesianOptions(array $optionSets, int $slotLimit): array
    {
        $states = [[
            'build' => [],
            'slots' => 0,
            'pollution' => 0,
            'commerce' => 0,
            'hospital' => 0,
            'police' => 0,
            'vector' => EconomyRules::emptyResourceBuffer(),
        ]];

        foreach ($optionSets as $options) {
            $next = [];

            foreach ($states as $state) {
                foreach ($options as $option) {
                    if ($slotLimit < $state['slots'] + $option['slots']) {
                        continue;
                    }

                    $next[] = $this->mergeState($state, $option);
                }
            }

            $states = $next;
        }

        return $states;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function supportOptions(Nation $nation, string $field, int $slotLimit): array
    {
        $hasProject = $this->projectChecker($nation);
        $cap = min(EconomyRules::improvementCap($field, $hasProject), $slotLimit);
        $options = [];

        for ($count = 0; $count <= $cap; $count++) {
            $vector = EconomyRules::emptyResourceBuffer();
            $vector['money'] = -(EconomyRules::buildingMoneyUpkeep($field, $hasProject) * $count);
            $options[] = [
                'build' => [$field => $count],
                'slots' => $count,
                'pollution' => EconomyRules::pollutionContribution($field, $count, $hasProject),
                'commerce' => EconomyRules::commerceContribution($field, $count),
                'hospital' => $field === 'hospital' ? $count : 0,
                'police' => $field === 'police_station' ? $count : 0,
                'vector' => $vector,
            ];
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function combineOptions(
        array $states,
        array $options,
        int $slotLimit,
        MarketPriceSet $prices,
        bool $includeSupportSignature,
        array $resourceSignatureKeys = [],
        ?int $maximumCommerce = null,
    ): array {
        $bestBySignature = [];

        foreach ($states as $state) {
            foreach ($options as $option) {
                if ($slotLimit < $state['slots'] + $option['slots']) {
                    continue;
                }

                $candidate = $this->mergeState($state, $option);
                if ($maximumCommerce !== null) {
                    $candidate['commerce'] = min($maximumCommerce, $candidate['commerce']);
                }
                $candidate['value'] = $prices->convert($candidate['vector']);
                $signature = $includeSupportSignature
                    ? implode(':', [
                        $candidate['slots'],
                        $candidate['pollution'],
                        $candidate['commerce'],
                        $candidate['hospital'],
                        $candidate['police'],
                    ])
                    : implode(':', [$candidate['slots'], $candidate['pollution']]);

                foreach ($resourceSignatureKeys as $resource) {
                    $signature .= sprintf(':%.6F', $candidate['vector'][$resource]);
                }

                $current = $bestBySignature[$signature] ?? null;

                if ($current === null || $this->isDirectlyBetter($candidate, $current)) {
                    $bestBySignature[$signature] = $candidate;
                }

                if ($includeSupportSignature && count($bestBySignature) >= 20_000) {
                    $bestBySignature = $this->indexSupportStates(
                        $this->pruneSupportStates(array_values($bestBySignature))
                    );
                }

            }
        }

        return array_values($bestBySignature);
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @return list<array<string, mixed>>
     */
    private function pruneEconomicStates(array $states): array
    {
        $bestByPoint = [];

        foreach ($states as $state) {
            $slots = (int) $state['slots'];
            $pollution = (int) $state['pollution'];
            $current = $bestByPoint[$slots][$pollution] ?? null;

            if ($current === null || $this->isDirectlyBetter($state, $current)) {
                $bestByPoint[$slots][$pollution] = $state;
            }
        }

        unset($states);
        ksort($bestByPoint, SORT_NUMERIC);
        $pollutionLookup = [];

        foreach ($bestByPoint as $points) {
            foreach (array_keys($points) as $pollution) {
                $pollutionLookup[$pollution] = true;
            }
        }

        $pollutionValues = array_keys($pollutionLookup);
        sort($pollutionValues, SORT_NUMERIC);
        $pollutionIndexes = array_flip($pollutionValues);
        $tree = [];
        $frontier = [];

        foreach ($bestByPoint as $points) {
            ksort($points, SORT_NUMERIC);

            foreach ($points as $pollution => $candidate) {
                $pollutionIndex = $pollutionIndexes[$pollution] + 1;

                if ($this->fenwickMaximum($tree, $pollutionIndex) >= $candidate['value'] - 0.000001) {
                    continue;
                }

                $frontier[] = $candidate;
                $this->fenwickUpdate(
                    $tree,
                    $pollutionIndex,
                    count($pollutionValues),
                    $candidate['value']
                );
            }
        }

        return $frontier;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<string>  $resourceKeys
     * @return list<array<string, mixed>>
     */
    private function pruneResourceStates(array $states, array $resourceKeys): array
    {
        if ($resourceKeys === []) {
            return $this->pruneEconomicStates($states);
        }

        $groups = [];

        foreach ($states as $state) {
            $parts = [];

            foreach ($resourceKeys as $resource) {
                $parts[] = sprintf('%.6F', $state['vector'][$resource]);
            }

            $key = implode(':', $parts);
            $groups[$key][] = $state;
        }

        $frontier = [];

        foreach ($groups as $group) {
            array_push($frontier, ...$this->pruneEconomicStates($group));
        }

        return $frontier;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @return list<array<string, mixed>>
     */
    private function pruneSupportStates(array $states): array
    {
        usort($states, function (array $left, array $right): int {
            return [
                $left['slots'],
                -$left['commerce'],
                $left['pollution'],
                -$left['value'],
                $this->buildKey($left['build']),
            ] <=> [
                $right['slots'],
                -$right['commerce'],
                $right['pollution'],
                -$right['value'],
                $this->buildKey($right['build']),
            ];
        });
        $pollutionValues = collect($states)
            ->pluck('pollution')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pollutionIndexes = array_flip($pollutionValues);
        $maximumCommerce = (int) max(0, collect($states)->max('commerce'));
        $trees = array_fill(0, $maximumCommerce + 1, []);
        $frontier = [];

        foreach ($states as $candidate) {
            $dominated = false;
            $pollutionIndex = $pollutionIndexes[$candidate['pollution']] + 1;

            for ($commerce = $candidate['commerce']; $commerce <= $maximumCommerce; $commerce++) {
                if ($this->fenwickMaximum($trees[$commerce], $pollutionIndex) >= $candidate['value'] - 0.000001) {
                    $dominated = true;

                    break;
                }
            }

            if ($dominated) {
                continue;
            }

            $frontier[] = $candidate;
            $this->fenwickUpdate(
                $trees[$candidate['commerce']],
                $pollutionIndex,
                count($pollutionValues),
                $candidate['value']
            );
        }

        return $frontier;
    }

    /**
     * @param  array<int, float>  $tree
     */
    private function fenwickMaximum(array $tree, int $index): float
    {
        $maximum = -INF;

        while ($index > 0) {
            $maximum = max($maximum, $tree[$index] ?? -INF);
            $index -= $index & -$index;
        }

        return $maximum;
    }

    /**
     * @param  array<int, float>  $tree
     */
    private function fenwickUpdate(array &$tree, int $index, int $size, float $value): void
    {
        while ($index <= $size) {
            $tree[$index] = max($tree[$index] ?? -INF, $value);
            $index += $index & -$index;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @return array<string, array<string, mixed>>
     */
    private function indexSupportStates(array $states): array
    {
        $indexed = [];

        foreach ($states as $state) {
            $signature = implode(':', [
                $state['slots'],
                $state['pollution'],
                $state['commerce'],
                $state['hospital'],
                $state['police'],
            ]);
            $indexed[$signature] = $state;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeState(array $left, array $right): array
    {
        $vector = $left['vector'];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $vector[$resource] = (float) ($vector[$resource] ?? 0.0)
                + (float) ($right['vector'][$resource] ?? 0.0);
        }

        return [
            'build' => array_replace($left['build'], $right['build']),
            'slots' => $left['slots'] + $right['slots'],
            'pollution' => $left['pollution'] + $right['pollution'],
            'commerce' => $left['commerce'] + $right['commerce'],
            'hospital' => $left['hospital'] + $right['hospital'],
            'police' => $left['police'] + $right['police'],
            'vector' => $vector,
        ];
    }

    /**
     * @return list<array<string, int>>
     */
    private function powerSignatures(int $targetInfrastructure, int $slotLimit): array
    {
        if ($targetInfrastructure <= 0) {
            return [[
                'coal_power' => 0,
                'oil_power' => 0,
                'wind_power' => 0,
                'nuclear_power' => 0,
            ]];
        }

        $maxNuclear = min($slotLimit, (int) ceil($targetInfrastructure / 2000));
        $maxCoal = min($slotLimit, (int) ceil($targetInfrastructure / 500));
        $maxOil = $maxCoal;
        $maxWind = min($slotLimit, (int) ceil($targetInfrastructure / 250));
        $signatures = [];

        for ($nuclear = 0; $nuclear <= $maxNuclear; $nuclear++) {
            for ($coal = 0; $coal <= $maxCoal; $coal++) {
                for ($oil = 0; $oil <= $maxOil; $oil++) {
                    for ($wind = 0; $wind <= $maxWind; $wind++) {
                        $slots = $nuclear + $coal + $oil + $wind;

                        if ($slots <= 0 || $slots > $slotLimit) {
                            continue;
                        }

                        $capacity = ($nuclear * 2000) + (($coal + $oil) * 500) + ($wind * 250);

                        if ($capacity < $targetInfrastructure) {
                            continue;
                        }

                        if (
                            ($nuclear > 0 && $capacity - 2000 >= $targetInfrastructure)
                            || ($coal > 0 && $capacity - 500 >= $targetInfrastructure)
                            || ($oil > 0 && $capacity - 500 >= $targetInfrastructure)
                            || ($wind > 0 && $capacity - 250 >= $targetInfrastructure)
                        ) {
                            continue;
                        }

                        $signatures[] = [
                            'coal_power' => $coal,
                            'oil_power' => $oil,
                            'wind_power' => $wind,
                            'nuclear_power' => $nuclear,
                        ];
                    }
                }
            }
        }

        return $signatures;
    }

    /**
     * @return array<string, mixed>
     */
    private function averageMetrics(
        Nation $nation,
        EloquentCollection $cities,
        array $build,
        int $targetInfrastructure,
        ?RadiationSnapshot $radiationSnapshot,
        MarketPriceSet $prices
    ): array {
        $totals = [
            'converted_profit_per_day' => 0.0,
            'money_profit_per_day' => 0.0,
            'city_income_per_day' => 0.0,
            'power_cost_per_day' => 0.0,
            'food_cost_per_day' => 0.0,
            'disease' => 0.0,
            'pollution' => 0.0,
            'crime' => 0.0,
            'commerce' => 0.0,
            'population' => 0.0,
            'resource_profit_per_day' => EconomyRules::emptyResourceBuffer(),
        ];

        foreach ($cities as $city) {
            $recommendedCity = $this->makeCity($city, $build, $targetInfrastructure);
            $cityResult = $this->calculator->calculateCity(
                $nation,
                $recommendedCity,
                $radiationSnapshot,
                $prices
            );
            $metrics = $this->calculator->calculateCityMetrics(
                $nation,
                $recommendedCity,
                $radiationSnapshot,
                $prices
            );

            foreach (array_keys($totals) as $key) {
                if ($key === 'resource_profit_per_day') {
                    foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                        $totals[$key][$resource] += (float) ($cityResult[$key][$resource] ?? 0.0);
                    }

                    continue;
                }

                $totals[$key] += (float) ($metrics[$key] ?? 0.0);
            }
        }

        $cityCount = max(1, $cities->count());
        $averageResourceVector = [];

        foreach ($totals as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $resource => $amount) {
                    $averageResourceVector[$resource] = $amount / $cityCount;
                    $totals[$key][$resource] = round($averageResourceVector[$resource], 2);
                }

                continue;
            }

            $totals[$key] = round($value / $cityCount, 2);
        }

        $totals['pollution'] = (int) round($totals['pollution']);
        $totals['commerce'] = (int) round($totals['commerce']);
        $totals['population'] = (int) round($totals['population']);
        $totals['money_profit_per_day'] = round($averageResourceVector['money'] ?? 0.0, 2);
        $totals['converted_profit_per_day'] = round($prices->convert($averageResourceVector), 2);

        return $totals;
    }

    private function maximumCivilContribution(array $context, int $baseCommerce): float
    {
        $population = 0.0;

        foreach ($context['profiles'] as $profile) {
            $population += (int) round(max(
                10,
                $profile['infra_cents'] * $profile['age_bonus']
            ));
        }

        $averagePopulation = $population / max(1, count($context['profiles']));
        $commerce = min(
            $context['maximum_commerce'],
            $baseCommerce + $context['commerce_bonus']
        );
        $commerceIncomeFactor = (($commerce * 0.02) * 0.725) + 0.725;

        return $commerceIncomeFactor * $averagePopulation * $context['income_multiplier'];
    }

    private function civilOptions(
        array $context,
        array $civilBuildKeys,
        int $basePollution,
        int $baseCommerce,
        int $hospitalCap,
        int $policeCap,
        array &$populationCache
    ): string {
        $options = [];
        $commerce = min(
            $context['maximum_commerce'],
            $baseCommerce + $context['commerce_bonus']
        );
        $commerceIncomeFactor = (($commerce * 0.02) * 0.725) + 0.725;
        $profileCount = max(1, count($context['profiles']));

        for ($hospitals = 0; $hospitals <= $hospitalCap; $hospitals++) {
            for ($policeStations = 0; $policeStations <= $policeCap; $policeStations++) {
                $pollution = max(0, $basePollution + ($hospitals * 4) + $policeStations);
                $diseaseKey = $pollution.':'.$hospitals;
                $crimeKey = $commerce.':'.$policeStations;

                if (! isset($populationCache['disease_adjusted'][$diseaseKey])) {
                    $populationCache['disease_adjusted'][$diseaseKey] = [];

                    foreach ($context['profiles'] as $profile) {
                        $disease = max(
                            0.0,
                            $profile['disease_base']
                                - ($hospitals * $context['hospital_modifier'])
                                + ($pollution * 0.05)
                        );
                        $populationCache['disease_adjusted'][$diseaseKey][] = $profile['infra_cents']
                            - (($disease * 0.01) * $profile['infra_cents']);
                    }
                }

                if (! isset($populationCache['crime_deaths'][$crimeKey])) {
                    $populationCache['crime_deaths'][$crimeKey] = [];
                    $commerceCrime = (103 - $commerce) ** 2;

                    foreach ($context['profiles'] as $profile) {
                        $crime = max(
                            0.0,
                            (($commerceCrime + $profile['infra_cents']) * 0.000009)
                                - ($policeStations * $context['police_modifier'])
                        );
                        $populationCache['crime_deaths'][$crimeKey][] = max(
                            ($crime * 0.1) * $profile['infra_cents'] - 25,
                            0
                        );
                    }
                }

                $totalPopulation = 0;

                foreach ($context['profiles'] as $index => $profile) {
                    $totalPopulation += (int) round(max(
                        10,
                        ($populationCache['disease_adjusted'][$diseaseKey][$index]
                            - $populationCache['crime_deaths'][$crimeKey][$index])
                            * $profile['age_bonus']
                    ));
                }

                $income = $commerceIncomeFactor
                    * ($totalPopulation / $profileCount)
                    * $context['income_multiplier'];

                $upkeep = (1000.0 * $hospitals) + (750.0 * $policeStations);
                $options[] = [
                    'contribution' => $income - $upkeep,
                    'slots' => $hospitals + $policeStations,
                    'pollution' => $pollution,
                    'hospitals' => $hospitals,
                    'police_stations' => $policeStations,
                    'build_key' => $civilBuildKeys[$hospitals][$policeStations],
                ];
            }
        }

        $packed = '';

        for ($capacity = 0; $capacity <= $hospitalCap + $policeCap; $capacity++) {
            $best = null;

            foreach ($options as $option) {
                if ($option['slots'] > $capacity) {
                    continue;
                }

                if ($best === null || $this->isBetterCivilOption($option, $best)) {
                    $best = $option;
                }
            }

            $packed .= pack(
                'dnnnn',
                $best['contribution'],
                $best['slots'],
                $best['pollution'],
                $best['hospitals'],
                $best['police_stations']
            ).$best['build_key'];
        }

        return $packed;
    }

    /**
     * @return array<string, mixed>
     */
    private function unpackCivilOption(string $packed, int $capacity): array
    {
        $buildKeyLength = count(EconomyRules::BUILD_FIELDS);
        $recordLength = 16 + $buildKeyLength;
        $record = substr($packed, $capacity * $recordLength, $recordLength);
        $values = unpack(
            'dcontribution/nslots/npollution/nhospitals/npolice_stations',
            substr($record, 0, 16)
        );
        $values['build_key'] = substr($record, 16, $buildKeyLength);

        return $values;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function civilBuildKeys(int $hospitalCap, int $policeCap): array
    {
        $keys = [];

        for ($hospitals = 0; $hospitals <= $hospitalCap; $hospitals++) {
            for ($policeStations = 0; $policeStations <= $policeCap; $policeStations++) {
                $build = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);
                $build['hospital'] = $hospitals;
                $build['police_station'] = $policeStations;
                $keys[$hospitals][$policeStations] = $this->compactBuildKey($build);
            }
        }

        return $keys;
    }

    private function isBetterCivilOption(array $candidate, array $current): bool
    {
        if (abs($candidate['contribution'] - $current['contribution']) > 0.000001) {
            return $candidate['contribution'] > $current['contribution'];
        }

        if ($candidate['slots'] !== $current['slots']) {
            return $candidate['slots'] < $current['slots'];
        }

        if ($candidate['pollution'] !== $current['pollution']) {
            return $candidate['pollution'] < $current['pollution'];
        }

        return $candidate['build_key'] < $current['build_key'];
    }

    private function makeCity(City $source, array $build, int $targetInfrastructure): City
    {
        $attributes = $source->getAttributes();
        $attributes['name'] = 'Recommended Build';
        $attributes['nuke_date'] = null;
        $attributes['infrastructure'] = $targetInfrastructure;
        $attributes['powered'] = true;

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $attributes[$field] = (int) ($build[$field] ?? 0);
        }

        return new City($attributes);
    }

    /**
     * @param  array<string, int>  $build
     * @return array<string, int>
     */
    private function normalizeBuild(array $build): array
    {
        $normalized = [];

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $normalized[$field] = max(0, (int) ($build[$field] ?? 0));
        }

        return $normalized;
    }

    private function buildingCount(array $build): int
    {
        $count = 0;

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $count += (int) ($build[$field] ?? 0);
        }

        return $count;
    }

    private function isDirectlyBetter(array $candidate, array $current): bool
    {
        if (abs($candidate['value'] - $current['value']) > 0.000001) {
            return $candidate['value'] > $current['value'];
        }

        if (abs($candidate['vector']['money'] - $current['vector']['money']) > 0.000001) {
            return $candidate['vector']['money'] > $current['vector']['money'];
        }

        return $this->buildKey($candidate['build']) < $this->buildKey($current['build']);
    }

    private function isBetterChoice(array $candidate, ?array $current): bool
    {
        if ($current === null) {
            return true;
        }

        if (abs($candidate['converted'] - $current['converted']) > 0.000001) {
            return $candidate['converted'] > $current['converted'];
        }

        if (abs($candidate['money'] - $current['money']) > 0.000001) {
            return $candidate['money'] > $current['money'];
        }

        if ($candidate['used_slots'] !== $current['used_slots']) {
            return $candidate['used_slots'] < $current['used_slots'];
        }

        if ($candidate['pollution'] !== $current['pollution']) {
            return $candidate['pollution'] < $current['pollution'];
        }

        return $candidate['build_key'] < $current['build_key'];
    }

    private function buildKey(array $build): string
    {
        $parts = [];

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $parts[] = sprintf('%s:%02d', $field, (int) ($build[$field] ?? 0));
        }

        return implode('|', $parts);
    }

    private function compactBuildKey(array $build): string
    {
        $counts = [];

        foreach (EconomyRules::BUILD_FIELDS as $field) {
            $counts[] = (int) ($build[$field] ?? 0);
        }

        return pack('C*', ...$counts);
    }

    private function projectChecker(Nation $nation): callable
    {
        return fn (string $project): bool => (bool) data_get($nation->projects, $project, false);
    }
}
