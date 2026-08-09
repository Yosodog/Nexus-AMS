<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Models\FederationInboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationInboxMessage>
 */
class FederationInboxMessageFactory extends Factory
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
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => (string) Str::ulid(),
            'recipient_installation_id' => (string) Str::ulid(),
            'sender_key_id' => (string) Str::ulid(),
            'recipient_key_id' => (string) Str::ulid(),
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => FederationMessageType::DeliveryReceived->value,
            'protocol_version' => '1.0',
            'resource_schema' => null,
            'payload_hash' => hash('sha256', '{"message":"test"}'),
            'envelope_body' => '{}',
            'decrypted_payload' => '{"message":"test"}',
            'status' => InboxStatus::Accepted->value,
            'safe_error_code' => null,
            'correlation_id' => (string) Str::ulid(),
            'issued_at' => now(),
            'expires_at' => now()->addHours(24),
            'processed_at' => null,
            'quarantined_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => InboxStatus::Processing->value,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => InboxStatus::Accepted->value,
            'processed_at' => null,
            'quarantined_at' => null,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (): array => [
            'status' => InboxStatus::Processed->value,
            'processed_at' => now(),
        ]);
    }

    public function quarantined(string $errorCode = 'invalid_envelope'): static
    {
        return $this->state(fn (): array => [
            'status' => InboxStatus::Quarantined->value,
            'safe_error_code' => $errorCode,
            'quarantined_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'issued_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
        ]);
    }
}
