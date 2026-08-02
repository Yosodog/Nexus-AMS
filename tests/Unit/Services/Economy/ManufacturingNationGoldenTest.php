<?php

namespace Tests\Unit\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\PWHelperService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturingNationGoldenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-02 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function forty_three_city_manufacturing_profile_has_a_stable_daily_result(): void
    {
        $calculator = app(EconomyCalculator::class);
        $nation = $this->nation();
        $radiation = $this->radiationSnapshot();
        $prices = $this->prices();

        $result = $calculator->calculateNation($nation, $radiation, $prices);
        $population = $nation->cities->sum(
            fn (City $city): int => $calculator->calculateCityMetrics(
                $nation,
                $city,
                $radiation,
                $prices
            )['population']
        );

        $this->assertSame(16199657, $population);
        $this->assertSame(36060312.25, $result['city_income_per_day']);
        $this->assertSame(2343060.0, $result['color_bonus_per_day']);
        $this->assertSame(-4736600.0, $result['military_upkeep_per_day']);
        $this->assertSame(-387.0, $result['resource_profit_per_day']['uranium']);
        $this->assertSame(-1315.8, $result['resource_profit_per_day']['coal']);
        $this->assertSame(-1315.8, $result['resource_profit_per_day']['iron']);
        $this->assertEqualsWithDelta(120.36, $result['resource_profit_per_day']['bauxite'], 0.000001);
        $this->assertEqualsWithDelta(3947.4, $result['resource_profit_per_day']['steel'], 0.000001);
        $this->assertEqualsWithDelta(3922.92, $result['resource_profit_per_day']['aluminum'], 0.000001);
        $this->assertEqualsWithDelta(-27820.43, $result['resource_profit_per_day']['food'], 0.01);
        $this->assertSame(27500902.25, $result['money_profit_per_day']);
        $this->assertSame(40517556.2, $result['converted_profit_per_day']);

        $repeat = $calculator->calculateNation($nation, $radiation, $prices);
        $this->assertSame($result, $repeat);
    }

    private function nation(): Nation
    {
        $nation = new Nation([
            'id' => 526341,
            'leader_name' => 'Manufacturing Leader',
            'nation_name' => 'Manufacturing Nation',
            'continent' => 'AF',
            'domestic_policy' => 'OPEN_MARKETS',
            'num_cities' => 43,
            'project_bits' => (string) $this->projectBits(),
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
            'treasure_income_modifier' => 0.0,
            'color_turn_bonus' => 195255,
            'ground_capacity_research' => 0,
            'ground_cost_research' => 0,
            'air_capacity_research' => 0,
            'air_cost_research' => 0,
            'naval_capacity_research' => 0,
            'naval_cost_research' => 0,
        ]);
        $nation->setRelation('cities', new Collection($this->cities()));
        $nation->setRelation('military', new NationMilitary([
            'soldiers' => 0,
            'tanks' => 34498,
            'aircraft' => 3630,
            'ships' => 44,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 60,
        ]));

        return $nation;
    }

    /**
     * @return list<City>
     */
    private function cities(): array
    {
        $dates = [
            '2014-12-12', '2014-12-20', '2014-12-30', '2015-01-24', '2015-02-26',
            '2015-03-15', '2015-04-10', '2015-06-09', '2015-07-31', '2015-09-15',
            '2015-12-06', '2016-01-04', '2016-03-07', '2016-03-30', '2016-05-05',
            '2016-08-07', '2016-09-08', '2017-09-08', '2017-10-16', '2017-11-15',
            '2018-01-15', '2018-04-15', '2020-07-08', '2020-07-24', '2020-08-17',
            '2020-09-19', '2021-03-16', '2021-04-15', '2021-06-20', '2021-07-13',
            '2022-01-10', '2022-04-08', '2022-05-30', '2022-10-13', '2023-04-24',
            '2023-12-19', '2024-03-20', '2024-04-30', '2024-06-30', '2024-10-30',
            '2025-03-05', '2025-08-15', '2025-10-02',
        ];
        $infrastructure = array_fill(0, 43, 2500.0);
        $infrastructure[0] = 2422.25;
        $infrastructure[21] = 2419.0;
        $infrastructure[40] = 2412.5;
        $infrastructure[42] = 2416.5;

        return collect($dates)->map(function (string $date, int $index) use ($infrastructure): City {
            $isReducedCity = $index === 42;

            return new City([
                'id' => 1000000 + $index,
                'date' => $date,
                'nuke_date' => null,
                'infrastructure' => $infrastructure[$index],
                'land' => $index < 32 ? 4000 : ($index < 42 ? 4500 : 4250),
                'powered' => true,
                ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
                'nuclear_power' => 2,
                'bauxite_mine' => $isReducedCity ? 7 : 8,
                'steel_mill' => 5,
                'aluminum_refinery' => $isReducedCity ? 4 : 5,
                'bank' => $isReducedCity ? 3 : 4,
                'shopping_mall' => 4,
                'stadium' => 3,
                'hospital' => 5,
                'police_station' => 1,
                'recycling_center' => 3,
                'subway' => 1,
            ]);
        })->all();
    }

    private function projectBits(): int
    {
        return collect([
            'Ironworks',
            'Bauxiteworks',
            'International Trade Center',
            'Recycling Initiative',
            'Telecommunications Satellite',
            'Green Technologies',
            'Clinical Research Center',
            'Specialized Police Training Program',
            'Government Support Agency',
            'Bureau of Domestic Affairs',
        ])->reduce(
            fn (int $bits, string $project): int => $bits | PWHelperService::PROJECTS[$project],
            0
        );
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

    private function prices(): MarketPriceSet
    {
        return new MarketPriceSet(
            acquisitionPrices: [
                'coal' => 5000,
                'oil' => 5030,
                'uranium' => 2548,
                'iron' => 5178,
                'bauxite' => 4920,
                'lead' => 5274,
                'gasoline' => 3779,
                'munitions' => 2294,
                'steel' => 4790,
                'aluminum' => 2948,
                'food' => 85,
            ],
            liquidationPrices: [
                'coal' => 4923,
                'oil' => 4923,
                'uranium' => 2379,
                'iron' => 4987,
                'bauxite' => 4705,
                'lead' => 5101,
                'gasoline' => 3606,
                'munitions' => 2200,
                'steel' => 4607,
                'aluminum' => 2806,
                'food' => 83,
            ],
        );
    }
}
