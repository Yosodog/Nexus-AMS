<?php

namespace App\Jobs;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationOutboxMessage;
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

            return;
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

                return;
            }

            if ($result->isRetryable()) {
                $this->scheduleRetry($message, $result->status === 429
                    ? FederationErrorCode::RateLimited
                    : FederationErrorCode::TemporaryUnavailable);

                throw new ConnectionException('Federation peer returned a transient response.');
            }

            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'safe_error_code' => $this->safeErrorForStatus($result->status)->value,
                'failed_at' => now(),
                'envelope_body' => null,
            ])->save();
        } catch (InvalidArgumentException $exception) {
            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'safe_error_code' => FederationErrorCode::InvalidEnvelope->value,
                'failed_at' => now(),
            ])->save();

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
}
