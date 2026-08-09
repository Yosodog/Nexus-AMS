<?php

namespace App\Jobs;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\Enums\DeliveryState;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationIdentity;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPublicationDelivery;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DeliverFederationEnvelopeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 30;

    public function __construct(public readonly string $outboxMessageId) {}

    public function handle(FederationTransport $transport): void
    {
        $message = FederationOutboxMessage::query()->with('link')->find($this->outboxMessageId);

        if (! $message instanceof FederationOutboxMessage
            || in_array($message->status, [
                OutboxStatus::TransportAccepted,
                OutboxStatus::Validated,
                OutboxStatus::Failed,
                OutboxStatus::Expired,
            ], true)) {
            return;
        }

        if ($message->expires_at->isPast()) {
            $message->forceFill([
                'status' => OutboxStatus::Expired,
                'safe_error_code' => FederationErrorCode::MessageExpired->value,
                'failed_at' => now(),
            ])->save();
            $this->failDelivery($message, FederationErrorCode::MessageExpired);

            return;
        }

        if (! $this->featureGateAllows($message->message_type)) {
            $message->forceFill([
                'status' => OutboxStatus::Pending,
                'safe_error_code' => FederationErrorCode::TemporaryUnavailable->value,
                'next_attempt_at' => now()->addMinutes(15),
            ])->save();

            return;
        }

        if (! FederationIdentity::query()->where('enabled', true)->exists()) {
            $message->forceFill([
                'status' => OutboxStatus::Pending,
                'safe_error_code' => FederationErrorCode::TemporaryUnavailable->value,
                'next_attempt_at' => now()->addMinutes(15),
            ])->save();

            return;
        }

        if ($message->link === null || $message->link->status->isTerminal()) {
            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'safe_error_code' => FederationErrorCode::LinkInactive->value,
                'failed_at' => now(),
            ])->save();
            $this->failDelivery($message, FederationErrorCode::LinkInactive);

            return;
        }

        if ($message->link->status !== FederationLinkStatus::Active
            && ! $message->message_type->isHandshake()
            && ! ($message->link->status === FederationLinkStatus::Suspended
                && $message->message_type->isAllowedWhileSuspended())) {
            $message->forceFill([
                'status' => OutboxStatus::Pending,
                'safe_error_code' => FederationErrorCode::LinkInactive->value,
                'next_attempt_at' => now()->addMinutes(15),
            ])->save();

            return;
        }

        if (in_array($message->message_type, [
            FederationMessageType::ResourcePublished,
            FederationMessageType::ResourceUpdated,
        ], true)) {
            $delivery = FederationPublicationDelivery::query()
                ->where('outbox_message_id', $message->message_id)
                ->first();

            if ($delivery instanceof FederationPublicationDelivery
                && ($delivery->state === DeliveryState::Revoked
                    || $delivery->access_revoked_at !== null
                    || $delivery->canonical_payload === null)) {
                $message->forceFill([
                    'status' => OutboxStatus::Failed,
                    'safe_error_code' => FederationErrorCode::CapabilityDenied->value,
                    'failed_at' => now(),
                    'envelope_body' => null,
                ])->save();

                return;
            }
        }

        $message->forceFill([
            'status' => OutboxStatus::Delivering,
            'attempts' => (int) $message->attempts + 1,
            'next_attempt_at' => null,
        ])->save();

        try {
            $endpoint = $message->message_type->isHandshake()
                ? FederationEndpoint::Handshakes
                : FederationEndpoint::Envelopes;
            $result = $transport->send(
                PeerOrigin::fromUrl($message->link->approved_origin),
                $endpoint,
                (string) $message->envelope_body,
            );

            if ($result->isAccepted()) {
                $message->forceFill([
                    'status' => OutboxStatus::TransportAccepted,
                    'transport_accepted_at' => now(),
                    'correlation_id' => $result->correlationId,
                    'safe_error_code' => null,
                ])->save();
                FederationPublicationDelivery::query()
                    ->where('outbox_message_id', $message->message_id)
                    ->whereIn('state', [
                        DeliveryState::Pending->value,
                        DeliveryState::Failed->value,
                    ])
                    ->update([
                        'state' => DeliveryState::TransportAccepted->value,
                        'transport_accepted_at' => now(),
                        'updated_at' => now(),
                    ]);

                return;
            }

            if ($result->isRetryable()) {
                $this->scheduleRetry($message, $result->status === 429
                    ? FederationErrorCode::RateLimited
                    : FederationErrorCode::TemporaryUnavailable);

                throw new ConnectionException('Federation peer returned a transient response.');
            }

            $errorCode = $this->safeErrorForStatus($result->status);
            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'safe_error_code' => $errorCode->value,
                'failed_at' => now(),
                'envelope_body' => null,
            ])->save();
            $this->failDelivery($message, $errorCode);
        } catch (InvalidArgumentException $exception) {
            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'safe_error_code' => FederationErrorCode::InvalidEnvelope->value,
                'failed_at' => now(),
            ])->save();
            $this->failDelivery($message, FederationErrorCode::InvalidEnvelope);

            Log::warning('Federation delivery blocked by local validation.', [
                'message_id' => $message->message_id,
                'link_id' => $message->federation_link_id,
                'error_code' => FederationErrorCode::InvalidEnvelope->value,
            ]);
        } catch (ConnectionException $exception) {
            if ($message->status !== OutboxStatus::Pending) {
                $this->scheduleRetry($message, FederationErrorCode::TemporaryUnavailable);
            }

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->outboxMessageId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return FederationOutboxMessage::query()->find($this->outboxMessageId)?->expires_at
            ?? now()->addMinute();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5 + random_int(0, 5), 30 + random_int(0, 15), 120 + random_int(0, 30), 600];
    }

    public function failed(?Throwable $exception): void
    {
        $message = FederationOutboxMessage::query()->find($this->outboxMessageId);

        if ($message instanceof FederationOutboxMessage
            && ! in_array($message->status, [OutboxStatus::Failed, OutboxStatus::Expired], true)) {
            $message->forceFill([
                'status' => $message->expires_at->isPast() ? OutboxStatus::Expired : OutboxStatus::Pending,
                'safe_error_code' => FederationErrorCode::TemporaryUnavailable->value,
                'next_attempt_at' => $message->expires_at->isFuture() ? now()->addMinutes(10) : null,
                'failed_at' => $message->expires_at->isPast() ? now() : null,
            ])->save();
        }
    }

    private function scheduleRetry(
        FederationOutboxMessage $message,
        FederationErrorCode $errorCode,
    ): void {
        $delay = min(600, (2 ** min((int) $message->attempts, 8)) + random_int(0, 10));
        $message->forceFill([
            'status' => OutboxStatus::Pending,
            'safe_error_code' => $errorCode->value,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();
    }

    private function safeErrorForStatus(int $status): FederationErrorCode
    {
        return match ($status) {
            401, 403, 404 => FederationErrorCode::UnknownPeer,
            409 => FederationErrorCode::VersionConflict,
            413 => FederationErrorCode::PayloadTooLarge,
            422 => FederationErrorCode::InvalidEnvelope,
            default => FederationErrorCode::InvalidEnvelope,
        };
    }

    private function failDelivery(
        FederationOutboxMessage $message,
        FederationErrorCode $errorCode,
    ): void {
        FederationPublicationDelivery::query()
            ->where('outbox_message_id', $message->message_id)
            ->whereIn('state', [
                DeliveryState::Pending->value,
                DeliveryState::TransportAccepted->value,
                DeliveryState::Failed->value,
            ])
            ->update([
                'state' => DeliveryState::Failed->value,
                'safe_error_code' => $errorCode->value,
                'updated_at' => now(),
            ]);
    }

    private function featureGateAllows(FederationMessageType $type): bool
    {
        if (! (bool) config('federation.enabled', false)) {
            return false;
        }

        if (in_array($type, [
            FederationMessageType::ResourcePublished,
            FederationMessageType::ResourceUpdated,
        ], true)) {
            return (bool) config('federation.features.publishing', false);
        }

        if ($type->isHandshake()
            || in_array($type, [
                FederationMessageType::KeyRotation,
                FederationMessageType::EndpointChange,
            ], true)) {
            return (bool) config('federation.features.linking', false);
        }

        return true;
    }
}
