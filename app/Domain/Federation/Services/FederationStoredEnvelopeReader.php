<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\FederationEnvelope;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Protocol\FederationEnvelopeService;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationPeerKey;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FederationStoredEnvelopeReader
{
    public function __construct(private readonly FederationEnvelopeService $envelopes) {}

    /** @return array<string, mixed> */
    public function payload(FederationInboxMessage $message): array
    {
        if ($message->decrypted_payload !== null) {
            return StrictJson::decodeObject((string) $message->decrypted_payload);
        }

        try {
            $identity = FederationIdentity::query()->firstOrFail();
            $recipientKey = $identity->keys()
                ->whereKey($message->recipient_key_id)
                ->whereIn('status', [
                    FederationKeyStatus::Active->value,
                    FederationKeyStatus::Retiring->value,
                ])
                ->firstOrFail();
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->firstOrFail();
            $senderKey = $link->peerKeys()
                ->where('remote_key_id', $message->sender_key_id)
                ->first();

            if (! $senderKey instanceof FederationPeerKey || $message->envelope_body === null) {
                throw new \RuntimeException('Stored federation envelope material is unavailable.');
            }

            $opened = $this->envelopes->open(
                FederationEnvelope::fromJson($message->envelope_body),
                $identity->id,
                $recipientKey,
                $senderKey,
            );

            if (! hash_equals($message->message_id, $opened->header->messageId)
                || ! hash_equals($message->sender_installation_id, $opened->header->senderInstallationId)
                || ! hash_equals($message->recipient_installation_id, $opened->header->recipientInstallationId)
                || ! hash_equals($message->payload_hash, $opened->header->payloadDigest)) {
                throw new \RuntimeException('Stored federation envelope metadata does not match.');
            }

            return $opened->payload;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'invitation' => 'The protected invitation details are no longer available.',
            ]);
        }
    }
}
