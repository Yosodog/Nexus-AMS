<?php

namespace App\Services\Milcom;

use App\Models\MilcomEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilcomAlertService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function dismiss(User $actor, MilcomEvent $event): MilcomEvent
    {
        if (! $event->isRaidPolicyViolation()) {
            throw ValidationException::withMessages([
                'event' => 'This Milcom event is not a dismissible raid-policy alert.',
            ]);
        }

        return DB::transaction(function () use ($actor, $event): MilcomEvent {
            $lockedEvent = MilcomEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->dismissed_at !== null) {
                return $lockedEvent;
            }

            $lockedEvent->forceFill([
                'dismissed_at' => now(),
                'dismissed_by_user_id' => $actor->id,
            ])->save();

            $this->auditLogger->success(
                category: 'milcom',
                action: 'raid_policy_alert_dismissed',
                subject: $lockedEvent,
                context: [
                    'data' => [
                        'event_id' => $lockedEvent->id,
                        'event_type' => $lockedEvent->event_type,
                        'war_id' => data_get($lockedEvent->payload, 'war_id'),
                    ],
                ],
                message: 'Raid-policy alert dismissed.',
            );

            return $lockedEvent;
        }, attempts: 5);
    }
}
