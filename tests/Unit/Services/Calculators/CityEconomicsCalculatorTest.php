<?php

namespace Tests\Unit\Services\Calculators;

use App\DataTransferObjects\Calculators\CalculatorResult;
use App\DataTransferObjects\MarketPriceSet;
use App\Models\RadiationSnapshot;
use App\Services\Calculators\CityEconomicsCalculator;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class CityEconomicsCalculatorTest extends UnitTestCase
{
    private CityEconomicsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CityEconomicsCalculator(
            new EconomyCalculator(new MilitaryCostCalculator),
        );
    }

    public function test_golden_build_fixture_reports_income_expenses_profit_and_roi(): void
    {
        $buildings = $this->buildings([
            'nuclear_power' => 1,
            'subway' => 1,
            'supermarket' => 4,
            'bank' => 5,
            'shopping_mall' => 4,
            'stadium' => 3,
        ]);
        $result = $this->calculate($buildings, prices: $this->prices(1_000))->toArray();

        $this->assertSame(419_343.05, $result['breakdowns']['gross_income_per_day']['money']);
        $this->assertSame(83_200.0, $result['breakdowns']['expenses_per_day']['money']);
        $this->assertSame(3.0, $result['breakdowns']['expenses_per_day']['resources']['uranium']);
        $this->assertSame(126.27, $result['breakdowns']['expenses_per_day']['resources']['food']);
        $this->assertSame(212_473.7, $result['breakdowns']['expenses_per_day']['market_value']);
        $this->assertSame(206_869.34, $result['breakdowns']['net_per_day']['market_value']);
        $this->assertSame(197_855.49, $result['breakdowns']['incremental_profit_per_day']['money']);
        $this->assertSame(-3.0, $result['breakdowns']['incremental_profit_per_day']['resources']['uranium']);
        $this->assertSame(194_855.49, $result['breakdowns']['incremental_profit_per_day']['market_value']);
        $this->assertSame(2_125_000.0, $result['breakdowns']['improvement_investment']['market_value']);
        $this->assertSame(10.91, $result['metrics']['payback_days']);
        $this->assertSame(275.09, $result['metrics']['roi_percent']);
    }

    public function test_zero_build_at_zero_infrastructure_keeps_base_population_income(): void
    {
        $result = $this->calculate(
            $this->buildings(),
            city: ['infrastructure' => 0.0, 'land' => 0.0, 'age_days' => 365, 'powered' => false],
        )->toArray();

        $this->assertSame(11.24, $result['breakdowns']['gross_income_per_day']['money']);
        $this->assertSame(0.0, $result['breakdowns']['expenses_per_day']['money']);
        $this->assertSame(11.24, $result['breakdowns']['net_per_day']['money']);
        $this->assertSame(10, $result['metrics']['population']);
        $this->assertNull($result['metrics']['payback_days']);
    }

    public function test_market_unavailable_keeps_raw_values_and_suppresses_roi(): void
    {
        $result = $this->calculate($this->buildings(['wind_power' => 4]))->toArray();

        $this->assertGreaterThan(0, $result['breakdowns']['gross_income_per_day']['money']);
        $this->assertNull($result['breakdowns']['gross_income_per_day']['market_value']);
        $this->assertNull($result['breakdowns']['improvement_investment']['market_value']);
        $this->assertNull($result['metrics']['payback_days']);
        $this->assertNull($result['metrics']['roi_percent']);
    }

    public function test_stale_market_prices_are_still_used_and_remain_identifiable_by_the_caller(): void
    {
        $prices = $this->prices(1_000, true);
        $result = $this->calculate($this->buildings(['wind_power' => 4]), prices: $prices)->toArray();

        $this->assertTrue($prices->stale);
        $this->assertNotNull($result['breakdowns']['net_per_day']['market_value']);
    }

    public function test_impossible_improvement_slot_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            $this->buildings(['wind_power' => 2]),
            city: ['infrastructure' => 50.0, 'land' => 1_000.0, 'age_days' => 365, 'powered' => true],
        );
    }

    public function test_continent_incompatible_resource_improvement_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            $this->buildings(['oil_well' => 1]),
            nation: ['continent' => 'NA', 'num_cities' => 10, 'domestic_policy' => 'NONE', 'treasure_income_modifier' => 0.0],
        );
    }

    public function test_farms_require_world_snapshot_context(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate($this->buildings(['farm' => 1]));
    }

    public function test_numeric_boundaries_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            $this->buildings(),
            city: ['infrastructure' => 20_000.01, 'land' => 1_000.0, 'age_days' => 365, 'powered' => true],
        );
    }

    public function test_non_finite_city_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            $this->buildings(),
            city: ['infrastructure' => INF, 'land' => 1_000.0, 'age_days' => 365, 'powered' => true],
        );
    }

    public function test_pinned_as_of_date_is_independent_of_the_wall_clock(): void
    {
        try {
            CarbonImmutable::setTestNow('2030-01-01 00:00:00 UTC');
            $first = $this->calculate($this->buildings(['wind_power' => 4]))->toArray();

            CarbonImmutable::setTestNow('2040-12-31 23:59:59 UTC');
            $second = $this->calculate($this->buildings(['wind_power' => 4]))->toArray();

            $this->assertSame($first, $second);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_result_discloses_income_power_and_farm_modifiers(): void
    {
        $snapshot = new RadiationSnapshot;
        $snapshot->setRawAttributes([
            'game_date' => '2026-08-08',
            'north_america' => 100.0,
            'south_america' => 0.0,
            'europe' => 0.0,
            'africa' => 0.0,
            'asia' => 0.0,
            'australia' => 0.0,
            'antarctica' => 0.0,
        ]);
        $result = $this->calculate(
            $this->buildings(['farm' => 1]),
            radiationSnapshot: $snapshot,
        )->toArray();
        $modifiers = array_column($result['modifiers'], null, 'key');

        $this->assertEqualsWithDelta(0.55, $modifiers['new_player_income_bonus']['rate'], 0.000000001);
        $this->assertTrue($modifiers['city_age_population_bonus']['applied']);
        $this->assertFalse($modifiers['powered_improvements']['applied']);
        $this->assertEqualsWithDelta(-0.12, $modifiers['farm_radiation']['rate'], 0.000000001);
        $this->assertEqualsWithDelta(0.2, $modifiers['farm_season']['rate'], 0.000000001);
        $this->assertFalse($modifiers['farm_continent']['applied']);
    }

    #[DataProvider('incomeModifierCombinations')]
    public function test_every_open_markets_modifier_combination(
        bool $openMarkets,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
        float $expectedGrossIncome,
    ): void {
        $projects = $this->projects([
            'government_support_agency' => $governmentSupportAgency,
            'bureau_of_domestic_affairs' => $bureauOfDomesticAffairs,
        ]);
        $result = $this->calculator->calculate(
            nationInput: [
                'continent' => 'NA',
                'num_cities' => 10,
                'domestic_policy' => $openMarkets ? 'OPEN_MARKETS' : 'NONE',
                'treasure_income_modifier' => 0.0,
            ],
            cityInput: ['infrastructure' => 1_000.0, 'land' => 1_000.0, 'age_days' => 365, 'powered' => false],
            buildings: $this->buildings(),
            projects: $projects,
            radiationSnapshot: null,
            prices: null,
            asOf: CarbonImmutable::parse('2026-08-08'),
            roiDays: 30,
        )->toArray();
        $this->assertSame($expectedGrossIncome, $result['breakdowns']['gross_income_per_day']['money']);
    }

    public static function incomeModifierCombinations(): array
    {
        return [
            'none' => [false, false, false, 138_287.55],
            'open markets' => [true, false, false, 139_670.43],
            'government support agency without policy' => [false, true, false, 138_287.55],
            'open markets and government support agency' => [true, true, false, 140_361.86],
            'bureau of domestic affairs without policy' => [false, false, true, 138_287.55],
            'open markets and bureau of domestic affairs' => [true, false, true, 140_016.15],
            'both projects without policy' => [false, true, true, 138_287.55],
            'all modifiers' => [true, true, true, 140_707.58],
        ];
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function buildings(array $overrides = []): array
    {
        return array_replace(array_fill_keys(EconomyRules::BUILD_FIELDS, 0), $overrides);
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    private function projects(array $overrides = []): array
    {
        return array_replace(array_fill_keys(array_keys(CityEconomicsCalculator::ECONOMY_PROJECTS), false), $overrides);
    }

    /**
     * @param  array<string, int>  $buildings
     * @param  array<string, mixed>|null  $nation
     * @param  array<string, mixed>|null  $city
     */
    private function calculate(
        array $buildings,
        ?array $nation = null,
        ?array $city = null,
        ?MarketPriceSet $prices = null,
        ?RadiationSnapshot $radiationSnapshot = null,
    ): CalculatorResult {
        return $this->calculator->calculate(
            nationInput: $nation ?? ['continent' => 'NA', 'num_cities' => 10, 'domestic_policy' => 'NONE', 'treasure_income_modifier' => 0.0],
            cityInput: $city ?? ['infrastructure' => 1_000.0, 'land' => 1_000.0, 'age_days' => 365, 'powered' => true],
            buildings: $buildings,
            projects: $this->projects(),
            radiationSnapshot: $radiationSnapshot,
            prices: $prices,
            asOf: CarbonImmutable::parse('2026-08-08'),
            roiDays: 30,
        );
    }

    private function prices(float $price, bool $stale = false): MarketPriceSet
    {
        $prices = array_fill_keys(EconomyRules::TRADE_RESOURCES, $price);

        return new MarketPriceSet(
            acquisitionPrices: $prices,
            liquidationPrices: $prices,
            calculatedAt: CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            stale: $stale,
        );
    }
}
