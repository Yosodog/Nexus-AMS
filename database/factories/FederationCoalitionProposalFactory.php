<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionProposal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationCoalitionProposal>
 */
class FederationCoalitionProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $targetInstallationId = (string) Str::ulid();
        $payload = json_encode([
            'proposal_type' => 'membership.add',
            'target_installation_id' => $targetInstallationId,
            'requested_role' => CoalitionRole::Member->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (string) Str::ulid(),
            'federation_coalition_id' => FederationCoalition::factory(),
            'proposer_installation_id' => (string) Str::ulid(),
            'proposal_type' => 'membership.add',
            'workflow_key' => 'proposal-'.Str::ulid(),
            'target_installation_id' => $targetInstallationId,
            'requested_role' => CoalitionRole::Member->value,
            'base_roster_revision' => 1,
            'payload_hash' => hash('sha256', $payload),
            'canonical_payload' => $payload,
            'status' => FederationWorkflowStatus::Pending->value,
            'pending_key' => 1,
            'reviewed_by' => null,
            'expires_at' => now()->addHours(24),
            'reviewed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => FederationWorkflowStatus::Approved->value,
            'pending_key' => null,
            'reviewed_at' => now(),
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
