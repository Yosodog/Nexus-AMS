<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantCallbackStatus;
use App\Enums\TenantCallbackType;
use App\Models\TenantCallbackDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantCallbackDelivery>
 */
final class TenantCallbackDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'callback_id' => (string) Str::ulid(),
            'tenant_id' => '01JZ0000000000000000000000',
            'event_type' => TenantCallbackType::BootstrapRedeemed,
            'subject_key' => hash('sha256', fake()->uuid()),
            'release_id' => 'test-release',
            'payload' => [
                'bootstrap_redemption_id' => fake()->numberBetween(1, 10_000),
                'cloud_user_id' => (string) Str::ulid(),
                'local_user_id' => fake()->numberBetween(1, 10_000),
                'mode' => 'created',
                'nation_id' => fake()->numberBetween(1, 10_000_000),
            ],
            'status' => TenantCallbackStatus::Pending,
            'attempt_count' => 0,
            'occurred_at' => now(),
        ];
    }
}
