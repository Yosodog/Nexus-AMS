<?php

namespace Tests\Unit\DataTransferObjects;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityPricingUnavailable;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class MarketPriceSetTest extends UnitTestCase
{
    #[Test]
    public function it_values_outputs_at_liquidation_and_inputs_at_acquisition_prices(): void
    {
        $prices = new MarketPriceSet(
            acquisitionPrices: ['coal' => 120.0],
            liquidationPrices: ['coal' => 90.0],
        );

        $this->assertSame(900.0, $prices->convert(['coal' => 10.0]));
        $this->assertSame(-1200.0, $prices->convert(['coal' => -10.0]));
        $this->assertSame(50.0, $prices->convert(['money' => 50.0]));
    }

    #[Test]
    public function it_rejects_missing_prices_instead_of_silently_valuing_them_at_zero(): void
    {
        $this->expectException(ProfitabilityPricingUnavailable::class);

        (new MarketPriceSet([], []))->convert(['uranium' => -1.0]);
    }
}
