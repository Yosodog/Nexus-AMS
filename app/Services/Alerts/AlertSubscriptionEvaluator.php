<?php

namespace App\Services\Alerts;

use App\Enums\AlertSubscriptionType;
use App\Models\AlertSubscription;
use App\Models\Nation;
use App\Models\TradePrice;
use App\Models\Treaty;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Support\Arr;

class AlertSubscriptionEvaluator
{
    public function __construct(
        private readonly AlertSubscriptionEligibilityService $eligibility,
        private readonly PrivateNotificationService $notifications,
    ) {}

    public function evaluate(AlertSubscription $subscription): bool
    {
        $subscription->loadMissing('user');

        if (! $subscription->is_active || $subscription->expires_at?->isPast()) {
            if ($subscription->is_active && $subscription->expires_at?->isPast()) {
                $subscription->update(['is_active' => false]);
            }

            return false;
        }

        $ownerNation = $this->eligibility->eligibleNation($subscription->user);
        if (! $ownerNation) {
            return false;
        }

        $state = $this->captureState($subscription);
        $previous = $subscription->last_observed_state;
        $condition = $subscription->type === AlertSubscriptionType::Market
            ? $this->marketCondition($subscription, $state)
            : null;

        $changes = $previous === null ? [] : $this->changes($subscription, $previous, $state, $condition);
        $subscription->forceFill([
            'last_observed_state' => $state,
            'last_condition' => $condition,
            'last_evaluated_at' => now(),
        ])->save();

        if ($changes === [] || $this->isCoolingDown($subscription)) {
            return false;
        }

        $queued = $this->notifications->enqueueForNation(
            $ownerNation,
            'watchlists',
            'watchlist_triggered',
            'watchlist-'.$subscription->id.'-'.substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 24),
            ['type' => 'alert_subscription', 'id' => $subscription->id, 'label' => $subscription->displayName()],
            '/user/alerts',
            [
                'status' => 'triggered',
                'alert_type' => $subscription->type->label(),
                'event' => implode('; ', $changes),
                'target' => (string) ($state['label'] ?? $subscription->displayName()),
            ],
        );

        if ($queued) {
            $subscription->update(['last_triggered_at' => now()]);
        }

        return $queued;
    }

    /** @return array<string, mixed> */
    private function captureState(AlertSubscription $subscription): array
    {
        return match ($subscription->type) {
            AlertSubscriptionType::Nation => $this->nationState((int) $subscription->config['target_id']),
            AlertSubscriptionType::Alliance => $this->allianceState((int) $subscription->config['target_id']),
            AlertSubscriptionType::Market => $this->marketState((string) $subscription->config['resource']),
        };
    }

    /** @return array<string, mixed> */
    private function nationState(int $nationId): array
    {
        $nation = Nation::query()->findOrFail($nationId);

        return [
            'label' => $nation->nation_name,
            'alliance_id' => $nation->alliance_id === null ? null : (int) $nation->alliance_id,
            'vacation_mode' => (int) $nation->vacation_mode_turns > 0,
            'beige' => (int) $nation->beige_turns > 0,
            'cities' => (int) $nation->num_cities,
            'offensive_wars' => (int) $nation->offensive_wars_count,
            'defensive_wars' => (int) $nation->defensive_wars_count,
        ];
    }

    /** @return array<string, mixed> */
    private function allianceState(int $allianceId): array
    {
        $members = Nation::query()
            ->where('alliance_id', $allianceId)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $treaties = Treaty::query()
            ->where(fn ($query) => $query
                ->where('alliance1_id', $allianceId)
                ->orWhere('alliance2_id', $allianceId))
            ->get(['pw_id', 'alliance1_id', 'alliance2_id', 'type'])
            ->map(fn (Treaty $treaty): string => implode(':', [
                $treaty->pw_id,
                $treaty->alliance1_id,
                $treaty->alliance2_id,
                $treaty->type,
            ]))
            ->sort()
            ->values()
            ->all();

        return [
            'label' => 'Alliance #'.$allianceId,
            'member_ids' => $members,
            'treaties' => $treaties,
        ];
    }

    /** @return array<string, mixed> */
    private function marketState(string $resource): array
    {
        $tradePrice = TradePrice::query()->latest('created_at')->firstOrFail();

        return [
            'label' => ucfirst($resource),
            'resource' => $resource,
            'price' => (float) $tradePrice->{$resource},
            'observed_at' => $tradePrice->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    private function changes(AlertSubscription $subscription, array $previous, array $current, ?bool $condition): array
    {
        $events = Arr::wrap($subscription->config['events'] ?? []);

        if ($subscription->type === AlertSubscriptionType::Market) {
            return $subscription->last_condition === false && $condition === true
                ? [sprintf(
                    '%s price crossed %s %s (now %s)',
                    ucfirst((string) $subscription->config['resource']),
                    (string) $subscription->config['direction'],
                    number_format((float) $subscription->config['threshold'], 2),
                    number_format((float) $current['price'], 2),
                )]
                : [];
        }

        if ($subscription->type === AlertSubscriptionType::Nation) {
            return $this->nationChanges($events, $previous, $current);
        }

        return $this->allianceChanges($events, $previous, $current);
    }

    /**
     * @param  array<int, string>  $events
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    private function nationChanges(array $events, array $previous, array $current): array
    {
        $changes = [];
        if (in_array('alliance_changed', $events, true) && $previous['alliance_id'] !== $current['alliance_id']) {
            $changes[] = 'Alliance changed';
        }
        if (in_array('vacation_mode_entered', $events, true) && ! $previous['vacation_mode'] && $current['vacation_mode']) {
            $changes[] = 'Entered vacation mode';
        }
        if (in_array('vacation_mode_exited', $events, true) && $previous['vacation_mode'] && ! $current['vacation_mode']) {
            $changes[] = 'Exited vacation mode';
        }
        if (in_array('beige_exited', $events, true) && $previous['beige'] && ! $current['beige']) {
            $changes[] = 'Exited beige';
        }
        if (in_array('city_count_changed', $events, true) && $previous['cities'] !== $current['cities']) {
            $changes[] = sprintf('Cities changed from %d to %d', $previous['cities'], $current['cities']);
        }
        if (in_array('war_state_changed', $events, true)
            && ($previous['offensive_wars'] !== $current['offensive_wars']
                || $previous['defensive_wars'] !== $current['defensive_wars'])) {
            $changes[] = sprintf('Active wars changed to %d offensive / %d defensive', $current['offensive_wars'], $current['defensive_wars']);
        }

        return $changes;
    }

    /**
     * @param  array<int, string>  $events
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    private function allianceChanges(array $events, array $previous, array $current): array
    {
        $changes = [];
        if (in_array('membership_changed', $events, true) && $previous['member_ids'] !== $current['member_ids']) {
            $added = count(array_diff($current['member_ids'], $previous['member_ids']));
            $removed = count(array_diff($previous['member_ids'], $current['member_ids']));
            $changes[] = sprintf('Membership changed (+%d / -%d)', $added, $removed);
        }
        if (in_array('treaty_changed', $events, true) && $previous['treaties'] !== $current['treaties']) {
            $added = count(array_diff($current['treaties'], $previous['treaties']));
            $removed = count(array_diff($previous['treaties'], $current['treaties']));
            $changes[] = sprintf('Treaties changed (+%d / -%d)', $added, $removed);
        }

        return $changes;
    }

    /** @param array<string, mixed> $state */
    private function marketCondition(AlertSubscription $subscription, array $state): bool
    {
        $price = (float) $state['price'];
        $threshold = (float) $subscription->config['threshold'];

        return $subscription->config['direction'] === 'above'
            ? $price >= $threshold
            : $price <= $threshold;
    }

    private function isCoolingDown(AlertSubscription $subscription): bool
    {
        return $subscription->last_triggered_at !== null
            && $subscription->last_triggered_at->copy()->addMinutes($subscription->cooldown_minutes)->isFuture();
    }
}
