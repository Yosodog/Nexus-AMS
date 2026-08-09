<?php

namespace App\Services\Alerts;

use App\Enums\AlertSubscriptionType;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class AlertTestDeliveryService
{
    public function __construct(
        private readonly AlertSubscriptionEventMap $events,
        private readonly AlertOccurrenceRecorder $occurrences,
    ) {}

    public function send(User $user, AlertSubscription $subscription): AlertOccurrence
    {
        if ((int) $subscription->user_id !== (int) $user->id) {
            throw new AuthorizationException('You may test only your own alerts.');
        }

        $allowedEventKeys = $this->events->eventKeys($subscription->type, $subscription->config);
        $storedEventKey = $subscription->events()->oldest('id')->value('event_key');
        $eventKey = is_string($storedEventKey) && in_array($storedEventKey, $allowedEventKeys, true)
            ? $storedEventKey
            : $allowedEventKeys[0];
        $targetId = $subscription->target_id
            ?? ($subscription->config['target_id'] ?? null);
        $allianceId = match ($subscription->type) {
            AlertSubscriptionType::Alliance => is_numeric($targetId) ? (int) $targetId : null,
            AlertSubscriptionType::Nation => is_numeric($targetId)
                ? Nation::query()->whereKey((int) $targetId)->value('alliance_id')
                : null,
            AlertSubscriptionType::Market => null,
        };

        return $this->occurrences->record(
            eventKey: $eventKey,
            sourceType: 'alert_subscription_test',
            sourceId: $subscription->id,
            dedupeKey: 'alert-test:'.$subscription->id.':'.Str::uuid(),
            payload: $this->payload($eventKey, $subscription),
            occurredAt: now(),
            observedAt: now(),
            allianceId: $allianceId === null ? null : (int) $allianceId,
            audienceUserId: $user->id,
            subjectType: $subscription->type->value,
            subjectId: is_scalar($targetId) ? (string) $targetId : null,
            sourceVersion: 'test-v1',
            correlationKey: 'alert-subscription:'.$subscription->id,
            deepLinkPath: '/user/alerts',
            isTest: true,
            subscription: $subscription,
            discordEnabled: true,
            forceImmediate: true,
        )->load('deliveries.batch.attempts');
    }

    /** @return array<string, mixed> */
    private function payload(string $eventKey, AlertSubscription $subscription): array
    {
        $label = 'Test · '.$subscription->displayName();

        return match ($eventKey) {
            'nation.alliance.changed' => ['label' => $label, 'old_alliance_id' => 0, 'alliance_id' => 1],
            'nation.vacation.entered' => ['label' => $label, 'vacation_mode' => true],
            'nation.vacation.exited' => ['label' => $label, 'vacation_mode' => false],
            'nation.beige.exited' => ['label' => $label, 'beige' => false],
            'nation.city_count.changed' => ['label' => $label, 'old_cities' => 10, 'cities' => 11],
            'nation.active_wars.changed' => ['label' => $label, 'offensive_wars' => 1, 'defensive_wars' => 1],
            'alliance.membership.changed' => ['label' => $label, 'added' => ['Example member'], 'removed' => []],
            'alliance.treaty.changed' => ['label' => $label, 'added' => ['Example treaty'], 'removed' => []],
            'market.price.crossed' => [
                'resource' => (string) $subscription->config['resource'],
                'direction' => (string) $subscription->config['direction'],
                'threshold' => (float) $subscription->config['threshold'],
                'price' => (float) $subscription->config['threshold'],
                'observed_at' => now()->toIso8601String(),
            ],
        };
    }
}
