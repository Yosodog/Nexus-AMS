<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationCapability>
 */
class FederationCapabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statement = json_encode([
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'direction' => CapabilityDirection::Outbound->value,
            'revision' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (string) Str::ulid(),
            'issuer_installation_id' => (string) Str::ulid(),
            'peer_installation_id' => (string) Str::ulid(),
            'federation_coalition_id' => FederationCoalition::factory(),
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'direction' => CapabilityDirection::Outbound->value,
            'revision' => 1,
            'state' => CapabilityState::Active->value,
            'is_local' => true,
            'statement_hash' => hash('sha256', $statement),
            'canonical_statement' => $statement,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function inbound(): static
    {
        return $this->direction(CapabilityDirection::Inbound, false);
    }

    public function outbound(): static
    {
        return $this->direction(CapabilityDirection::Outbound);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'state' => CapabilityState::Active->value,
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => CapabilityState::Revoked->value,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'state' => CapabilityState::Expired->value,
            'expires_at' => now()->subMinute(),
        ]);
    }

    private function direction(CapabilityDirection $direction, bool $isLocal = true): static
    {
        return $this->state(function (array $attributes) use ($direction, $isLocal): array {
            $statement = json_encode([
                'resource_type' => $attributes['resource_type'] ?? FederationResourceType::WarPlanSnapshot->value,
                'direction' => $direction->value,
                'revision' => (int) ($attributes['revision'] ?? 1),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return [
                'direction' => $direction->value,
                'is_local' => $isLocal,
                'canonical_statement' => $statement,
                'statement_hash' => hash('sha256', $statement),
            ];
        });
    }
}
