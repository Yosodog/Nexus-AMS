<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationReceivedVersion>
 */
class FederationReceivedVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sourceInstallationId = (string) Str::ulid();
        $sourcePublicationId = (string) Str::ulid();
        $payload = json_encode([
            'schema' => 'milcom.war-plan-snapshot/1.0',
            'source_installation_id' => $sourceInstallationId,
            'source_publication_id' => $sourcePublicationId,
            'version' => 1,
            'revision' => 1,
            'targets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (string) Str::ulid(),
            'federation_received_resource_id' => FederationReceivedResource::factory()->state([
                'source_installation_id' => $sourceInstallationId,
                'source_publication_id' => $sourcePublicationId,
            ]),
            'source_installation_id' => $sourceInstallationId,
            'source_publication_id' => $sourcePublicationId,
            'source_version_id' => (string) Str::ulid(),
            'version' => 1,
            'revision' => 1,
            'source_generation' => 1,
            'roster_revision' => 1,
            'schema_version' => '1.0',
            'canonical_payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'payload_bytes' => strlen($payload),
            'disposition' => ReceivedDisposition::Pending->value,
            'import_state' => ImportState::NotRequested->value,
            'reviewed_by' => null,
            'imported_operation_id' => null,
            'import_baseline_generation' => null,
            'missing_target_ids' => null,
            'safe_error_code' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'rejected_at' => null,
            'imported_at' => null,
            'payload_purged_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'disposition' => ReceivedDisposition::Accepted->value,
            'accepted_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'disposition' => ReceivedDisposition::Pending->value,
            'import_state' => ImportState::NotRequested->value,
            'accepted_at' => null,
            'rejected_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'disposition' => ReceivedDisposition::Rejected->value,
            'rejected_at' => now(),
            'canonical_payload' => null,
            'payload_purged_at' => now(),
        ]);
    }

    public function queued(): static
    {
        return $this->accepted()->state(fn (): array => [
            'import_state' => ImportState::Queued->value,
        ]);
    }

    public function imported(): static
    {
        return $this->accepted()->state(fn (): array => [
            'import_state' => ImportState::Imported->value,
            'imported_at' => now(),
        ]);
    }

    public function blockedMissingTargets(array $targetIds = [999001]): static
    {
        return $this->accepted()->state(fn (): array => [
            'import_state' => ImportState::BlockedMissingTargets->value,
            'missing_target_ids' => array_values($targetIds),
        ]);
    }

    public function sourceStale(): static
    {
        return $this->accepted()->state(fn (): array => [
            'import_state' => ImportState::SourceStale->value,
        ]);
    }
}
