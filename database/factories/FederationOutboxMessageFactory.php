<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationOutboxMessage>
 */
class FederationOutboxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipientInstallationId = (string) Str::ulid();

        return [
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'federation_link_id' => FederationLink::factory()->active()->state([
                'remote_installation_id' => $recipientInstallationId,
            ]),
            'sender_installation_id' => (string) Str::ulid(),
            'recipient_installation_id' => $recipientInstallationId,
            'sender_key_id' => (string) Str::ulid(),
            'recipient_key_id' => (string) Str::ulid(),
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => FederationMessageType::DeliveryReceived->value,
            'protocol_version' => '1.0',
            'resource_schema' => null,
            'envelope_body' => '{}',
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'safe_error_code' => null,
            'correlation_id' => (string) Str::ulid(),
            'next_attempt_at' => now(),
            'expires_at' => now()->addHours(24),
            'transport_accepted_at' => null,
            'validated_at' => null,
            'failed_at' => null,
        ];
    }

    public function delivering(): static
    {
        return $this->state(fn (): array => [
            'status' => OutboxStatus::Delivering->value,
            'attempts' => 1,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'safe_error_code' => null,
            'failed_at' => null,
        ]);
    }

    public function transportAccepted(): static
    {
        return $this->state(fn (): array => [
            'status' => OutboxStatus::TransportAccepted->value,
            'attempts' => 1,
            'transport_accepted_at' => now(),
        ]);
    }

    public function validated(): static
    {
        return $this->transportAccepted()->state(fn (): array => [
            'status' => OutboxStatus::Validated->value,
            'validated_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'temporary_unavailable'): static
    {
        return $this->state(fn (): array => [
            'status' => OutboxStatus::Failed->value,
            'attempts' => 1,
            'safe_error_code' => $errorCode,
            'failed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => OutboxStatus::Expired->value,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
