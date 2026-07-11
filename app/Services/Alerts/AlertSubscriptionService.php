<?php

namespace App\Services\Alerts;

use App\Enums\AlertSubscriptionType;
use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\User;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AlertSubscriptionService
{
    public const MAX_ACTIVE_PER_USER = 25;

    public function __construct(
        private readonly AlertSubscriptionEligibilityService $eligibility,
        private readonly PrivateNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function createForUser(User $user, array $data): AlertSubscription
    {
        $this->authorize($user);

        if ($this->activeCount($user) >= self::MAX_ACTIVE_PER_USER) {
            throw ValidationException::withMessages([
                'type' => 'You may have at most '.self::MAX_ACTIVE_PER_USER.' active alerts.',
            ]);
        }

        $type = AlertSubscriptionType::from((string) $data['type']);

        return AlertSubscription::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'name' => isset($data['name']) ? trim((string) $data['name']) ?: null : null,
            'config' => $this->configFor($type, $data),
            'is_active' => true,
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 60),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    public function setActive(User $user, AlertSubscription $subscription, bool $active): AlertSubscription
    {
        $this->authorizeOwnership($user, $subscription);

        if ($active && ! $subscription->is_active
            && $this->activeCount($user) >= self::MAX_ACTIVE_PER_USER) {
            throw ValidationException::withMessages([
                'is_active' => 'You may have at most '.self::MAX_ACTIVE_PER_USER.' active alerts.',
            ]);
        }

        if ($active && $subscription->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'is_active' => 'This alert has expired. Create a new alert instead.',
            ]);
        }

        $updates = ['is_active' => $active];
        if ($active && ! $subscription->is_active) {
            $updates += [
                'last_observed_state' => null,
                'last_condition' => null,
                'last_evaluated_at' => null,
            ];
        }
        $subscription->update($updates);

        return $subscription->refresh();
    }

    public function delete(User $user, AlertSubscription $subscription): void
    {
        $this->authorizeOwnership($user, $subscription);
        $subscription->delete();
    }

    public function test(User $user, AlertSubscription $subscription): void
    {
        $this->authorizeOwnership($user, $subscription);
        $nation = $this->eligibility->eligibleNation($user);

        if (! $nation || ! $this->notifications->enqueueForNation(
            $nation,
            'watchlists',
            'watchlist_test',
            'watchlist-test-'.$subscription->id.'-'.Str::uuid(),
            ['type' => 'alert_subscription', 'id' => $subscription->id, 'label' => $subscription->displayName()],
            '/user/alerts',
            ['status' => 'triggered', 'event' => 'Test alert'],
        )) {
            throw ValidationException::withMessages([
                'alert' => 'Enable private Discord notifications and the Watchlists category before testing an alert.',
            ]);
        }
    }

    public function authorize(User $user): void
    {
        if (! $this->eligibility->isEligible($user)) {
            throw new AuthorizationException('Custom alerts are available only to verified alliance and offshore members.');
        }
    }

    public function authorizeOwnership(User $user, AlertSubscription $subscription): void
    {
        $this->authorize($user);

        if ((int) $subscription->user_id !== (int) $user->id) {
            throw new AuthorizationException('You may manage only your own alerts.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function configFor(AlertSubscriptionType $type, array $data): array
    {
        if ($type === AlertSubscriptionType::Market) {
            return [
                'resource' => (string) $data['resource'],
                'direction' => (string) $data['direction'],
                'threshold' => (float) $data['threshold'],
            ];
        }

        $events = array_values(array_unique(array_map('strval', $data['events'] ?? [])));
        $invalidEvents = array_diff($events, array_keys($type->events()));

        if ($events === [] || $invalidEvents !== []) {
            throw ValidationException::withMessages([
                'events' => 'Select at least one event supported by this alert type.',
            ]);
        }

        $targetId = (int) $data['target_id'];
        $targetExists = $type === AlertSubscriptionType::Nation
            ? Nation::query()->whereKey($targetId)->exists()
            : Alliance::query()->whereKey($targetId)->exists();

        if (! $targetExists) {
            throw ValidationException::withMessages([
                'target_id' => 'The selected '.strtolower($type->label()).' target does not exist in Nexus.',
            ]);
        }

        return [
            'target_id' => $targetId,
            'events' => $events,
        ];
    }

    private function activeCount(User $user): int
    {
        return AlertSubscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->count();
    }
}
