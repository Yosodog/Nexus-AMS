<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\ProtectedHeader;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Protocol\FederationEnvelopeService;
use App\Domain\Federation\Support\Base64Url;
use App\Jobs\DeliverFederationEnvelopeJob;
use App\Models\FederationIdentity;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FederationOutboxService
{
    public function __construct(private readonly FederationEnvelopeService $envelopes) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(
        FederationLink $link,
        FederationMessageType $type,
        array $payload,
        CarbonImmutable $expiresAt,
        ?string $resourceSchema = null,
        bool $includeHandshakeKey = false,
    ): FederationOutboxMessage {
        if (! (bool) config('federation.enabled', false)) {
            throw ValidationException::withMessages([
                'federation' => 'Federation is disabled by server configuration.',
            ]);
        }

        if ($link->status->isTerminal()
            || ($link->status !== FederationLinkStatus::Active
                && ! $type->isHandshake()
                && ! ($link->status === FederationLinkStatus::Suspended && $type->isAllowedWhileSuspended()))) {
            throw ValidationException::withMessages([
                'federation' => 'The peer link does not allow this message type in its current state.',
            ]);
        }

        $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();
        $senderKey = $identity->activeKey;
        $recipientKey = $link->peerKeys()
            ->where('status', FederationKeyStatus::Active->value)
            ->latest('generation')
            ->first();

        if (! $identity->enabled || $senderKey === null || $recipientKey === null) {
            throw ValidationException::withMessages([
                'federation' => 'Federation key material is not ready for delivery.',
            ]);
        }

        $envelope = $this->envelopes->seal(
            type: $type,
            payload: $payload,
            senderInstallationId: $identity->id,
            senderKey: $senderKey,
            recipientInstallationId: $link->remote_installation_id,
            recipientKeyId: $recipientKey->remote_key_id,
            recipientBoxPublicKey: $recipientKey->box_public_key,
            expiresAt: $expiresAt,
            resourceSchema: $resourceSchema,
            includeHandshakeKey: $includeHandshakeKey,
        );
        $header = ProtectedHeader::fromJson(Base64Url::decode($envelope->protected));
        $message = FederationOutboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => $header->messageId,
            'federation_link_id' => $link->id,
            'sender_installation_id' => $identity->id,
            'recipient_installation_id' => $link->remote_installation_id,
            'sender_key_id' => $senderKey->id,
            'recipient_key_id' => $recipientKey->remote_key_id,
            'nonce' => $header->nonce,
            'message_type' => $type,
            'protocol_version' => $header->protocolVersion,
            'resource_schema' => $resourceSchema,
            'envelope_body' => $envelope->toJson(),
            'status' => OutboxStatus::Pending,
            'correlation_id' => (string) Str::ulid(),
            'next_attempt_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        DeliverFederationEnvelopeJob::dispatch($message->id)->afterCommit();

        return $message;
    }
}
