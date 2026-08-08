<?php

namespace Tests\Unit\Services\Calculators;

use App\DataTransferObjects\MarketPriceSet;
use App\Services\Calculators\GamePurchaseCostCalculator;
use App\Services\Calculators\ProjectCostCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class GamePurchaseCostCalculatorTest extends UnitTestCase
{
    private GamePurchaseCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new GamePurchaseCostCalculator(new ProjectCostCatalog);
    }

    public function test_golden_purchase_fixtures_match_known_outputs(): void
    {
        $city = $this->calculator->city(20, 40.8216, false, false, false)->toArray();
        $infrastructure = $this->calculator->infrastructure(55, 300, false, false, false, false, false)->toArray();
        $land = $this->calculator->land(250, 1000, false, false, false, false, false)->toArray();

        $this->assertSame(95_507_890.91, $city['breakdowns']['purchase']['money']);
        $this->assertSame(91_101.95, $infrastructure['breakdowns']['purchase']['money']);
        $this->assertSame(356_850.0, $land['breakdowns']['purchase']['money']);
    }

    public function test_current_equals_target_returns_zero_for_infrastructure_and_land(): void
    {
        $infrastructure = $this->calculator->infrastructure(1_500, 1_500, true, true, true, true, true)->toArray();
        $land = $this->calculator->land(2_000, 2_000, true, true, true, true, true)->toArray();

        $this->assertSame(0.0, $infrastructure['breakdowns']['purchase']['money']);
        $this->assertSame(0.0, $land['breakdowns']['purchase']['money']);
    }

    #[DataProvider('invalidPurchaseRanges')]
    public function test_invalid_purchase_ranges_are_rejected(string $calculator, float $current, float $target): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->{$calculator}($current, $target, false, false, false, false, false);
    }

    public static function invalidPurchaseRanges(): array
    {
        return [
            'negative infrastructure' => ['infrastructure', -0.01, 100],
            'infrastructure target below current' => ['infrastructure', 100, 99.99],
            'infrastructure over maximum' => ['infrastructure', 100, 20_000.01],
            'negative land' => ['land', -0.01, 100],
            'land target below current' => ['land', 100, 99.99],
            'land over maximum' => ['land', 100, 20_000.01],
        ];
    }

    public function test_invalid_city_and_research_states_are_rejected(): void
    {
        try {
            $this->calculator->city(0, 40.8216, false, false, false);
            $this->fail('An invalid city number should fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $levels = array_fill_keys(array_keys(GamePurchaseCostCalculator::RESEARCH_BRANCHES), 0);
        $targets = $levels;
        $targets['ground_cost'] = -1;

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->research($levels, $targets, false);
    }

    #[DataProvider('invalidCityInputs')]
    public function test_invalid_city_bounds_are_rejected(int $cityNumber, float $cityAverage): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->city($cityNumber, $cityAverage, false, false, false);
    }

    public static function invalidCityInputs(): array
    {
        return [
            'city number over maximum' => [10_001, 40.8216],
            'city average over maximum' => [20, 10_000.01],
            'non-finite city average' => [20, INF],
        ];
    }

    #[DataProvider('threeModifierCombinations')]
    public function test_every_city_and_project_modifier_combination(
        bool $policy,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
    ): void {
        $factor = 1
            - ($policy ? 0.05 : 0)
            - ($policy && $governmentSupportAgency ? 0.025 : 0)
            - ($policy && $bureauOfDomesticAffairs ? 0.0125 : 0);
        $city = $this->calculator->city(20, 40.8216, $policy, $governmentSupportAgency, $bureauOfDomesticAffairs)->toArray();
        $project = $this->calculator->project('arms_stockpile', $policy, $governmentSupportAgency, $bureauOfDomesticAffairs)->toArray();

        $this->assertSame(round(95_507_890.91465363 * $factor, 2), $city['breakdowns']['purchase']['money']);
        $this->assertSame(round(10_000_000 * $factor, 2), $project['breakdowns']['purchase']['money']);
        $this->assertSame(round(500 * $factor, 2), $project['breakdowns']['purchase']['resources']['coal']);
    }

    public static function threeModifierCombinations(): array
    {
        return self::booleanCombinations(3);
    }

    #[DataProvider('fiveModifierCombinations')]
    public function test_every_infrastructure_and_land_modifier_combination(
        bool $policy,
        bool $firstIndependentProject,
        bool $secondIndependentProject,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
    ): void {
        $factor = 1
            - ($policy ? 0.05 : 0)
            - ($firstIndependentProject ? 0.05 : 0)
            - ($secondIndependentProject ? 0.05 : 0)
            - ($policy && $governmentSupportAgency ? 0.025 : 0)
            - ($policy && $bureauOfDomesticAffairs ? 0.0125 : 0);
        $infrastructure = $this->calculator->infrastructure(
            55,
            300,
            $policy,
            $firstIndependentProject,
            $secondIndependentProject,
            $governmentSupportAgency,
            $bureauOfDomesticAffairs,
        )->toArray();
        $land = $this->calculator->land(
            250,
            1_000,
            $policy,
            $firstIndependentProject,
            $secondIndependentProject,
            $governmentSupportAgency,
            $bureauOfDomesticAffairs,
        )->toArray();

        $this->assertSame(round(91_101.95 * $factor, 2), $infrastructure['breakdowns']['purchase']['money']);
        $this->assertSame(round(356_850 * $factor, 2), $land['breakdowns']['purchase']['money']);
    }

    public static function fiveModifierCombinations(): array
    {
        return self::booleanCombinations(5);
    }

    public function test_research_golden_fixture_zero_case_and_modifier_rounding(): void
    {
        $current = array_fill_keys(array_keys(GamePurchaseCostCalculator::RESEARCH_BRANCHES), 0);
        $target = $current;
        $target['ground_cost'] = 1;
        $base = $this->calculator->research($current, $target, false)->toArray();
        $discounted = $this->calculator->research($current, $target, true)->toArray();
        $zero = $this->calculator->research($current, $current, true)->toArray();

        $this->assertSame(602_250.0, $base['breakdowns']['purchase']['money']);
        $this->assertSame(100.0, $base['breakdowns']['purchase']['resources']['gasoline']);
        $this->assertSame(400.0, $base['breakdowns']['purchase']['resources']['aluminum']);
        $this->assertSame(572_137.5, $discounted['breakdowns']['purchase']['money']);
        $this->assertSame(95.0, $discounted['breakdowns']['purchase']['resources']['steel']);
        $this->assertSame(0.0, $zero['breakdowns']['purchase']['money']);
        $this->assertSame([], $zero['breakdowns']['purchase']['resources']);
    }

    public function test_zero_research_cost_does_not_require_prices_for_zero_resources(): void
    {
        $levels = array_fill_keys(array_keys(GamePurchaseCostCalculator::RESEARCH_BRANCHES), 0);
        $result = $this->calculator->research(
            $levels,
            $levels,
            false,
            new MarketPriceSet([], []),
        )->toArray();

        $this->assertSame([], $result['breakdowns']['purchase']['resources']);
        $this->assertSame(0.0, $result['breakdowns']['purchase']['market_value']);
    }

    public function test_market_value_is_optional_and_stale_prices_remain_explicitly_usable(): void
    {
        $withoutPrices = $this->calculator->project('international_trade_center', false, false, false)->toArray();
        $stalePrices = new MarketPriceSet(
            acquisitionPrices: ['aluminum' => 2_000.0],
            liquidationPrices: ['aluminum' => 1_500.0],
            calculatedAt: CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            stale: true,
        );
        $withStalePrices = $this->calculator->project(
            'international_trade_center',
            false,
            false,
            false,
            $stalePrices,
        )->toArray();

        $this->assertNull($withoutPrices['breakdowns']['purchase']['market_value']);
        $this->assertTrue($stalePrices->stale);
        $this->assertSame(70_000_000.0, $withStalePrices['breakdowns']['purchase']['market_value']);
    }

    public function test_project_catalog_excludes_removed_projects(): void
    {
        $projects = (new ProjectCostCatalog)->all();

        $this->assertCount(38, $projects);
        $this->assertArrayNotHasKey('urban_planning', $projects);
        $this->assertArrayNotHasKey('advanced_urban_planning', $projects);
        $this->assertArrayNotHasKey('metropolitan_planning', $projects);
    }

    private static function booleanCombinations(int $count): array
    {
        $combinations = [];

        foreach (range(0, (2 ** $count) - 1) as $mask) {
            $values = [];
            foreach (range(0, $count - 1) as $bit) {
                $values[] = (bool) ($mask & (1 << $bit));
            }
            $combinations["combination-{$mask}"] = $values;
        }

        return $combinations;
    }
}
