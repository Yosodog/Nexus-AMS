<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationCoalitionInvitation>
 */
class FederationCoalitionInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $installationId = (string) Str::ulid();

        return [
            'id' => (string) Str::ulid(),
            'federation_coalition_id' => FederationCoalition::factory(),
            'federation_link_id' => FederationLink::factory()->state([
                'remote_installation_id' => $installationId,
            ]),
            'installation_id' => $installationId,
            'role' => CoalitionRole::Member->value,
            'direction' => 'outbound',
            'token_hash' => hash('sha256', (string) Str::ulid()),
            'status' => FederationWorkflowStatus::Pending->value,
            'pending_key' => 1,
            'source_message_id' => null,
            'created_by' => null,
            'reviewed_by' => null,
            'expires_at' => now()->addHours(24),
            'reviewed_at' => null,
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
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'role' => CoalitionRole::Admin->value,
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
