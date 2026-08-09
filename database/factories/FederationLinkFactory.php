<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Models\FederationLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationLink>
 */
class FederationLinkFactory extends Factory
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
            'remote_installation_id' => (string) Str::ulid(),
            'remote_display_name' => fake()->company().' Nexus',
            'approved_origin' => 'https://'.fake()->unique()->domainName(),
            'status' => FederationLinkStatus::PendingRemote->value,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => null,
            'negotiated_resource_versions' => null,
            'suspension_reason_code' => null,
            'active_at' => null,
            'suspended_at' => null,
            'revoked_at' => null,
            'expired_at' => null,
            'last_contact_at' => null,
            'last_reconciled_at' => null,
        ];
    }

    public function pendingLocal(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::PendingLocal->value,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::PendingRemote->value,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::Active->value,
            'negotiated_protocol_version' => '1.0',
            'negotiated_resource_versions' => [
                'milcom.war-plan-snapshot' => ['1.0'],
            ],
            'active_at' => now(),
            'suspended_at' => null,
            'revoked_at' => null,
            'expired_at' => null,
            'last_contact_at' => now(),
        ]);
    }

    public function suspended(string $reason = 'test_suspension'): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::Suspended->value,
            'suspension_reason_code' => $reason,
            'suspended_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::Revoked->value,
            'revoked_at' => now(),
            'suspended_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationLinkStatus::Expired->value,
            'expired_at' => now(),
            'suspended_at' => null,
        ]);
    }
}
