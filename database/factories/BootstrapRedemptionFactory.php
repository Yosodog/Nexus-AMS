<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BootstrapRedemptionMode;
use App\Enums\TenantBootstrapAction;
use App\Models\BootstrapRedemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BootstrapRedemption>
 */
final class BootstrapRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', random_bytes(32)),
            'tenant_id' => '01JZ0000000000000000000000',
            'cloud_user_id' => (string) Str::ulid(),
            'action' => TenantBootstrapAction::InitialAdmin,
            'release_id' => 'test-release',
            'alliance_id' => fake()->numberBetween(1, 1_000_000),
            'nation_id' => fake()->numberBetween(1, 10_000_000),
            'local_user_id' => User::factory(),
            'mode' => BootstrapRedemptionMode::Created,
            'claims_digest' => hash('sha256', random_bytes(32)),
            'issued_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'redeemed_at' => now(),
        ];
    }
}
