<?php

namespace App\Services\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use Illuminate\Support\Collection;

class AlertScheduledDeliveryDispatcher
{
    public function __construct(
        private readonly AlertEventCatalog $catalog,
        private readonly AlertDeliveryService $deliveries,
    ) {}

    public function dispatchDue(int $limit = 500): int
    {
        $due = AlertDelivery::query()
            ->where('status', AlertDeliveryStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->with('occurrence')
            ->oldest('id')
            ->limit(max(1, min(2_000, $limit)))
            ->get();

        $digestDeliveries = collect();
        foreach ($due as $delivery) {
            if ($this->applySuppressionPolicy($delivery)) {
                continue;
            }

            if ($delivery->delivery_mode === AlertDeliveryMode::Immediate) {
                $this->deliveries->queueDelivery(
                    $delivery,
                    $delivery->occurrence,
                    $delivery->destination_snapshot ?? [],
                );

                continue;
            }

            $digestDeliveries->push($delivery);
        }

        $digestDeliveries
            ->groupBy(fn (AlertDelivery $delivery): string => $this->digestGroupKey($delivery))
            ->each(function (Collection $group): void {
                $first = $group->first();
                $this->deliveries->queueDigest($group->values(), $first->destination_snapshot ?? []);
            });

        return $due->count();
    }

    private function applySuppressionPolicy(AlertDelivery $delivery): bool
    {
        $occurrence = $delivery->occurrence;
        $definition = $this->catalog->get($occurrence->event_key);

        if ($definition->stalePolicy === 'supersede' && $this->hasNewerOccurrence($occurrence)) {
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Superseded,
                'reason_code' => 'newer_occurrence',
            ])->save();

            return true;
        }

        if ($occurrence->stale_at === null || $occurrence->stale_at->isFuture()) {
            return false;
        }

        $status = match ($definition->stalePolicy) {
            'quarantine' => AlertDeliveryStatus::Quarantined,
            'suppress' => AlertDeliveryStatus::Suppressed,
            default => null,
        };
        if ($status === null) {
            return false;
        }

        $delivery->forceFill([
            'status' => $status,
            'reason_code' => 'stale_occurrence',
            'failed_at' => $status === AlertDeliveryStatus::Quarantined ? now() : null,
        ])->save();

        return true;
    }

    private function hasNewerOccurrence(AlertOccurrence $occurrence): bool
    {
        return AlertOccurrence::query()
            ->where('event_key', $occurrence->event_key)
            ->where('source_type', $occurrence->source_type)
            ->where('source_id', $occurrence->source_id)
            ->whereKeyNot($occurrence->id)
            ->where('occurred_at', '>', $occurrence->occurred_at)
            ->exists();
    }

    private function digestGroupKey(AlertDelivery $delivery): string
    {
        return hash('sha256', implode('|', [
            $delivery->delivery_mode->value,
            $delivery->destination_kind->value,
            $delivery->recipient_user_id ?? 'none',
            $delivery->alert_destination_id ?? 'none',
            json_encode($delivery->destination_snapshot ?? [], JSON_THROW_ON_ERROR),
        ]));
    }
}
