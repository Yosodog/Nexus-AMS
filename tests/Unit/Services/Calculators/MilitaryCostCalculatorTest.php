<?php

namespace Tests\Unit\Services\Calculators;

use App\DataTransferObjects\MarketPriceSet;
use App\Services\Calculators\MilitaryCostCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class MilitaryCostCalculatorTest extends UnitTestCase
{
    private MilitaryCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new MilitaryCostCalculator;
    }

    public function test_golden_fixture_includes_purchase_upkeep_research_and_resources(): void
    {
        $result = $this->calculator->calculate(
            $this->quantities([
                'soldiers' => 1_000,
                'tanks' => 10,
                'aircraft' => 2,
                'ships' => 1,
                'missiles' => 1,
                'nukes' => 1,
                'spies' => 5,
            ]),
            $this->research([
                'ground_cost' => 2,
                'ground_capacity' => 1,
                'air_cost' => 3,
                'air_capacity' => 2,
                'naval_cost' => 4,
                'naval_capacity' => 1,
            ]),
            true,
            true,
            true,
            true,
            $this->prices(1_000),
        )->toArray();

        $this->assertSame(2_211_080.0, $result['breakdowns']['purchase']['money']);
        $this->assertSame(32.8, $result['breakdowns']['purchase']['resources']['steel']);
        $this->assertSame(1_168.8, $result['breakdowns']['purchase']['resources']['aluminum']);
        $this->assertSame(95_817.06, $result['breakdowns']['daily_upkeep']['money']);
        $this->assertSame(1.63, $result['breakdowns']['daily_upkeep']['resources']['food']);
        $this->assertSame(97_446.53, $result['breakdowns']['daily_upkeep']['market_value']);
    }

    public function test_zero_quantities_return_zero_purchase_and_upkeep(): void
    {
        $result = $this->calculator->calculate(
            $this->quantities(),
            $this->research(),
            false,
            false,
            false,
            false,
        )->toArray();

        $this->assertSame(0.0, $result['breakdowns']['purchase']['money']);
        $this->assertSame([], $result['breakdowns']['purchase']['resources']);
        $this->assertSame(0.0, $result['breakdowns']['daily_upkeep']['money']);
        $this->assertSame([], $result['breakdowns']['daily_upkeep']['resources']);
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            $this->quantities(['soldiers' => -1]),
            $this->research(),
            false,
            false,
            false,
            false,
        );
    }

    public function test_invalid_research_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            $this->quantities(),
            $this->research(['ground_cost' => 21]),
            false,
            false,
            false,
            false,
        );
    }

    #[DataProvider('upkeepModifierCombinations')]
    public function test_every_upkeep_modifier_combination(
        bool $wartime,
        bool $imperialism,
        bool $governmentSupportAgency,
        bool $bureauOfDomesticAffairs,
    ): void {
        $factor = 1
            - ($imperialism ? 0.05 : 0)
            - ($imperialism && $governmentSupportAgency ? 0.025 : 0)
            - ($imperialism && $bureauOfDomesticAffairs ? 0.0125 : 0);
        $result = $this->calculator->calculate(
            $this->quantities(['aircraft' => 1]),
            $this->research(),
            $wartime,
            $imperialism,
            $governmentSupportAgency,
            $bureauOfDomesticAffairs,
        )->toArray();

        $baseUpkeep = $wartime ? 1_000 : 750;

        $this->assertSame(round($baseUpkeep * $factor, 2), $result['breakdowns']['daily_upkeep']['money']);
    }

    public static function upkeepModifierCombinations(): array
    {
        $combinations = [];
        foreach (range(0, 15) as $mask) {
            $combinations["combination-{$mask}"] = [
                (bool) ($mask & 1),
                (bool) ($mask & 2),
                (bool) ($mask & 4),
                (bool) ($mask & 8),
            ];
        }

        return $combinations;
    }

    public function test_wartime_and_peacetime_upkeep_rates_are_distinct(): void
    {
        $peace = $this->calculator->calculate(
            $this->quantities(['aircraft' => 1]),
            $this->research(),
            false,
            false,
            false,
            false,
        )->toArray();
        $war = $this->calculator->calculate(
            $this->quantities(['aircraft' => 1]),
            $this->research(),
            true,
            false,
            false,
            false,
        )->toArray();

        $this->assertSame(750.0, $peace['breakdowns']['daily_upkeep']['money']);
        $this->assertSame(1_000.0, $war['breakdowns']['daily_upkeep']['money']);
    }

    public function test_money_and_resource_rounding_and_market_availability(): void
    {
        $withoutPrices = $this->calculator->calculate(
            $this->quantities(['tanks' => 1]),
            $this->research(['ground_cost' => 1]),
            false,
            false,
            false,
            false,
        )->toArray();
        $stalePrices = $this->prices(1_234.567, true);
        $withStalePrices = $this->calculator->calculate(
            $this->quantities(['tanks' => 1]),
            $this->research(['ground_cost' => 1]),
            false,
            false,
            false,
            false,
            $stalePrices,
        )->toArray();

        $this->assertSame(59.0, $withoutPrices['breakdowns']['purchase']['money']);
        $this->assertSame(0.49, $withoutPrices['breakdowns']['purchase']['resources']['steel']);
        $this->assertNull($withoutPrices['breakdowns']['purchase']['market_value']);
        $this->assertTrue($stalePrices->stale);
        $this->assertSame(663.94, $withStalePrices['breakdowns']['purchase']['market_value']);
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function quantities(array $overrides = []): array
    {
        return array_replace(array_fill_keys(MilitaryCostCalculator::UNITS, 0), $overrides);
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function research(array $overrides = []): array
    {
        return array_replace(array_fill_keys(MilitaryCostCalculator::RESEARCH_FIELDS, 0), $overrides);
    }

    private function prices(float $price, bool $stale = false): MarketPriceSet
    {
        $prices = array_fill_keys([
            'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        ], $price);

        return new MarketPriceSet(
            acquisitionPrices: $prices,
            liquidationPrices: $prices,
            calculatedAt: CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            stale: $stale,
        );
    }
}
