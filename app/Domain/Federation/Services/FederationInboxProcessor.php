<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class FederationInboxProcessor
{
    public function __construct(
        private readonly FederationLinkService $links,
        private readonly FederationCoalitionService $coalitions,
        private readonly FederationCapabilityService $capabilities,
        private readonly FederationReceivedWarPlanService $receivedPlans,
        private readonly FederationReconciliationService $reconciliation,
        private readonly FederationOutboxService $outbox,
        private readonly WarPlanPublicationService $publications,
    ) {}

    public function process(FederationInboxMessage $message): void
    {
        $message = DB::transaction(function () use ($message): FederationInboxMessage {
            $locked = FederationInboxMessage::query()->lockForUpdate()->findOrFail($message->id);

            if ($locked->status === InboxStatus::Processed) {
                return $locked;
            }

            if ($locked->status === InboxStatus::Quarantined) {
                return $locked;
            }

            if ($locked->decrypted_payload === null || $locked->expires_at->isPast()) {
                $locked->forceFill([
                    'status' => InboxStatus::Quarantined,
                    'safe_error_code' => FederationErrorCode::MessageExpired->value,
                    'decrypted_payload' => null,
                    'envelope_body' => null,
                    'quarantined_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                return $locked;
            }

            if ($locked->next_attempt_at !== null && $locked->next_attempt_at->isFuture()) {
                throw new FederationProtocolException(FederationErrorCode::TemporaryUnavailable, 503);
            }

            $locked->forceFill([
                'status' => InboxStatus::Processing,
                'processing_attempts' => (int) $locked->processing_attempts + 1,
                'safe_error_code' => null,
                'next_attempt_at' => null,
            ])->save();

            return $locked;
        }, attempts: 5);

        if (in_array($message->status, [InboxStatus::Processed, InboxStatus::Quarantined], true)) {
            return;
        }

        try {
            $payload = StrictJson::decodeObject((string) $message->decrypted_payload);
            $this->dispatch($message, $payload);

            DB::transaction(function () use ($message): void {
                $locked = FederationInboxMessage::query()->lockForUpdate()->findOrFail($message->id);
                $locked->forceFill([
                    'status' => InboxStatus::Processed,
                    'safe_error_code' => null,
                    'processed_at' => now(),
                    'next_attempt_at' => null,
                    'decrypted_payload' => null,
                ])->save();
                FederationLink::query()
                    ->where('remote_installation_id', $locked->sender_installation_id)
                    ->update(['last_contact_at' => now(), 'updated_at' => now()]);

                if (! in_array($locked->message_type, [
                    FederationMessageType::DeliveryReceived,
                    FederationMessageType::ResourceAcknowledged,
                    FederationMessageType::LinkRequest,
                    FederationMessageType::LinkAcceptance,
                    FederationMessageType::LinkActivation,
                ], true)) {
                    $this->queueDeliveryReceipt($locked);
                }
            }, attempts: 5);
        } catch (FederationProtocolException $exception) {
            if ($exception->errorCode === FederationErrorCode::TemporaryUnavailable) {
                if ($this->scheduleRetry($message, $exception->errorCode)) {
                    throw $exception;
                }

                return;
            }

            $this->quarantine($message, $exception->errorCode);
        } catch (InvalidArgumentException|ValidationException) {
            $this->quarantine($message, FederationErrorCode::InvalidEnvelope);
        } catch (Throwable $exception) {
            if (! $this->scheduleRetry($message, FederationErrorCode::TemporaryUnavailable)) {
                return;
            }

            throw $exception;
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function dispatch(FederationInboxMessage $message, array $payload): void
    {
        match ($message->message_type) {
            FederationMessageType::LinkRequest => $this->links->receiveRequest($message, $payload),
            FederationMessageType::LinkAcceptance => $this->links->receiveAcceptance($message, $payload),
            FederationMessageType::LinkActivation => $this->links->receiveActivation($message, $payload),
            FederationMessageType::KeyRotation => $this->links->receiveKeyRotation($message, $payload),
            FederationMessageType::EndpointChange => $this->links->receiveEndpointChange($message, $payload),
            FederationMessageType::LinkSuspensionNotice => $this->links->receiveSuspensionNotice($message, $payload),
            FederationMessageType::CapabilityManifest => $this->receiveCapabilities($message, $payload),
            FederationMessageType::CoalitionInvitation => $this->coalitions->receiveInvitation($message, $payload),
            FederationMessageType::CoalitionProposal => $this->coalitions->receiveProposal($message, $payload),
            FederationMessageType::CoalitionManifest => $this->receiveCoalitionManifest($message, $payload),
            FederationMessageType::CoalitionDissolved => $this->receiveCoalitionDissolution($message, $payload),
            FederationMessageType::ResourcePublished,
            FederationMessageType::ResourceUpdated => $this->receivedPlans->store($message, $payload),
            FederationMessageType::ResourceAcknowledged => $this->receivedPlans->receiveDisposition($message, $payload),
            FederationMessageType::ResourceAccessRevoked => $this->receivedPlans->revoke($message, $payload, true),
            FederationMessageType::ResourceRevoked => $this->receivedPlans->revoke($message, $payload, false),
            FederationMessageType::DeliveryReceived => $this->receivedPlans->receiveDeliveryReceipt($message, $payload),
            FederationMessageType::ReconciliationManifest => $this->reconciliation->receive($message, $payload),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function receiveCapabilities(FederationInboxMessage $message, array $payload): void
    {
        $this->capabilities->receiveManifest($message, $payload);
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();

        foreach ($payload['statements'] as $statement) {
            if (hash_equals($statement['peer_installation_id'], $identity->id)
                && $statement['state'] !== CapabilityState::Active->value) {
                if ($statement['direction'] === 'outbound') {
                    $this->receivedPlans->invalidateCoalition(
                        $statement['coalition_id'],
                        'capability_invalidated',
                        $message->sender_installation_id,
                    );
                } else {
                    $this->publications->revokeForRecipientScope(
                        $statement['coalition_id'],
                        $message->sender_installation_id,
                        'recipient_inbound_capability_invalidated',
                    );
                }
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function receiveCoalitionManifest(FederationInboxMessage $message, array $payload): void
    {
        $this->coalitions->receiveManifest($message, $payload);
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $coalition = FederationCoalition::query()->findOrFail($payload['coalition_id']);
        $membership = $coalition->memberships()->where('installation_id', $identity->id)->first();

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            $this->receivedPlans->invalidateCoalition($coalition->id, 'coalition_membership_removed');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function receiveCoalitionDissolution(FederationInboxMessage $message, array $payload): void
    {
        $this->coalitions->receiveDissolution($message, $payload);
        $this->receivedPlans->invalidateCoalition($payload['coalition_id'], 'coalition_dissolved');
    }

    private function queueDeliveryReceipt(FederationInboxMessage $message): void
    {
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->first();

        if (! $link instanceof FederationLink) {
            return;
        }

        $this->outbox->queue(
            link: $link,
            type: FederationMessageType::DeliveryReceived,
            payload: [
                'original_message_id' => $message->message_id,
                'received_at' => now()->utc()->toIso8601String(),
            ],
            expiresAt: CarbonImmutable::now('UTC')->addDay(),
        );
    }

    private function quarantine(FederationInboxMessage $message, FederationErrorCode $errorCode): void
    {
        FederationInboxMessage::query()->whereKey($message->id)->update([
            'status' => InboxStatus::Quarantined->value,
            'safe_error_code' => $errorCode->value,
            'decrypted_payload' => null,
            'envelope_body' => null,
            'quarantined_at' => now(),
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function scheduleRetry(
        FederationInboxMessage $message,
        FederationErrorCode $errorCode,
    ): bool {
        return DB::transaction(function () use ($message, $errorCode): bool {
            $locked = FederationInboxMessage::query()->lockForUpdate()->findOrFail($message->id);

            if ((int) $locked->processing_attempts >= 10 || $locked->expires_at->isPast()) {
                $locked->forceFill([
                    'status' => InboxStatus::Quarantined,
                    'safe_error_code' => $errorCode->value,
                    'decrypted_payload' => null,
                    'envelope_body' => null,
                    'quarantined_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                return false;
            }

            $delay = min(600, (2 ** min((int) $locked->processing_attempts, 8)) * 15);
            $locked->forceFill([
                'status' => InboxStatus::Accepted,
                'safe_error_code' => $errorCode->value,
                'next_attempt_at' => now()->addSeconds($delay),
            ])->save();

            return true;
        }, attempts: 5);
    }
}
