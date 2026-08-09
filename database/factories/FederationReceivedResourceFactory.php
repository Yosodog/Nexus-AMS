<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Models\FederationLink;
use App\Models\FederationReceivedResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationReceivedResource>
 */
class FederationReceivedResourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sourceInstallationId = (string) Str::ulid();

        return [
            'id' => (string) Str::ulid(),
            'federation_link_id' => FederationLink::factory()->active()->state([
                'remote_installation_id' => $sourceInstallationId,
            ]),
            'source_installation_id' => $sourceInstallationId,
            'source_publication_id' => (string) Str::ulid(),
            'coalition_id' => (string) Str::ulid(),
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'state' => ReceivedResourceState::PendingReview->value,
            'current_version' => 0,
            'current_revision' => 0,
            'expires_at' => now()->addDays(7),
            'revoked_at' => null,
            'payload_purged_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'state' => ReceivedResourceState::Accepted->value,
            'current_version' => 1,
            'current_revision' => 1,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'state' => ReceivedResourceState::PendingReview->value,
            'current_version' => 0,
            'current_revision' => 0,
            'revoked_at' => null,
            'payload_purged_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'state' => ReceivedResourceState::Rejected->value,
            'current_version' => 1,
            'current_revision' => 1,
            'payload_purged_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => ReceivedResourceState::Revoked->value,
            'current_version' => 1,
            'current_revision' => 2,
            'revoked_at' => now(),
            'payload_purged_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'state' => ReceivedResourceState::Expired->value,
            'current_version' => 1,
            'current_revision' => 1,
            'expires_at' => now()->subMinute(),
            'payload_purged_at' => now(),
        ]);
    }
}
