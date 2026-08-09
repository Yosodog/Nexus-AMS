<?php

namespace Database\Factories;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Models\FederationLink;
use App\Models\FederationPeerKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationPeerKey>
 */
class FederationPeerKeyFactory extends Factory
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
            'federation_link_id' => FederationLink::factory(),
            'remote_key_id' => (string) Str::ulid(),
            'generation' => 1,
            'status' => FederationKeyStatus::Pending->value,
            'signing_public_key' => $material['signing_public_key'],
            'box_public_key' => $material['box_public_key'],
            'signing_fingerprint' => $material['signing_fingerprint'],
            'box_fingerprint' => $material['box_fingerprint'],
            'approved_at' => null,
            'retired_at' => null,
            'compromised_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Active->value,
            'approved_at' => now(),
            'retired_at' => null,
            'compromised_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Pending->value,
            'approved_at' => null,
            'retired_at' => null,
            'compromised_at' => null,
        ]);
    }

    public function retiring(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Retiring->value,
            'approved_at' => now()->subDays(2),
            'retired_at' => null,
            'compromised_at' => null,
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Retired->value,
            'approved_at' => now()->subDays(3),
            'retired_at' => now(),
            'compromised_at' => null,
        ]);
    }

    public function compromised(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationKeyStatus::Compromised->value,
            'approved_at' => now()->subDay(),
            'retired_at' => null,
            'compromised_at' => now(),
        ]);
    }
}
