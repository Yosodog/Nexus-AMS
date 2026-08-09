<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationLinkInvitation>
 */
class FederationLinkInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $peerInstallationId = (string) Str::ulid();
        $peerOrigin = 'https://'.fake()->unique()->domainName();

        return [
            'id' => (string) Str::ulid(),
            'federation_link_id' => FederationLink::factory()->state([
                'remote_installation_id' => $peerInstallationId,
            ]),
            'direction' => 'outbound',
            'peer_origin' => $peerOrigin,
            'peer_installation_id' => $peerInstallationId,
            'token_hash' => hash('sha256', (string) Str::ulid()),
            'status' => FederationWorkflowStatus::Pending->value,
            'pending_key' => 1,
            'discovery_snapshot' => [
                'installation_id' => $peerInstallationId,
                'origin' => $peerOrigin,
                'display_name' => 'Peer Nexus',
                'ownership_epoch' => 1,
                'current_key' => [
                    'key_id' => (string) Str::ulid(),
                    'generation' => 1,
                    'signing_public_key' => 'test-signing-public-key',
                    'box_public_key' => 'test-box-public-key',
                    'signing_fingerprint' => str_repeat('A', 64),
                    'box_fingerprint' => str_repeat('B', 64),
                ],
            ],
            'source_message_id' => null,
            'created_by' => null,
            'reviewed_by' => null,
            'expires_at' => now()->addHours(24),
            'reviewed_at' => null,
            'consumed_at' => null,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => 'inbound',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Pending->value,
            'pending_key' => 1,
            'reviewed_at' => null,
            'consumed_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Approved->value,
            'pending_key' => null,
            'reviewed_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Completed->value,
            'pending_key' => null,
            'reviewed_at' => now()->subHour(),
            'consumed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Rejected->value,
            'pending_key' => null,
            'reviewed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Expired->value,
            'pending_key' => null,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
