<?php

namespace Database\Factories;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationIdentityKey>
 */
class FederationIdentityKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $material = app(FederationCryptography::class)->generateKeyMaterial();

        return [
            'id' => (string) Str::ulid(),
            'identity_id' => FederationIdentity::factory()->disabled(),
            'generation' => 1,
            'status' => FederationKeyStatus::Pending->value,
            'active_key' => null,
            ...$material,
            'rotation_statement' => null,
            'activated_at' => null,
            'retiring_at' => null,
            'retired_at' => null,
            'compromised_at' => null,
            'purge_after' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Active->value,
            'active_key' => 1,
            'activated_at' => now(),
            'retiring_at' => null,
            'retired_at' => null,
            'compromised_at' => null,
        ])->afterCreating(function (FederationIdentityKey $key): void {
            FederationIdentity::query()
                ->whereKey($key->identity_id)
                ->where('enabled', false)
                ->update([
                    'enabled' => true,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                ]);
        });
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Pending->value,
            'active_key' => null,
            'activated_at' => null,
            'retiring_at' => null,
            'retired_at' => null,
            'compromised_at' => null,
            'purge_after' => null,
        ]);
    }

    public function retiring(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Retiring->value,
            'active_key' => null,
            'activated_at' => now()->subDay(),
            'retiring_at' => now(),
            'retired_at' => null,
            'compromised_at' => null,
            'purge_after' => now()->addDays(30),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Retired->value,
            'active_key' => null,
            'activated_at' => now()->subDays(2),
            'retiring_at' => now()->subDay(),
            'retired_at' => now(),
            'compromised_at' => null,
            'purge_after' => now(),
        ]);
    }

    public function compromised(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Compromised->value,
            'active_key' => null,
            'activated_at' => now()->subDay(),
            'retiring_at' => null,
            'retired_at' => null,
            'compromised_at' => now(),
            'purge_after' => null,
        ]);
    }
}
