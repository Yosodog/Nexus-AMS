<?php

namespace App\Services\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertSubscriptionStatus;
use App\Enums\AlertSubscriptionType;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AlertSubscriptionService
{
    public const MAX_ACTIVE_PER_USER = 25;

    public function __construct(
        private readonly AlertSubscriptionEligibilityService $eligibility,
        private readonly AlertSubscriptionEventMap $events,
        private readonly AlertTestDeliveryService $testDeliveries,
    ) {}

    /** @param array<string, mixed> $data */
    public function createForUser(User $user, array $data): AlertSubscription
    {
        $this->authorize($user);
        $this->expireSubscriptions($user);

        if ($this->activeCount($user) >= self::MAX_ACTIVE_PER_USER) {
            throw ValidationException::withMessages([
                'type' => 'You may have at most '.self::MAX_ACTIVE_PER_USER.' active alerts.',
            ]);
        }

        $draft = $this->prepareDraft($data);
        $type = $draft['type'];
        $config = $draft['config'];
        $eventKeys = $draft['event_keys'];
        $preferences = $draft['preferences'];
        $targetId = $draft['target_id'];
        $filterConfig = $draft['filter_config'];
        $fingerprint = $draft['fingerprint'];

        $this->assertFingerprintAvailable($user, $fingerprint);

        try {
            return DB::transaction(function () use (
                $user,
                $draft,
                $type,
                $config,
                $eventKeys,
                $preferences,
                $targetId,
                $filterConfig,
                $fingerprint,
            ): AlertSubscription {
                $subscription = AlertSubscription::query()->create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'name' => $draft['name'],
                    'config' => $config,
                    'target_type' => $type->value,
                    'target_id' => $targetId,
                    'filter_config' => $filterConfig,
                    'is_active' => true,
                    'status' => AlertSubscriptionStatus::Active,
                    'status_reason' => null,
                    'cooldown_minutes' => $preferences['cooldown_minutes'],
                    'delivery_mode' => $preferences['delivery_mode'],
                    'discord_enabled' => $preferences['discord_enabled'],
                    'rearm_percent' => $preferences['rearm_percent'],
                    'timezone' => $preferences['timezone'],
                    'expires_at' => $draft['expires_at'],
                    'active_fingerprint' => $fingerprint,
                ]);
                $subscription->events()->createMany(array_map(
                    fn (string $eventKey): array => ['event_key' => $eventKey],
                    $eventKeys,
                ));

                return $subscription->load('events');
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)
                || ! $this->fingerprintExists($user, $fingerprint)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'alert' => 'An active alert already watches the same target, events, and threshold.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function previewForUser(User $user, array $data): array
    {
        $this->authorize($user);
        $draft = $this->prepareDraft($data);

        return [
            'name' => $draft['name']
                ?? $this->draftDisplayName($draft['type'], $draft['config']),
            'type' => $draft['type']->value,
            'type_label' => $draft['type']->label(),
            'target_id' => $draft['target_id'],
            'events' => collect($draft['event_keys'])->map(fn (string $eventKey): array => [
                'key' => $eventKey,
                'label' => (string) str($eventKey)->replace('.', ' ')->headline()->lower()->ucfirst(),
            ])->values()->all(),
            'condition' => $this->draftCondition($draft['type'], $draft['config'], $draft['event_keys']),
            'cooldown_minutes' => $draft['preferences']['cooldown_minutes'],
            'rearm_percent' => $draft['preferences']['rearm_percent'],
            'expires_at' => $draft['expires_at'],
            'baseline_state' => 'established_after_save',
            'can_save' => $this->activeCount($user) < self::MAX_ACTIVE_PER_USER,
            'delivery' => [
                'mode' => $draft['preferences']['delivery_mode']->value,
                'discord_enabled' => $draft['preferences']['discord_enabled'],
                'timezone' => $draft['preferences']['timezone'],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public function testDraft(User $user, array $data): AlertOccurrence
    {
        $this->authorize($user);
        $draft = $this->prepareDraft($data);
        $subscription = new AlertSubscription([
            'user_id' => $user->id,
            'type' => $draft['type'],
            'name' => $draft['name'],
            'config' => $draft['config'],
            'target_type' => $draft['type']->value,
            'target_id' => $draft['target_id'],
            'filter_config' => $draft['filter_config'],
            'cooldown_minutes' => $draft['preferences']['cooldown_minutes'],
            'delivery_mode' => $draft['preferences']['delivery_mode'],
            'discord_enabled' => $draft['preferences']['discord_enabled'],
            'rearm_percent' => $draft['preferences']['rearm_percent'],
            'timezone' => $draft['preferences']['timezone'],
        ]);

        return $this->testDeliveries->sendDraft($user, $subscription, $draft['event_keys']);
    }

    /** @param array<string, mixed> $data */
    public function updateForUser(
        User $user,
        AlertSubscription $subscription,
        array $data,
    ): AlertSubscription {
        $this->authorizeOwnership($user, $subscription);
        $this->expireSubscriptions($user);
        $draft = $this->prepareDraft($data);

        try {
            return DB::transaction(function () use ($user, $subscription, $draft): AlertSubscription {
                $locked = AlertSubscription::query()
                    ->whereKey($subscription->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $currentEventKeys = $this->events->eventKeys($locked->type, $locked->config);
                $currentTargetId = $locked->target_id
                    ?? (isset($locked->config['target_id']) ? (int) $locked->config['target_id'] : null);
                $currentFilter = $locked->filter_config ?? ($locked->type === AlertSubscriptionType::Market
                    ? [
                        'resource' => $locked->config['resource'],
                        'direction' => $locked->config['direction'],
                        'threshold' => $locked->config['threshold'],
                    ]
                    : []);
                $currentFingerprint = $this->fingerprint(
                    $locked->type,
                    $currentTargetId,
                    $currentFilter,
                    $currentEventKeys,
                );
                $triggerChanged = ! hash_equals($currentFingerprint, $draft['fingerprint']);

                if ($locked->is_active) {
                    $this->assertFingerprintAvailable($user, $draft['fingerprint'], $locked->id);
                }

                $updates = [
                    'type' => $draft['type'],
                    'name' => $draft['name'],
                    'config' => $draft['config'],
                    'target_type' => $draft['type']->value,
                    'target_id' => $draft['target_id'],
                    'filter_config' => $draft['filter_config'],
                    'cooldown_minutes' => $draft['preferences']['cooldown_minutes'],
                    'delivery_mode' => $draft['preferences']['delivery_mode'],
                    'discord_enabled' => $draft['preferences']['discord_enabled'],
                    'rearm_percent' => $draft['preferences']['rearm_percent'],
                    'timezone' => $draft['preferences']['timezone'],
                    'expires_at' => $draft['expires_at'],
                    'active_fingerprint' => $locked->is_active ? $draft['fingerprint'] : null,
                ];
                if ($triggerChanged) {
                    $updates += [
                        'last_observed_state' => null,
                        'last_condition' => null,
                        'last_evaluated_at' => null,
                        'last_triggered_at' => null,
                        'last_source_version' => null,
                        'last_source_observed_at' => null,
                    ];
                }
                $locked->forceFill($updates)->save();
                $locked->events()->delete();
                $locked->events()->createMany(array_map(
                    fn (string $eventKey): array => ['event_key' => $eventKey],
                    $draft['event_keys'],
                ));

                return $locked->refresh()->load('events');
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'alert' => 'An active alert already watches the same target, events, and threshold.',
            ]);
        }
    }

    public function setActive(User $user, AlertSubscription $subscription, bool $active): AlertSubscription
    {
        $this->authorizeOwnership($user, $subscription);
        $this->expireSubscriptions($user);

        try {
            return DB::transaction(function () use ($user, $subscription, $active): AlertSubscription {
                $locked = AlertSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if ($active && ! $locked->is_active
                    && $this->activeCount($user, $locked->id) >= self::MAX_ACTIVE_PER_USER) {
                    throw ValidationException::withMessages([
                        'is_active' => 'You may have at most '.self::MAX_ACTIVE_PER_USER.' active alerts.',
                    ]);
                }

                if ($active && $locked->expires_at?->isPast()) {
                    throw ValidationException::withMessages([
                        'is_active' => 'This alert has expired. Create a new alert instead.',
                    ]);
                }

                if (! $active) {
                    if ($locked->status === AlertSubscriptionStatus::Expired) {
                        return $locked->refresh()->load('events');
                    }

                    $locked->forceFill([
                        'is_active' => false,
                        'status' => AlertSubscriptionStatus::Paused,
                        'status_reason' => 'user_paused',
                        'active_fingerprint' => null,
                    ])->save();

                    return $locked->refresh()->load('events');
                }

                $eventKeys = $this->events->eventKeys($locked->type, $locked->config);
                $targetId = $locked->target_id
                    ?? (isset($locked->config['target_id']) ? (int) $locked->config['target_id'] : null);
                $filterConfig = $locked->filter_config ?? ($locked->type === AlertSubscriptionType::Market
                    ? [
                        'resource' => $locked->config['resource'],
                        'direction' => $locked->config['direction'],
                        'threshold' => $locked->config['threshold'],
                    ]
                    : []);
                $fingerprint = $this->fingerprint($locked->type, $targetId, $filterConfig, $eventKeys);
                $this->assertFingerprintAvailable($user, $fingerprint, $locked->id);

                foreach ($eventKeys as $eventKey) {
                    $locked->events()->firstOrCreate(['event_key' => $eventKey]);
                }

                $updates = [
                    'target_type' => $locked->target_type ?: $locked->type->value,
                    'target_id' => $targetId,
                    'filter_config' => $filterConfig,
                    'is_active' => true,
                    'status' => AlertSubscriptionStatus::Active,
                    'status_reason' => null,
                    'active_fingerprint' => $fingerprint,
                ];
                if (! $locked->is_active) {
                    $updates += [
                        'last_observed_state' => null,
                        'last_condition' => null,
                        'last_evaluated_at' => null,
                    ];
                }
                $locked->forceFill($updates)->save();

                return $locked->refresh()->load('events');
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'alert' => 'An active alert already watches the same target, events, and threshold.',
            ]);
        }
    }

    public function delete(User $user, AlertSubscription $subscription): void
    {
        $this->authorizeOwnership($user, $subscription);
        $subscription->delete();
    }

    public function test(User $user, AlertSubscription $subscription): AlertOccurrence
    {
        $this->authorizeOwnership($user, $subscription);

        return $this->testDeliveries->send($user, $subscription);
    }

    /** @return list<string> */
    public function eventKeysFor(AlertSubscription $subscription): array
    {
        return $this->events->eventKeys($subscription->type, $subscription->config);
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
            $resource = (string) ($data['resource'] ?? '');
            $direction = (string) ($data['direction'] ?? '');
            $threshold = filter_var($data['threshold'] ?? null, FILTER_VALIDATE_FLOAT);
            if (! isset(AlertSubscriptionType::resources()[$resource])
                || ! in_array($direction, ['above', 'below'], true)
                || $threshold === false
                || $threshold < 0.01
                || $threshold > 1_000_000_000) {
                throw ValidationException::withMessages([
                    'threshold' => 'Choose a valid resource, direction, and market threshold.',
                ]);
            }

            return [
                'resource' => $resource,
                'direction' => $direction,
                'threshold' => (float) $threshold,
            ];
        }

        $events = array_values(array_unique(array_map('strval', $data['events'] ?? [])));
        if ($events === []) {
            throw ValidationException::withMessages([
                'events' => 'Select at least one event supported by this alert type.',
            ]);
        }

        $targetId = (int) ($data['target_id'] ?? 0);
        $targetExists = $type === AlertSubscriptionType::Nation
            ? Nation::query()->whereKey($targetId)->exists()
            : Alliance::query()->whereKey($targetId)->exists();

        if (! $targetExists) {
            throw ValidationException::withMessages([
                'target_id' => 'The selected '.strtolower($type->label()).' target does not exist in '.config('app.name').'.',
            ]);
        }

        return [
            'target_id' => $targetId,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{cooldown_minutes:int,delivery_mode:AlertDeliveryMode,discord_enabled:bool,rearm_percent:float,timezone:string|null}
     */
    private function deliveryPreferences(array $data): array
    {
        $input = [
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
            'delivery_mode' => $data['delivery_mode'] ?? AlertDeliveryMode::Immediate->value,
            'discord_enabled' => $data['discord_enabled'] ?? false,
            'rearm_percent' => $data['rearm_percent'] ?? 1,
            'timezone' => isset($data['timezone']) && trim((string) $data['timezone']) !== ''
                ? trim((string) $data['timezone'])
                : null,
        ];
        $validated = Validator::validate($input, [
            'cooldown_minutes' => ['required', 'integer', 'between:5,10080'],
            'delivery_mode' => ['required', Rule::enum(AlertDeliveryMode::class)],
            'discord_enabled' => ['required', 'boolean'],
            'rearm_percent' => ['required', 'numeric', 'min:0.01', 'max:25'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
        ]);

        return [
            'cooldown_minutes' => (int) $validated['cooldown_minutes'],
            'delivery_mode' => AlertDeliveryMode::from((string) $validated['delivery_mode']),
            'discord_enabled' => in_array($validated['discord_enabled'], [true, 1, '1'], true),
            'rearm_percent' => (float) $validated['rearm_percent'],
            'timezone' => $validated['timezone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     type:AlertSubscriptionType,
     *     name:string|null,
     *     config:array<string,mixed>,
     *     event_keys:list<string>,
     *     preferences:array{cooldown_minutes:int,delivery_mode:AlertDeliveryMode,discord_enabled:bool,rearm_percent:float,timezone:string|null},
     *     target_id:int|null,
     *     filter_config:array<string,mixed>,
     *     fingerprint:string,
     *     expires_at:mixed
     * }
     */
    private function prepareDraft(array $data): array
    {
        $type = AlertSubscriptionType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            throw ValidationException::withMessages(['type' => 'Choose a supported alert type.']);
        }

        $config = $this->configFor($type, $data);
        $eventKeys = $this->events->eventKeys($type, $config);
        if ($type !== AlertSubscriptionType::Market) {
            $config['events'] = $this->events->legacyEvents($type, $eventKeys);
        }
        $preferences = $this->deliveryPreferences($data);
        $targetId = isset($config['target_id']) ? (int) $config['target_id'] : null;
        $filterConfig = $type === AlertSubscriptionType::Market
            ? [
                'resource' => $config['resource'],
                'direction' => $config['direction'],
                'threshold' => $config['threshold'],
            ]
            : [];

        return [
            'type' => $type,
            'name' => isset($data['name']) ? trim((string) $data['name']) ?: null : null,
            'config' => $config,
            'event_keys' => $eventKeys,
            'preferences' => $preferences,
            'target_id' => $targetId,
            'filter_config' => $filterConfig,
            'fingerprint' => $this->fingerprint($type, $targetId, $filterConfig, $eventKeys),
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $config */
    private function draftDisplayName(AlertSubscriptionType $type, array $config): string
    {
        return match ($type) {
            AlertSubscriptionType::Nation => 'Nation #'.(int) $config['target_id'],
            AlertSubscriptionType::Alliance => 'Alliance #'.(int) $config['target_id'],
            AlertSubscriptionType::Market => ucfirst((string) $config['resource']).' price',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $eventKeys
     */
    private function draftCondition(
        AlertSubscriptionType $type,
        array $config,
        array $eventKeys,
    ): string {
        if ($type === AlertSubscriptionType::Market) {
            return sprintf(
                '%s %s %s',
                ucfirst((string) $config['resource']),
                (string) $config['direction'],
                number_format((float) $config['threshold'], 2),
            );
        }

        return collect($eventKeys)
            ->map(fn (string $eventKey): string => (string) str($eventKey)
                ->replace('.', ' ')
                ->headline()
                ->lower()
                ->ucfirst())
            ->join(', ');
    }

    /**
     * @param  array<string, mixed>  $filterConfig
     * @param  list<string>  $eventKeys
     */
    private function fingerprint(
        AlertSubscriptionType $type,
        ?int $targetId,
        array $filterConfig,
        array $eventKeys,
    ): string {
        sort($eventKeys);
        ksort($filterConfig);
        if (isset($filterConfig['threshold'])) {
            $filterConfig['threshold'] = sprintf('%.4F', (float) $filterConfig['threshold']);
        }

        return hash('sha256', json_encode([
            'type' => $type->value,
            'target_id' => $targetId,
            'filter_config' => $filterConfig,
            'event_keys' => $eventKeys,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertFingerprintAvailable(
        User $user,
        string $fingerprint,
        ?int $exceptSubscriptionId = null,
    ): void {
        if ($this->fingerprintExists($user, $fingerprint, $exceptSubscriptionId)) {
            throw ValidationException::withMessages([
                'alert' => 'An active alert already watches the same target, events, and threshold.',
            ]);
        }
    }

    private function fingerprintExists(
        User $user,
        string $fingerprint,
        ?int $exceptSubscriptionId = null,
    ): bool {
        return AlertSubscription::query()
            ->whereBelongsTo($user)
            ->where('active_fingerprint', $fingerprint)
            ->when($exceptSubscriptionId !== null, fn ($query) => $query->whereKeyNot($exceptSubscriptionId))
            ->exists();
    }

    private function expireSubscriptions(User $user): void
    {
        AlertSubscription::query()
            ->whereBelongsTo($user)
            ->where('status', '!=', AlertSubscriptionStatus::Expired->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'is_active' => false,
                'status' => AlertSubscriptionStatus::Expired->value,
                'status_reason' => 'expired',
                'active_fingerprint' => null,
                'updated_at' => now(),
            ]);
    }

    private function activeCount(User $user, ?int $exceptSubscriptionId = null): int
    {
        return AlertSubscription::query()
            ->whereBelongsTo($user)
            ->when($exceptSubscriptionId !== null, fn ($query) => $query->whereKeyNot($exceptSubscriptionId))
            ->active()
            ->count();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
