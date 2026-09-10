<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\StoreOffshoreRequest;
use App\Http\Requests\Admin\UpdateOffshoreRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OffshoreRequestTest extends TestCase
{
    #[DataProvider('invalidApiKeyProvider')]
    public function test_store_requires_an_exact_twenty_character_api_key(string $apiKey): void
    {
        $validator = Validator::make(
            ['api_key' => $apiKey],
            ['api_key' => (new StoreOffshoreRequest)->rules()['api_key']],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('api_key'));
    }

    public function test_store_accepts_a_twenty_character_api_key(): void
    {
        $validator = Validator::make(
            ['api_key' => str_repeat('a', 20)],
            ['api_key' => (new StoreOffshoreRequest)->rules()['api_key']],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_update_only_validates_the_api_key_when_a_replacement_is_supplied(): void
    {
        $rules = ['api_key' => (new UpdateOffshoreRequest)->rules()['api_key']];

        $this->assertFalse(Validator::make([], $rules)->fails());
        $this->assertFalse(Validator::make(['api_key' => null], $rules)->fails());
        $this->assertFalse(Validator::make(['api_key' => str_repeat('a', 20)], $rules)->fails());
        $this->assertTrue(Validator::make(['api_key' => str_repeat('a', 19)], $rules)->fails());
        $this->assertTrue(Validator::make(['api_key' => str_repeat('a', 21)], $rules)->fails());
    }

    public function test_direct_deposit_tax_ids_are_required_and_must_differ_when_enabled(): void
    {
        $request = new StoreOffshoreRequest;
        $request->merge(['direct_deposit_enabled' => true]);
        $directDepositRules = $this->directDepositRules($request);

        $this->assertTrue(Validator::make([
            'direct_deposit_enabled' => true,
        ], $directDepositRules)->fails());

        $this->assertTrue(Validator::make([
            'direct_deposit_enabled' => true,
            'direct_deposit_tax_id' => 100,
            'direct_deposit_fallback_tax_id' => 100,
        ], $directDepositRules)->fails());

        $this->assertFalse(Validator::make([
            'direct_deposit_enabled' => true,
            'direct_deposit_tax_id' => 100,
            'direct_deposit_fallback_tax_id' => 101,
        ], $directDepositRules)->fails());
    }

    public function test_tax_ids_are_optional_when_direct_deposit_is_disabled(): void
    {
        $request = new StoreOffshoreRequest;
        $request->merge(['direct_deposit_enabled' => false]);
        $directDepositRules = $this->directDepositRules($request);

        $this->assertFalse(Validator::make([
            'direct_deposit_enabled' => false,
        ], $directDepositRules)->fails());
    }

    /**
     * @return array<string, mixed>
     */
    private function directDepositRules(StoreOffshoreRequest $request): array
    {
        return collect($request->rules())->only([
            'direct_deposit_enabled',
            'direct_deposit_tax_id',
            'direct_deposit_fallback_tax_id',
        ])->all();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidApiKeyProvider(): array
    {
        return [
            'nineteen characters' => [str_repeat('a', 19)],
            'twenty-one characters' => [str_repeat('a', 21)],
            'twenty non-ASCII characters' => [str_repeat('é', 20)],
        ];
    }
}
