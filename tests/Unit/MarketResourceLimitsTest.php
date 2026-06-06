<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UpdateMarketResourceRequest;
use App\Models\MarketResource;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\MarketService;
use App\Services\TradePriceService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class MarketResourceLimitsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_market_resource_update_validation_accepts_supported_boundaries(): void
    {
        $validator = Validator::make([
            'adjustment_percent' => (string) MarketResource::MIN_ADJUSTMENT_PERCENT,
            'buy_cap_remaining' => number_format(MarketResource::MAX_BUY_CAP_REMAINING, 2, '.', ''),
        ], (new UpdateMarketResourceRequest)->rules());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_market_resource_update_validation_rejects_unbounded_values(): void
    {
        $rules = (new UpdateMarketResourceRequest)->rules();

        foreach ($this->invalidMarketResourcePayloads() as $label => [$payload, $field]) {
            $validator = Validator::make($payload, $rules);

            $this->assertTrue($validator->fails(), $label);
            $this->assertArrayHasKey($field, $validator->errors()->toArray(), $label);
        }
    }

    public function test_compute_final_price_rejects_unsafe_adjustment_percentages(): void
    {
        $service = $this->marketService();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Adjustment percent must be between -99.99 and 100.00.');

        $service->computeFinalPrice('coal', 100.01, 200);
    }

    public function test_compute_final_price_allows_supported_adjustment_boundaries(): void
    {
        $service = $this->marketService();

        $this->assertSame(400.0, $service->computeFinalPrice('coal', MarketResource::MAX_ADJUSTMENT_PERCENT, 200));
        $this->assertEqualsWithDelta(0.02, $service->computeFinalPrice('coal', MarketResource::MIN_ADJUSTMENT_PERCENT, 200), 0.00001);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    private function invalidMarketResourcePayloads(): array
    {
        return [
            'adjustment below minimum' => [
                ['adjustment_percent' => '-100.00', 'buy_cap_remaining' => '100.00'],
                'adjustment_percent',
            ],
            'adjustment above maximum' => [
                ['adjustment_percent' => '100.01', 'buy_cap_remaining' => '100.00'],
                'adjustment_percent',
            ],
            'adjustment extra precision' => [
                ['adjustment_percent' => '10.123', 'buy_cap_remaining' => '100.00'],
                'adjustment_percent',
            ],
            'buy cap below minimum' => [
                ['adjustment_percent' => '10.00', 'buy_cap_remaining' => '-0.01'],
                'buy_cap_remaining',
            ],
            'buy cap above maximum' => [
                ['adjustment_percent' => '10.00', 'buy_cap_remaining' => '100000000.01'],
                'buy_cap_remaining',
            ],
            'buy cap extra precision' => [
                ['adjustment_percent' => '10.00', 'buy_cap_remaining' => '100.123'],
                'buy_cap_remaining',
            ],
        ];
    }

    private function marketService(): MarketService
    {
        return new MarketService(
            Mockery::mock(TradePriceService::class),
            Mockery::mock(AllianceMembershipService::class),
            Mockery::mock(AuditLogger::class)
        );
    }
}
