<?php

namespace App\Services\Alerts;

use App\Enums\AlertDestinationKind;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class AlertActivityService
{
    /**
     * @return array{items:list<array<string, mixed>>,next_cursor:int|null}
     */
    public function forUser(User $user, ?int $beforeDeliveryId = null, int $limit = 30): array
    {
        $pageSize = max(1, min(100, $limit));
        $webDeliveries = AlertDelivery::query()
            ->whereBelongsTo($user, 'recipient')
            ->where('destination_kind', AlertDestinationKind::Web->value)
            ->when($beforeDeliveryId !== null, fn (Builder $query): Builder => $query->whereKeyNot($beforeDeliveryId)->where('id', '<', $beforeDeliveryId))
            ->with([
                'occurrence.deliveries' => fn ($query) => $query->oldest('id'),
                'occurrence.deliveries.batch.attempts' => fn ($query) => $query->oldest('attempt_number'),
            ])
            ->latest('id')
            ->limit($pageSize + 1)
            ->get();
        $hasMore = $webDeliveries->count() > $pageSize;
        if ($hasMore) {
            $webDeliveries->pop();
        }

        return [
            'items' => $webDeliveries
                ->map(fn (AlertDelivery $delivery): array => $this->activityItem($delivery))
                ->values()
                ->all(),
            'next_cursor' => $hasMore ? $webDeliveries->last()?->id : null,
        ];
    }

    public function markRead(User $user, AlertDelivery $delivery, bool $read): AlertDelivery
    {
        $ownedDelivery = AlertDelivery::query()
            ->whereKey($delivery->id)
            ->whereBelongsTo($user, 'recipient')
            ->where('destination_kind', AlertDestinationKind::Web->value)
            ->first();
        if ($ownedDelivery === null) {
            throw new AuthorizationException('You may update only your own alert activity.');
        }

        $ownedDelivery->forceFill(['read_at' => $read ? now() : null])->save();

        return $ownedDelivery->refresh();
    }

    /** @return array<string, mixed> */
    public function deliveryForUser(User $user, AlertDelivery $delivery): array
    {
        $ownedDelivery = AlertDelivery::query()
            ->whereKey($delivery->id)
            ->whereBelongsTo($user, 'recipient')
            ->with(['occurrence', 'batch.attempts'])
            ->first();
        if ($ownedDelivery === null) {
            throw new AuthorizationException('You may view only your own alert deliveries.');
        }

        return $this->deliveryItem($ownedDelivery, $ownedDelivery->occurrence);
    }

    /** @return array<string, mixed> */
    private function activityItem(AlertDelivery $webDelivery): array
    {
        /** @var AlertOccurrence $occurrence */
        $occurrence = $webDelivery->occurrence;

        return [
            'activity_id' => $webDelivery->id,
            'occurrence_id' => $occurrence->id,
            'event_key' => $occurrence->event_key,
            'schema_version' => $occurrence->schema_version,
            'severity' => $occurrence->severity->value,
            'payload' => $occurrence->payload,
            'deep_link_path' => $occurrence->deep_link_path,
            'is_test' => $occurrence->is_test,
            'occurred_at' => $occurrence->occurred_at->toIso8601String(),
            'observed_at' => $occurrence->observed_at?->toIso8601String(),
            'received_at' => $occurrence->received_at->toIso8601String(),
            'stale_at' => $occurrence->stale_at?->toIso8601String(),
            'read_at' => $webDelivery->read_at?->toIso8601String(),
            'deliveries' => $occurrence->deliveries
                ->map(fn (AlertDelivery $delivery): array => $this->deliveryItem($delivery, $occurrence))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function deliveryItem(AlertDelivery $delivery, AlertOccurrence $occurrence): array
    {
        $attempt = $delivery->batch?->attempts->sortByDesc('attempt_number')->first();

        return [
            'id' => $delivery->id,
            'occurrence_id' => $occurrence->id,
            'subscription_id' => $delivery->alert_subscription_id,
            'event_key' => $occurrence->event_key,
            'is_test' => $occurrence->is_test,
            'destination_kind' => $delivery->destination_kind->value,
            'delivery_mode' => $delivery->delivery_mode->value,
            'status' => $delivery->status->value,
            'reason_code' => $delivery->reason_code,
            'scheduled_at' => $delivery->scheduled_at?->toIso8601String(),
            'queued_at' => $delivery->queued_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
            'batch' => $delivery->batch === null ? null : [
                'id' => $delivery->batch->id,
                'status' => $delivery->batch->status->value,
                'provider_message_id' => $delivery->batch->provider_message_id,
                'failure_code' => $delivery->batch->failure_code,
                'attempt_count' => $delivery->batch->attempts->count(),
                'last_attempt' => $attempt === null ? null : [
                    'number' => $attempt->attempt_number,
                    'status' => $attempt->status->value,
                    'error_code' => $attempt->error_code,
                    'retryable' => $attempt->retryable,
                    'finished_at' => $attempt->finished_at?->toIso8601String(),
                ],
            ],
        ];
    }
}
