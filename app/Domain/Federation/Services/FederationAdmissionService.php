<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\FederationEnvelope;
use App\Domain\Federation\DTO\ProtectedHeader;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Protocol\FederationEnvelopeService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\FederationFingerprint;
use App\Jobs\ProcessFederationInboxMessageJob;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationPeerKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class FederationAdmissionService
{
    public function __construct(private readonly FederationEnvelopeService $envelopes) {}

    public function accept(string $body, bool $handshake): FederationInboxMessage
    {
        $this->assertAvailable($handshake);

        if (strlen($body) > (int) config('federation.limits.outer_request_bytes', 1048576)) {
            throw new FederationProtocolException(FederationErrorCode::PayloadTooLarge, 413);
        }

        try {
            $envelope = FederationEnvelope::fromJson($body);
            $header = ProtectedHeader::fromJson(Base64Url::decode($envelope->protected));
        } catch (FederationProtocolException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        if ($header->messageType->isHandshake() !== $handshake) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        $senderLimitKey = 'federation:sender:'.$header->senderInstallationId;
        $senderLimit = max((int) config('federation.rate_limits.sender_per_minute', 60), 1);

        if (RateLimiter::tooManyAttempts($senderLimitKey, $senderLimit)) {
            throw new FederationProtocolException(FederationErrorCode::RateLimited, 429);
        }

        RateLimiter::hit($senderLimitKey, 60);
        $identity = FederationIdentity::query()->first();

        if (! $identity instanceof FederationIdentity || ! $identity->enabled) {
            throw new FederationProtocolException(FederationErrorCode::TemporaryUnavailable, 503);
        }

        $recipientKey = $identity->keys()
            ->whereKey($header->recipientKeyId)
            ->whereNotIn('status', [FederationKeyStatus::Retired->value, FederationKeyStatus::Compromised->value])
            ->first();

        if ($recipientKey === null) {
            throw new FederationProtocolException(FederationErrorCode::RecipientMismatch, 403);
        }

        $link = FederationLink::query()
            ->where('remote_installation_id', $header->senderInstallationId)
            ->first();
        $senderKey = $this->senderKey($header, $link);
        $this->assertLinkAllows($header->messageType, $link);
        $opened = $this->envelopes->open($envelope, $identity->id, $recipientKey, $senderKey);
        $this->assertHandshakeIdentity($opened->header, $opened->payload);

        try {
            $message = DB::transaction(function () use ($body, $opened): FederationInboxMessage {
                $existing = FederationInboxMessage::query()
                    ->where('sender_installation_id', $opened->header->senderInstallationId)
                    ->where('message_id', $opened->header->messageId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof FederationInboxMessage) {
                    if (hash_equals($existing->payload_hash, $opened->header->payloadDigest)
                        && hash_equals($existing->nonce, $opened->header->nonce)
                        && hash_equals($existing->sender_key_id, $opened->header->senderKeyId)) {
                        return $existing;
                    }

                    throw new FederationProtocolException(FederationErrorCode::MessageReplayed, 409);
                }

                $message = FederationInboxMessage::query()->create([
                    'id' => (string) Str::ulid(),
                    'message_id' => $opened->header->messageId,
                    'sender_installation_id' => $opened->header->senderInstallationId,
                    'recipient_installation_id' => $opened->header->recipientInstallationId,
                    'sender_key_id' => $opened->header->senderKeyId,
                    'recipient_key_id' => $opened->header->recipientKeyId,
                    'nonce' => $opened->header->nonce,
                    'message_type' => $opened->header->messageType,
                    'protocol_version' => $opened->header->protocolVersion,
                    'resource_schema' => $opened->header->resourceSchema,
                    'payload_hash' => $opened->header->payloadDigest,
                    'envelope_body' => $body,
                    'decrypted_payload' => $opened->rawPayload,
                    'status' => InboxStatus::Accepted,
                    'correlation_id' => (string) Str::ulid(),
                    'issued_at' => $opened->header->issuedAt,
                    'expires_at' => $opened->header->expiresAt,
                ]);

                ProcessFederationInboxMessageJob::dispatch($message->id)->afterCommit();

                return $message;
            }, attempts: 5);
        } catch (FederationProtocolException $exception) {
            throw $exception;
        } catch (QueryException) {
            $duplicate = FederationInboxMessage::query()
                ->where('sender_installation_id', $opened->header->senderInstallationId)
                ->where('message_id', $opened->header->messageId)
                ->first();

            if ($duplicate instanceof FederationInboxMessage
                && hash_equals($duplicate->payload_hash, $opened->header->payloadDigest)
                && hash_equals($duplicate->nonce, $opened->header->nonce)) {
                return $duplicate;
            }

            throw new FederationProtocolException(FederationErrorCode::MessageReplayed, 409);
        }

        return $message;
    }

    private function assertAvailable(bool $handshake): void
    {
        if (! (bool) config('federation.enabled', false)
            || ! (bool) config('federation.features.inbound', false)
            || ($handshake && ! (bool) config('federation.features.linking', false))) {
            throw new FederationProtocolException(FederationErrorCode::TemporaryUnavailable, 503);
        }
    }

    private function senderKey(ProtectedHeader $header, ?FederationLink $link): FederationPeerKey|string
    {
        if ($header->messageType === FederationMessageType::LinkRequest && $link === null) {
            if ($header->handshakeSigningPublicKey === null) {
                throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
            }

            return $header->handshakeSigningPublicKey;
        }

        if (! $link instanceof FederationLink) {
            throw new FederationProtocolException(FederationErrorCode::UnknownPeer, 404);
        }

        $key = $link->peerKeys()
            ->where('remote_key_id', $header->senderKeyId)
            ->whereNotIn('status', [FederationKeyStatus::Compromised->value, FederationKeyStatus::Retired->value])
            ->first();

        if (! $key instanceof FederationPeerKey) {
            throw new FederationProtocolException(FederationErrorCode::UnknownPeer, 404);
        }

        return $key;
    }

    private function assertLinkAllows(FederationMessageType $type, ?FederationLink $link): void
    {
        if ($type === FederationMessageType::LinkRequest && $link === null) {
            return;
        }

        if (! $link instanceof FederationLink || $link->status->isTerminal()) {
            throw new FederationProtocolException(FederationErrorCode::UnknownPeer, 404);
        }

        if ($link->status === FederationLinkStatus::Active || $type->isHandshake()) {
            return;
        }

        if ($link->status === FederationLinkStatus::Suspended && $type->isAllowedWhileSuspended()) {
            return;
        }

        throw new FederationProtocolException(FederationErrorCode::LinkInactive, 403);
    }

    /** @param  array<string, mixed>  $payload */
    private function assertHandshakeIdentity(ProtectedHeader $header, array $payload): void
    {
        if ($header->messageType !== FederationMessageType::LinkRequest) {
            if ($header->handshakeSigningPublicKey !== null) {
                throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
            }

            return;
        }

        $sourceKey = $payload['source_key'] ?? null;

        if (! is_array($sourceKey)
            || ! hash_equals((string) $sourceKey['key_id'], $header->senderKeyId)
            || ! hash_equals((string) $sourceKey['signing_public_key'], (string) $header->handshakeSigningPublicKey)
            || ! hash_equals((string) $payload['source_installation_id'], $header->senderInstallationId)) {
            throw new FederationProtocolException(FederationErrorCode::InvalidSignature, 401);
        }

        try {
            $signingFingerprint = FederationFingerprint::signing(Base64Url::decode($sourceKey['signing_public_key']));
            $boxFingerprint = FederationFingerprint::encryption(Base64Url::decode($sourceKey['box_public_key']));
        } catch (Throwable) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        if (! hash_equals($signingFingerprint, $sourceKey['signing_fingerprint'])
            || ! hash_equals($boxFingerprint, $sourceKey['box_fingerprint'])) {
            throw new FederationProtocolException(FederationErrorCode::InvalidSignature, 401);
        }
    }
}
