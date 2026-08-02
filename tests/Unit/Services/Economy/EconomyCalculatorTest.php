<?php

namespace Tests\Unit\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\PWHelperService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EconomyCalculatorTest extends TestCase
{
    private EconomyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-04-15 12:00:00 UTC');
        $this->calculator = app(EconomyCalculator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function uranium_uses_the_correct_base_and_project_output(): void
    {
        $nation = $this->nation(PWHelperService::PROJECTS['Uranium Enrichment Program']);
        $vector = $this->calculator->improvementOperatingVector(
            $nation,
            $this->city(),
            'uranium_mine',
            5,
            null
        );

        $this->assertSame(45.0, $vector['uranium']);
        $this->assertSame(-25000.0, $vector['money']);
    }

    #[Test]
    public function nuclear_power_uses_three_uranium_per_thousand_infrastructure_and_has_no_pollution(): void
    {
        $nation = $this->nation();
        $city = $this->city([
            'infrastructure' => 2500,
            'nuclear_power' => 2,
        ]);
        $vector = $this->calculator->powerOperatingVector($nation, $city, 2500);

        $this->assertSame(-9.0, $vector['uranium']);
        $this->assertSame(0, $this->calculator->pollution($city, fn (): bool => false));
    }

    #[Test]
    public function radiation_uses_the_five_region_global_divisor_and_antarctica_modifier(): void
    {
        $radiation = new RadiationSnapshot([
            'game_date' => '2126-04-15',
            'north_america' => 100,
            'south_america' => 100,
            'europe' => 100,
            'africa' => 100,
            'asia' => 100,
            'australia' => 100,
            'antarctica' => 100,
        ]);
        $hasProject = fn (): bool => false;

        $this->assertEqualsWithDelta(
            9.12,
            $this->calculator->resourceProduction('food', 500, 1, 'NA', $hasProject, $radiation),
            0.000001
        );
        $this->assertEqualsWithDelta(
            4.56,
            $this->calculator->resourceProduction('food', 500, 1, 'AN', $hasProject, $radiation),
            0.000001
        );
    }

    #[Test]
    public function green_technologies_reduces_raw_resource_upkeep(): void
    {
        $nation = $this->nation(PWHelperService::PROJECTS['Green Technologies']);
        $vector = $this->calculator->improvementOperatingVector(
            $nation,
            $this->city(),
            'coal_mine',
            1,
            null
        );

        $this->assertSame(-360.0, $vector['money']);
    }

    #[Test]
    public function unpowered_cities_keep_raw_production_and_base_income_but_disable_powered_improvements(): void
    {
        $nation = $this->nation(
            PWHelperService::PROJECTS['International Trade Center']
            | PWHelperService::PROJECTS['Telecommunications Satellite']
            | PWHelperService::PROJECTS['Specialized Police Training Program']
        );
        $city = $this->city([
            'infrastructure' => 1000,
            'land' => 1000,
            'powered' => true,
            'wind_power' => 1,
            'uranium_mine' => 1,
            'oil_refinery' => 1,
            'stadium' => 1,
        ]);
        $metrics = $this->calculator->calculateCityMetrics($nation, $city, null, $this->prices());

        $this->assertFalse($metrics['powered']);
        $this->assertSame(3.0, $metrics['resource_profit_per_day']['uranium']);
        $this->assertSame(0.0, $metrics['resource_profit_per_day']['gasoline']);
        $this->assertSame(0, $metrics['commerce']);
        $this->assertGreaterThan(0, $metrics['city_income_per_day']);
    }

    #[Test]
    public function military_research_reduces_supported_unit_upkeep_without_going_negative(): void
    {
        $nation = $this->nation();
        $nation->setRelation('cities', new Collection);
        $nation->setRelation('military', new NationMilitary([
            'soldiers' => 1000,
            'tanks' => 10,
            'aircraft' => 2,
            'ships' => 1,
        ]));
        $withoutResearch = $this->calculator->calculateNation($nation, null, $this->prices());

        foreach ([
            'ground_capacity_research',
            'ground_cost_research',
            'air_capacity_research',
            'air_cost_research',
            'naval_capacity_research',
            'naval_cost_research',
        ] as $field) {
            $nation->{$field} = 20;
        }

        $withResearch = $this->calculator->calculateNation($nation, null, $this->prices());

        $this->assertGreaterThan(
            $withoutResearch['military_upkeep_per_day'],
            $withResearch['military_upkeep_per_day']
        );
        $this->assertEqualsWithDelta(-1550.87, $withResearch['military_upkeep_per_day'], 0.01);
    }

    #[Test]
    public function color_revenue_is_not_multiplied_by_population_income_modifiers(): void
    {
        $nation = $this->nation();
        $nation->domestic_policy = 'OPEN_MARKETS';
        $nation->color_turn_bonus = 195255;
        $nation->treasure_income_modifier = 0.1;
        $nation->setRelation('cities', new Collection);

        $result = $this->calculator->calculateNation($nation, null, $this->prices());

        $this->assertSame(2343060.0, $result['color_bonus_per_day']);
        $this->assertSame(2343060.0, $result['money_profit_per_day']);
    }

    #[Test]
    #[DataProvider('nuclearPollutionProvider')]
    public function nuclear_pollution_uses_pinned_game_calendar_turns(
        string $nukeDate,
        int $expectedPollution
    ): void {
        $city = $this->city(['nuke_date' => $nukeDate]);

        $this->assertSame(
            $expectedPollution,
            $this->calculator->pollution(
                $city,
                fn (): bool => false,
                CarbonImmutable::parse('2126-09-23')
            )
        );
    }

    public static function nuclearPollutionProvider(): array
    {
        return [
            'same turn' => ['2126-09-23', 400],
            'one turn old' => ['2126-09-22', 396],
            'last active turn' => ['2126-05-15', 3],
            'expired' => ['2126-05-14', 0],
            'ancient' => ['2103-05-17', 0],
        ];
    }

    #[Test]
    public function future_nuclear_date_is_rejected_as_inconsistent_context(): void
    {
        $this->expectException(ProfitabilityContextUnavailable::class);

        $this->calculator->pollution(
            $this->city(['nuke_date' => '2126-09-24']),
            fn (): bool => false,
            CarbonImmutable::parse('2126-09-23')
        );
    }

    #[Test]
    public function nuclear_date_requires_a_pinned_game_date(): void
    {
        $this->expectException(ProfitabilityContextUnavailable::class);

        $this->calculator->pollution(
            $this->city(['nuke_date' => '2126-09-23']),
            fn (): bool => false
        );
    }

    #[Test]
    public function nuclear_decay_changes_with_the_game_date_while_host_time_is_frozen(): void
    {
        CarbonImmutable::setTestNow('2026-01-15 12:00:00 UTC');
        $city = $this->city(['nuke_date' => '2126-09-20']);

        $this->assertSame(
            400,
            $this->calculator->pollution($city, fn (): bool => false, CarbonImmutable::parse('2126-09-20'))
        );
        $this->assertSame(
            396,
            $this->calculator->pollution($city, fn (): bool => false, CarbonImmutable::parse('2126-09-21'))
        );
        $this->assertSame('2026-01-15', now()->toDateString());
    }

    #[Test]
    #[DataProvider('seasonalProductionProvider')]
    public function seasonal_production_uses_game_month_instead_of_host_month(
        string $gameDate,
        string $continent,
        float $expected
    ): void {
        CarbonImmutable::setTestNow('2026-01-15 12:00:00 UTC');
        $radiation = new RadiationSnapshot([
            'game_date' => $gameDate,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);

        $this->assertEqualsWithDelta(
            $expected,
            $this->calculator->resourceProduction(
                'food',
                500,
                1,
                $continent,
                fn (): bool => false,
                $radiation
            ),
            0.000001
        );
    }

    public static function seasonalProductionProvider(): array
    {
        return [
            'northern winter' => ['2126-01-15', 'NA', 9.6],
            'southern summer' => ['2126-01-15', 'SA', 14.4],
            'northern summer' => ['2126-07-15', 'EU', 14.4],
            'african winter' => ['2126-07-15', 'AF', 9.6],
            'northern spring' => ['2126-04-15', 'AS', 12.0],
            'southern spring' => ['2126-04-15', 'AU', 12.0],
            'antarctic summer' => ['2126-01-15', 'AN', 3.0],
            'antarctic winter' => ['2126-07-15', 'AN', 3.0],
            'antarctic shoulder season' => ['2126-04-15', 'AN', 6.0],
        ];
    }

    #[Test]
    public function city_founding_age_continues_to_use_real_world_time(): void
    {
        $city = $this->city([
            'date' => '2020-01-01',
            'infrastructure' => 2500,
            'land' => 4000,
        ]);
        $hasProject = fn (): bool => false;
        $gameDate = CarbonImmutable::parse('2126-09-21');

        CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');
        $youngerPopulation = $this->calculator->population($city, $hasProject, $gameDate);

        CarbonImmutable::setTestNow('2027-01-01 12:00:00 UTC');
        $olderPopulation = $this->calculator->population($city, $hasProject, $gameDate);

        $this->assertGreaterThan($youngerPopulation, $olderPopulation);
    }

    private function nation(int $projectBits = 0): Nation
    {
        $nation = new Nation([
            'id' => 1,
            'leader_name' => 'Leader',
            'nation_name' => 'Nation',
            'continent' => 'NA',
            'domestic_policy' => 'MANIFEST_DESTINY',
            'num_cities' => 1,
            'project_bits' => (string) $projectBits,
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
        ]);
        $nation->setRelation('cities', new Collection([$this->city()]));
        $nation->setRelation('military', new NationMilitary);

        return $nation;
    }

    private function city(array $overrides = []): City
    {
        return new City(array_replace([
            'date' => '2025-04-15',
            'nuke_date' => null,
            'infrastructure' => 100,
            'land' => 500,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
        ], $overrides));
    }

    private function prices(): MarketPriceSet
    {
        return MarketPriceSet::symmetric(array_fill_keys(EconomyRules::TRADE_RESOURCES, 1.0));
    }
}
