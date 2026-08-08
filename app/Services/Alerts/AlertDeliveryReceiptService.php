<?php

namespace App\Services\Alerts;

use App\Enums\AlertAttemptStatus;
use App\Enums\AlertBatchStatus;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationHealth;
use App\Enums\DiscordQueueStatus;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDeliveryBatch;
use App\Models\DiscordQueue;
use Illuminate\Support\Arr;

class AlertDeliveryReceiptService
{
    public function beginAttempt(DiscordQueue $command): void
    {
        if ($command->alert_delivery_batch_id === null) {
            return;
        }

        AlertDeliveryAttempt::query()->firstOrCreate(
            [
                'alert_delivery_batch_id' => $command->alert_delivery_batch_id,
                'attempt_number' => $command->attempts,
            ],
            [
                'adapter' => 'discord',
                'status' => AlertAttemptStatus::Started,
                'started_at' => now(),
            ],
        );

        AlertDeliveryBatch::query()->whereKey($command->alert_delivery_batch_id)->update([
            'attempted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $result */
    public function record(DiscordQueue $command, ?array $result = null): void
    {
        if ($command->alert_delivery_batch_id === null) {
            return;
        }

        $batch = AlertDeliveryBatch::query()
            ->with(['deliveries', 'destination'])
            ->find($command->alert_delivery_batch_id);
        if ($batch === null) {
            return;
        }

        $attempt = AlertDeliveryAttempt::query()->firstOrCreate(
            [
                'alert_delivery_batch_id' => $batch->id,
                'attempt_number' => $command->attempts,
            ],
            [
                'adapter' => 'discord',
                'status' => AlertAttemptStatus::Started,
                'started_at' => $command->updated_at ?? now(),
            ],
        );
        $classification = (string) data_get($result, 'delivery', 'failed');
        $retryable = (bool) data_get($result, 'retryable', false);
        $errorCode = (string) data_get($result, 'error_code', data_get($command->last_error, 'code', 'discord_failed'));
        $errorMessage = (string) data_get($result, 'error_message', data_get($command->last_error, 'message', 'Discord delivery failed.'));

        [$attemptStatus, $batchStatus, $deliveryStatus] = match (true) {
            $command->status === DiscordQueueStatus::Complete && $classification === 'delivered' => [
                AlertAttemptStatus::Succeeded,
                AlertBatchStatus::Delivered,
                AlertDeliveryStatus::Delivered,
            ],
            $command->status === DiscordQueueStatus::Complete && $classification === 'undeliverable' => [
                AlertAttemptStatus::PermanentFailure,
                AlertBatchStatus::Undeliverable,
                AlertDeliveryStatus::Undeliverable,
            ],
            $classification === 'quarantined' => [
                AlertAttemptStatus::Quarantined,
                AlertBatchStatus::Quarantined,
                AlertDeliveryStatus::Quarantined,
            ],
            $command->status === DiscordQueueStatus::Pending => [
                AlertAttemptStatus::RetryableFailure,
                AlertBatchStatus::Queued,
                AlertDeliveryStatus::Queued,
            ],
            default => [
                $retryable ? AlertAttemptStatus::RetryableFailure : AlertAttemptStatus::PermanentFailure,
                AlertBatchStatus::Failed,
                AlertDeliveryStatus::Failed,
            ],
        };

        $finishedAt = now();
        $attempt->forceFill([
            'status' => $attemptStatus,
            'finished_at' => $finishedAt,
            'latency_ms' => $attempt->started_at?->diffInMilliseconds($finishedAt),
            'error_code' => $attemptStatus === AlertAttemptStatus::Succeeded ? null : $errorCode,
            'error_message' => $attemptStatus === AlertAttemptStatus::Succeeded ? null : (string) str($errorMessage)->limit(500, ''),
            'retryable' => $retryable,
            'provider_message_id' => data_get($result, 'provider_message_id'),
            'provider_guild_id' => data_get($result, 'guild_id'),
            'provider_channel_id' => data_get($result, 'channel_id'),
            'result' => Arr::only($result ?? [], [
                'delivery',
                'provider_message_id',
                'guild_id',
                'channel_id',
                'error_code',
                'retryable',
                'retry_after_ms',
            ]),
        ])->save();

        $terminalAt = $deliveryStatus === AlertDeliveryStatus::Delivered ? $finishedAt : null;
        $failedAt = in_array($deliveryStatus, [
            AlertDeliveryStatus::Undeliverable,
            AlertDeliveryStatus::Failed,
            AlertDeliveryStatus::Quarantined,
        ], true) ? $finishedAt : null;

        $batch->forceFill([
            'status' => $batchStatus,
            'provider_message_id' => data_get($result, 'provider_message_id', $batch->provider_message_id),
            'failure_code' => $failedAt === null ? null : $errorCode,
            'failure_message' => $failedAt === null ? null : (string) str($errorMessage)->limit(2000, ''),
            'delivered_at' => $terminalAt,
            'failed_at' => $failedAt,
        ])->save();

        foreach ($batch->deliveries as $delivery) {
            $delivery->forceFill([
                'status' => $deliveryStatus,
                'reason_code' => $failedAt === null ? null : $errorCode,
                'delivered_at' => $terminalAt,
                'failed_at' => $failedAt,
            ])->save();
        }

        if ($batch->destination !== null && $deliveryStatus !== AlertDeliveryStatus::Quarantined) {
            $batch->destination->forceFill($deliveryStatus === AlertDeliveryStatus::Delivered
                ? [
                    'health_status' => AlertDestinationHealth::Healthy,
                    'last_succeeded_at' => $finishedAt,
                    'last_failure_code' => null,
                ]
                : [
                    'health_status' => $retryable
                        ? AlertDestinationHealth::Degraded
                        : AlertDestinationHealth::Unhealthy,
                    'last_failed_at' => $finishedAt,
                    'last_failure_code' => $errorCode,
                ])->save();
        }
    }
}
