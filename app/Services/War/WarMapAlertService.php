<?php

namespace App\Services\War;

use App\Enums\DiscordQueueAction;
use App\Models\DiscordQueue;
use App\Models\User;
use App\Models\War;
use App\Models\WarMapAlertState;
use App\Services\Alerts\AlertSubscriptionEligibilityService;
use App\Services\Discord\PrivateNotificationService;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WarMapAlertService
{
    public const CATEGORY = 'war_map_alerts';

    public const EVENT_TYPE = 'war_map_maxed';

    public const MAX_MAPS = 12;

    public const MAX_AGE_MINUTES = 90;

    public function __construct(
        private readonly AlertSubscriptionEligibilityService $eligibility,
        private readonly PrivateNotificationService $notifications,
        private readonly RuntimeCapabilities $capabilities,
    ) {}

    /**
     * Observe one member's side of a war, atomically enqueueing at most once per refill.
     * last_observed_at records the source war timestamp so older snapshots cannot rearm an alert.
     */
    public function evaluate(User $user, War $war): bool
    {
        if (! $this->capabilities->writesTenantPrivate()) {
            return false;
        }

        $nation = $this->eligibility->eligibleNation($user);
        $points = $nation === null ? null : $this->currentPoints($war, (int) $nation->id);

        if ($nation === null || $points === null) {
            return false;
        }

        $queued = WarMapAlertState::resolveConnection()->transaction(function () use ($user, $nation, $war, $points): bool {
            $key = ['user_id' => $user->id, 'nation_id' => $nation->id, 'war_id' => $war->id];
            WarMapAlertState::query()->insertOrIgnore([
                ...$key,
                'armed' => true,
                'notification_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $state = WarMapAlertState::query()->where($key)->lockForUpdate()->firstOrFail();

            if ($state->last_observed_at !== null && $war->updated_at->lt($state->last_observed_at)) {
                return false;
            }

            $state->last_observed_at = $war->updated_at;

            if ($points < self::MAX_MAPS) {
                $state->armed = true;
                $state->save();

                return false;
            }

            if (! $state->armed) {
                $state->save();

                return false;
            }

            $sequence = $state->notification_sequence + 1;
            $opponent = (int) $war->att_id === (int) $nation->id ? $war->defender : $war->attacker;
            $opponentId = (int) $war->att_id === (int) $nation->id ? $war->def_id : $war->att_id;
            $opponentName = Str::squish($opponent?->nation_name ?? 'Nation #'.$opponentId);
            $nation->setRelation('user', $user);

            if (! $this->notifications->enqueueForNation(
                nation: $nation,
                category: self::CATEGORY,
                eventType: self::EVENT_TYPE,
                notificationId: $this->notificationId($state, $sequence),
                subject: ['type' => 'war', 'id' => (int) $war->id, 'label' => $opponentName],
                deepLinkPath: route('defense.war-stats', absolute: false),
                summary: [
                    'nation_id' => (int) $nation->id,
                    'opponent_name' => $opponentName,
                    'current_maps' => $points,
                    'maximum_maps' => self::MAX_MAPS,
                    'notification_sequence' => $sequence,
                ],
            )) {
                $state->save();

                return false;
            }

            $state->forceFill([
                'armed' => false,
                'notification_sequence' => $sequence,
                'last_enqueued_at' => now(),
            ])->save();

            return true;
        }, attempts: 3);

        if ($queued) {
            Log::info('Maxed war action point alert queued.', ['user_id' => $user->id, 'nation_id' => $nation->id, 'war_id' => $war->id]);
        }

        return $queued;
    }

    /**
     * Called inside the queue claim transaction; processing leases are never revalidated here.
     */
    public function suppressionReason(DiscordQueue $command): ?string
    {
        if ($command->action !== DiscordQueueAction::PrivateNotification->value
            || ($command->payload['event_type'] ?? null) !== self::EVENT_TYPE) {
            return null;
        }

        $notificationId = $command->payload['notification_id'] ?? null;
        if (! is_string($notificationId) || ! preg_match('/^war-map:([1-9][0-9]*):([1-9][0-9]*)$/', $notificationId, $matches)) {
            return 'invalid_war_map_notification';
        }

        $state = WarMapAlertState::query()
            ->with(['user.nation', 'user.discordAccounts', 'user.discordNotificationPreferences', 'war'])
            ->lockForUpdate()
            ->find($matches[1]);

        if ($state === null || $state->armed || $state->notification_sequence !== (int) $matches[2]) {
            return 'obsolete_war_map_notification';
        }

        $user = $state->user;
        $nation = $user === null ? null : $this->eligibility->eligibleNation($user);
        if ($nation === null || (int) $nation->id !== (int) $state->nation_id
            || ! $this->notifications->canSend($user, self::CATEGORY)
            || $user->activeDiscordAccount()?->discord_id !== ($command->payload['recipient_discord_id'] ?? null)) {
            return 'war_map_recipient_ineligible';
        }

        if ($state->war === null || $this->currentPoints($state->war, (int) $nation->id) !== self::MAX_MAPS) {
            return 'war_map_no_longer_maxed';
        }

        return null;
    }

    private function notificationId(WarMapAlertState $state, int $sequence): string
    {
        return 'war-map:'.$state->id.':'.$sequence;
    }

    private function currentPoints(War $war, int $nationId): ?int
    {
        if ($war->end_date !== null || (int) $war->turns_left <= 0
            || $war->updated_at === null || $war->updated_at->lt(now()->subMinutes(self::MAX_AGE_MINUTES))
            || $war->updated_at->isFuture()) {
            return null;
        }

        $points = match ($nationId) {
            (int) $war->att_id => $war->att_points,
            (int) $war->def_id => $war->def_points,
            default => null,
        };

        if (! is_int($points) && (! is_string($points) || ! ctype_digit($points))) {
            return null;
        }

        return $points >= 0 && $points <= self::MAX_MAPS ? (int) $points : null;
    }
}
