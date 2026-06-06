<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UpdateMMRAssistantSettingsRequest;
use App\Models\MMRSetting;
use App\Models\TradePrice;
use App\Services\TradePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MMRAssistantSurchargeLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mmr_assistant_settings_validation_accepts_supported_boundaries(): void
    {
        $validator = $this->validatorFor([
            'enabled' => '1',
            'resources' => [
                'coal' => ['enabled' => '1', 'surcharge_pct' => (string) MMRSetting::MIN_SURCHARGE_PCT],
                'oil' => ['surcharge_pct' => (string) MMRSetting::MAX_SURCHARGE_PCT],
            ],
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_mmr_assistant_settings_validation_rejects_unsafe_surcharges_and_resources(): void
    {
        foreach ($this->invalidSettingsPayloads() as $label => [$payload, $field]) {
            $validator = $this->validatorFor($payload);

            $this->assertTrue($validator->fails(), $label);
            $this->assertArrayHasKey($field, $validator->errors()->toArray(), $label);
        }
    }

    public function test_trade_prices_clamp_stale_surcharge_settings_before_pricing(): void
    {
        $this->createTradePrice();

        MMRSetting::query()->create([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => -50,
        ]);
        MMRSetting::query()->create([
            'resource' => 'oil',
            'enabled' => true,
            'surcharge_pct' => 500,
        ]);

        $prices = app(TradePriceService::class)->get24hAverageWithSurcharge();

        $this->assertSame(100.0, $prices['coal']);
        $this->assertSame(200.0, $prices['oil']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    private function invalidSettingsPayloads(): array
    {
        return [
            'negative surcharge' => [
                ['resources' => ['coal' => ['surcharge_pct' => '-0.01']]],
                'resources.coal.surcharge_pct',
            ],
            'excessive surcharge' => [
                ['resources' => ['coal' => ['surcharge_pct' => '100.01']]],
                'resources.coal.surcharge_pct',
            ],
            'extra precision' => [
                ['resources' => ['coal' => ['surcharge_pct' => '5.123']]],
                'resources.coal.surcharge_pct',
            ],
            'unsupported resource' => [
                ['resources' => ['credits' => ['surcharge_pct' => '5.00']]],
                'resources.credits',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatorFor(array $payload): \Illuminate\Validation\Validator
    {
        $request = new UpdateMMRAssistantSettingsRequest;
        $request->merge($payload);

        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    private function createTradePrice(): void
    {
        TradePrice::query()->create([
            'date' => now()->toDateString(),
            'coal' => 100,
            'oil' => 100,
            'uranium' => 100,
            'iron' => 100,
            'bauxite' => 100,
            'lead' => 100,
            'gasoline' => 100,
            'munitions' => 100,
            'steel' => 100,
            'aluminum' => 100,
            'food' => 100,
            'credits' => 100,
        ]);
    }
}
