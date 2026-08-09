<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\CoalitionStatus;
use App\Models\FederationCoalition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationCoalition>
 */
class FederationCoalitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $manifest = json_encode([
            'protocol_version' => '1.0',
            'roster_revision' => 1,
            'members' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (string) Str::ulid(),
            'name' => fake()->words(2, true).' Federation Coalition',
            'coordinator_installation_id' => (string) Str::ulid(),
            'status' => CoalitionStatus::Active->value,
            'roster_revision' => 1,
            'roster_hash' => hash('sha256', $manifest),
            'canonical_manifest' => $manifest,
            'expires_at' => null,
            'dissolved_at' => null,
            'created_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => CoalitionStatus::Active->value,
            'dissolved_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => CoalitionStatus::Expired->value,
            'expires_at' => now()->subMinute(),
            'dissolved_at' => null,
        ]);
    }

    public function dissolved(): static
    {
        return $this->state(fn (): array => [
            'status' => CoalitionStatus::Dissolved->value,
            'dissolved_at' => now(),
        ]);
    }
}
