<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\DeliveryState;
use App\Models\FederationLink;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationPublicationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationPublicationDelivery>
 */
class FederationPublicationDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = json_encode([
            'schema' => 'milcom.war-plan-snapshot/1.0',
            'targets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $recipientInstallationId = (string) Str::ulid();

        return [
            'id' => (string) Str::ulid(),
            'federation_publication_version_id' => FederationPublicationVersion::factory(),
            'federation_link_id' => FederationLink::factory()->active()->state([
                'remote_installation_id' => $recipientInstallationId,
            ]),
            'recipient_installation_id' => $recipientInstallationId,
            'state' => DeliveryState::Pending->value,
            'canonical_payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'payload_bytes' => strlen($payload),
            'outbox_message_id' => null,
            'safe_error_code' => null,
            'transport_accepted_at' => null,
            'validated_at' => null,
            'acknowledged_at' => null,
            'access_revoked_at' => null,
        ];
    }

    public function transportAccepted(): static
    {
        return $this->state(fn (): array => [
            'state' => DeliveryState::TransportAccepted->value,
            'transport_accepted_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'state' => DeliveryState::Pending->value,
            'transport_accepted_at' => null,
            'validated_at' => null,
            'acknowledged_at' => null,
            'access_revoked_at' => null,
        ]);
    }

    public function validated(): static
    {
        return $this->transportAccepted()->state(fn (): array => [
            'state' => DeliveryState::Validated->value,
            'validated_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->validated()->state(fn (): array => [
            'state' => DeliveryState::Accepted->value,
            'acknowledged_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->validated()->state(fn (): array => [
            'state' => DeliveryState::Rejected->value,
            'acknowledged_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => DeliveryState::Revoked->value,
            'access_revoked_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'temporary_unavailable'): static
    {
        return $this->state(fn (): array => [
            'state' => DeliveryState::Failed->value,
            'safe_error_code' => $errorCode,
        ]);
    }
}
