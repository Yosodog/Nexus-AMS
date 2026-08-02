<?php

namespace Tests\Unit\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\BuildOptimizer;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildOptimizerTest extends TestCase
{
    private BuildOptimizer $optimizer;

    private EconomyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(EconomyCalculator::class);
        $this->optimizer = new BuildOptimizer($this->calculator);
    }

    #[Test]
    public function target_capacity_uses_the_highest_recovered_city(): void
    {
        $first = $this->city(['infrastructure' => 1000, 'coal_mine' => 10, 'farm' => 20, 'land' => 900]);
        $second = $this->city(['infrastructure' => 1250, 'land' => 1100]);
        $profile = $this->optimizer->targetProfile(new Collection([$first, $second]));

        $this->assertSame(1500, $profile['target_infrastructure']);
        $this->assertSame(30, $profile['available_slots']);
        $this->assertSame(2, $profile['cities_below_target']);
        $this->assertSame(750.0, $profile['infrastructure_shortfall']);
        $this->assertSame(900.0, $profile['land_used']);
    }

    #[Test]
    public function target_capacity_rounds_down_without_inventing_a_slot(): void
    {
        $city = $this->city(['infrastructure' => 49, 'land' => 250]);

        $profile = $this->optimizer->targetProfile(new Collection([$city]));

        $this->assertSame(0, $profile['target_infrastructure']);
        $this->assertSame(0, $profile['available_slots']);
    }

    #[Test]
    public function small_slot_optimization_matches_exhaustive_enumeration_and_is_deterministic(): void
    {
        $nation = $this->nation();
        $city = $this->city(['infrastructure' => 100, 'land' => 500]);
        $cities = new Collection([$city]);
        $prices = new MarketPriceSet(
            acquisitionPrices: array_fill_keys(EconomyRules::TRADE_RESOURCES, 100.0),
            liquidationPrices: array_fill_keys(EconomyRules::TRADE_RESOURCES, 90.0),
        );
        $minimum = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);

        $radiation = $this->radiationSnapshot();
        $optimized = $this->optimizer->optimize($nation, $cities, $minimum, $radiation, $prices);
        $repeat = $this->optimizer->optimize($nation, $cities, $minimum, $radiation, $prices);
        $bruteForceBest = $this->bruteForceTwoSlotBest($nation, $city, $radiation, $prices);

        $this->assertNotNull($optimized);
        $this->assertEqualsWithDelta(
            $bruteForceBest,
            $optimized['metrics']['converted_profit_per_day'],
            0.01
        );
        $this->assertSame($optimized['build'], $repeat['build']);
        $this->assertLessThanOrEqual($optimized['available_slots'], $optimized['used_slots']);
    }

    #[Test]
    public function three_slot_optimization_matches_multistage_exhaustive_enumeration(): void
    {
        $nation = $this->nation();
        $city = $this->city(['infrastructure' => 150, 'land' => 500]);
        $cities = new Collection([$city]);
        $prices = new MarketPriceSet(
            acquisitionPrices: array_fill_keys(EconomyRules::TRADE_RESOURCES, 100.0),
            liquidationPrices: array_fill_keys(EconomyRules::TRADE_RESOURCES, 90.0),
        );
        $radiation = $this->radiationSnapshot();

        $optimized = $this->optimizer->optimize(
            $nation,
            $cities,
            array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            $radiation,
            $prices
        );

        $this->assertNotNull($optimized);
        $this->assertEqualsWithDelta(
            $this->bruteForceThreeSlotBest($nation, $city, $radiation, $prices),
            $optimized['metrics']['converted_profit_per_day'],
            0.01
        );
    }

    #[Test]
    public function representative_high_infrastructure_optimization_stays_within_the_runtime_budget(): void
    {
        $nation = $this->nation();
        $cities = new Collection;

        foreach (range(1, 20) as $index) {
            $cities->push($this->city([
                'infrastructure' => 4000,
                'land' => 3000 + ($index * 50),
                'date' => now()->subDays(365 + $index)->toDateString(),
            ]));
        }
        $prices = new MarketPriceSet(
            acquisitionPrices: [
                'coal' => 250,
                'oil' => 300,
                'uranium' => 3200,
                'iron' => 2200,
                'bauxite' => 2100,
                'lead' => 2000,
                'gasoline' => 3400,
                'munitions' => 3200,
                'steel' => 4200,
                'aluminum' => 3800,
                'food' => 110,
            ],
            liquidationPrices: [
                'coal' => 225,
                'oil' => 275,
                'uranium' => 3000,
                'iron' => 2000,
                'bauxite' => 1900,
                'lead' => 1800,
                'gasoline' => 3200,
                'munitions' => 3000,
                'steel' => 4000,
                'aluminum' => 3600,
                'food' => 100,
            ],
        );
        $startedAt = hrtime(true);

        $result = $this->optimizer->optimize(
            $nation,
            $cities,
            array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            $this->radiationSnapshot(),
            $prices
        );
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertNotNull($result);
        $this->assertLessThan(5.0, $elapsedSeconds);
        $this->assertLessThan(256 * 1024 * 1024, memory_get_peak_usage(true));
    }

    #[Test]
    public function food_deficits_are_valued_before_dominated_states_are_pruned(): void
    {
        $nation = $this->nation();
        $city = $this->city(['infrastructure' => 100, 'land' => 500]);
        $acquisition = array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0);
        $liquidation = array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0);
        $acquisition['food'] = 1000.0;
        $prices = new MarketPriceSet(
            acquisitionPrices: $acquisition,
            liquidationPrices: $liquidation,
        );

        $result = $this->optimizer->optimize(
            $nation,
            new Collection([$city]),
            array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            $this->radiationSnapshot(),
            $prices
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['build']['farm']);
    }

    #[Test]
    public function mixed_city_resources_are_averaged_before_side_specific_conversion(): void
    {
        $nation = $this->nation();
        $cities = new Collection([
            $this->city(['infrastructure' => 100, 'land' => 10]),
            $this->city(['infrastructure' => 100, 'land' => 5000]),
        ]);
        $acquisition = array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0);
        $liquidation = array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0);
        $acquisition['food'] = 1000.0;
        $prices = new MarketPriceSet(
            acquisitionPrices: $acquisition,
            liquidationPrices: $liquidation,
        );
        $radiation = $this->radiationSnapshot();
        $result = $this->optimizer->optimize(
            $nation,
            $cities,
            array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            $radiation,
            $prices
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['build']['farm']);

        $averageVector = EconomyRules::emptyResourceBuffer();
        $perCityConverted = 0.0;

        foreach ($cities as $city) {
            $recommendedCity = $this->city($city->getAttributes());
            $recommendedCity->nuke_date = null;
            $recommendedCity->infrastructure = $result['target_infrastructure'];
            $recommendedCity->powered = true;

            foreach (EconomyRules::BUILD_FIELDS as $field) {
                $recommendedCity->{$field} = $result['build'][$field] ?? 0;
            }

            $cityResult = $this->calculator->calculateCity($nation, $recommendedCity, $radiation, $prices);
            $perCityConverted += $prices->convert($cityResult['resource_profit_per_day']);

            foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                $averageVector[$resource] += $cityResult['resource_profit_per_day'][$resource] / $cities->count();
            }
        }

        $aggregateConverted = round($prices->convert($averageVector), 2);
        $averageConverted = round($perCityConverted / $cities->count(), 2);

        $this->assertSame($aggregateConverted, $result['metrics']['converted_profit_per_day']);
        $this->assertNotSame($averageConverted, $aggregateConverted);
    }

    private function bruteForceTwoSlotBest(
        Nation $nation,
        City $source,
        RadiationSnapshot $radiation,
        MarketPriceSet $prices
    ): float {
        $best = -INF;
        $hasProject = fn (): bool => false;
        $candidateFields = [
            null,
            ...array_keys(EconomyRules::RAW_BUILDINGS),
            ...array_keys(EconomyRules::MANUFACTURING_BUILDINGS),
            ...EconomyRules::SUPPORT_FIELDS,
        ];

        foreach (EconomyRules::POWER_FIELDS as $powerField) {
            foreach ($candidateFields as $candidateField) {
                if (
                    $candidateField !== null
                    && (! EconomyRules::isFieldAllowed($candidateField, $nation->continent)
                        || EconomyRules::improvementCap($candidateField, $hasProject) < 1)
                ) {
                    continue;
                }

                $build = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);
                $build[$powerField] = 1;

                if ($candidateField !== null) {
                    $build[$candidateField] = 1;
                }

                $city = $this->city($source->getAttributes());
                $city->infrastructure = 100;
                $city->powered = true;

                foreach ($build as $field => $count) {
                    $city->{$field} = $count;
                }

                $metrics = $this->calculator->calculateCityMetrics($nation, $city, $radiation, $prices);

                if ($metrics['powered']) {
                    if ($metrics['converted_profit_per_day'] > $best) {
                        $best = $metrics['converted_profit_per_day'];
                    }
                }
            }
        }

        return $best;
    }

    private function bruteForceThreeSlotBest(
        Nation $nation,
        City $source,
        RadiationSnapshot $radiation,
        MarketPriceSet $prices
    ): float {
        $best = -INF;
        $hasProject = fn (): bool => false;
        $candidateFields = array_values(array_filter(
            EconomyRules::BUILD_FIELDS,
            fn (string $field): bool => ! in_array($field, EconomyRules::POWER_FIELDS, true)
                && EconomyRules::isFieldAllowed($field, $nation->continent)
                && EconomyRules::improvementCap($field, $hasProject) > 0
        ));
        $allocations = [[], ...array_map(fn (string $field): array => [$field], $candidateFields)];

        foreach ($candidateFields as $leftIndex => $leftField) {
            foreach (array_slice($candidateFields, $leftIndex) as $rightField) {
                if (
                    $leftField === $rightField
                    && EconomyRules::improvementCap($leftField, $hasProject) < 2
                ) {
                    continue;
                }

                $allocations[] = [$leftField, $rightField];
            }
        }

        foreach (EconomyRules::POWER_FIELDS as $powerField) {
            foreach ($allocations as $allocation) {
                $build = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);
                $build[$powerField] = 1;

                foreach ($allocation as $field) {
                    $build[$field]++;
                }

                $city = $this->city($source->getAttributes());
                $city->infrastructure = 150;
                $city->powered = true;

                foreach ($build as $field => $count) {
                    $city->{$field} = $count;
                }

                $metrics = $this->calculator->calculateCityMetrics($nation, $city, $radiation, $prices);

                if ($metrics['powered']) {
                    $best = max($best, $metrics['converted_profit_per_day']);
                }
            }
        }

        return $best;
    }

    private function nation(): Nation
    {
        return new Nation([
            'id' => 1,
            'leader_name' => 'Leader',
            'nation_name' => 'Nation',
            'continent' => 'NA',
            'domestic_policy' => 'MANIFEST_DESTINY',
            'num_cities' => 1,
            'project_bits' => '0',
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
        ]);
    }

    private function city(array $overrides = []): City
    {
        return new City(array_replace([
            'date' => now()->subYear()->toDateString(),
            'nuke_date' => null,
            'infrastructure' => 100,
            'land' => 500,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
        ], $overrides));
    }

    private function radiationSnapshot(): RadiationSnapshot
    {
        return new RadiationSnapshot([
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);
    }
}
