<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UpdateDirectDepositBracketsRequest;
use App\Models\DirectDepositTaxBracket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DirectDepositBracketRateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_deposit_bracket_update_validation_accepts_supported_boundaries(): void
    {
        $bracket = $this->createBracket();

        $validator = $this->validatorFor([
            'selected' => [$bracket->id],
            'rates' => [
                'money' => (string) DirectDepositTaxBracket::MIN_TAX_RATE,
                'coal' => (string) DirectDepositTaxBracket::MAX_TAX_RATE,
            ],
        ]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_direct_deposit_bracket_update_validation_rejects_unsafe_rates_and_keys(): void
    {
        $bracket = $this->createBracket();

        foreach ($this->invalidRatePayloads($bracket->id) as $label => [$payload, $field]) {
            $validator = $this->validatorFor($payload);

            $this->assertTrue($validator->fails(), $label);
            $this->assertArrayHasKey($field, $validator->errors()->toArray(), $label);
        }
    }

    public function test_direct_deposit_tax_rate_normalizer_clamps_and_rounds_rates(): void
    {
        $this->assertSame(0.0, DirectDepositTaxBracket::normalizeTaxRate(-25));
        $this->assertSame(100.0, DirectDepositTaxBracket::normalizeTaxRate(250));
        $this->assertSame(12.35, DirectDepositTaxBracket::normalizeTaxRate(12.345));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    private function invalidRatePayloads(int $bracketId): array
    {
        return [
            'negative rate' => [
                ['selected' => [$bracketId], 'rates' => ['money' => '-0.01']],
                'rates.money',
            ],
            'excessive rate' => [
                ['selected' => [$bracketId], 'rates' => ['money' => '100.01']],
                'rates.money',
            ],
            'extra precision' => [
                ['selected' => [$bracketId], 'rates' => ['money' => '10.123']],
                'rates.money',
            ],
            'unsupported resource key' => [
                ['selected' => [$bracketId], 'rates' => ['credits' => '10.00']],
                'rates',
            ],
            'missing rates' => [
                ['selected' => [$bracketId], 'rates' => []],
                'rates',
            ],
            'duplicate selected brackets' => [
                ['selected' => [$bracketId, $bracketId], 'rates' => ['money' => '10.00']],
                'selected.1',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatorFor(array $payload): \Illuminate\Validation\Validator
    {
        $request = new UpdateDirectDepositBracketsRequest;
        $request->merge($payload);

        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    private function createBracket(): DirectDepositTaxBracket
    {
        return DirectDepositTaxBracket::query()->create([
            'city_number' => 0,
            ...array_fill_keys(DirectDepositTaxBracket::rateFields(), 10),
        ]);
    }
}
