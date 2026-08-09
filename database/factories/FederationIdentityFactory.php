<?php

namespace Database\Factories;

use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationIdentity>
 */
class FederationIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'singleton_key' => 1,
            'origin' => 'https://'.fake()->unique()->domainName(),
            'display_name' => fake()->company().' Nexus',
            'ownership_epoch' => 1,
            'enabled' => false,
            'enabled_at' => null,
            'disabled_at' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => true,
            'enabled_at' => now(),
            'disabled_at' => null,
        ])->afterCreating(function (FederationIdentity $identity): void {
            if (! $identity->keys()->where('active_key', 1)->exists()) {
                FederationIdentityKey::factory()
                    ->for($identity, 'identity')
                    ->active()
                    ->create();
            }
        });
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
            'enabled_at' => null,
            'disabled_at' => now(),
        ]);
    }

    public function withActiveKey(): static
    {
        return $this->enabled();
    }
}
