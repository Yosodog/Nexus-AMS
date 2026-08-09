<?php

namespace App\Services\StaffWorkQueue;

use App\Enums\OperationsSensitivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class StaffWorkQueueRegistry implements OperationsReadStore
{
    private const CACHE_SCHEMA_VERSION = 2;

    /** @var array<string, StaffWorkQueueSource> */
    private array $sources;

    /** @var array<string, array<string, mixed>> */
    private array $requestSnapshots = [];

    /**
     * @param  iterable<StaffWorkQueueSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        $this->sources = [];

        foreach ($sources as $source) {
            if (isset($this->sources[$source->type()])) {
                throw new InvalidArgumentException("Duplicate staff work queue source [{$source->type()}].");
            }

            $this->sources[$source->type()] = $source;
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->flushCache();
        }

        $snapshots = [];

        foreach ($this->sources as $source) {
            $snapshots[] = $this->sourceSnapshot($source, null, $forceRefresh);
        }

        $snapshot = $this->combineSnapshots($snapshots, $this->typeLabels($this->sources));
        $this->mirrorLegacySnapshot($snapshot);

        return $snapshot;
    }

    /** @return array<string, mixed> */
    public function forUser(User $user, bool $forceRefresh = false): array
    {
        $allowedSources = $this->allowedSources($user);
        $allowedTypes = $this->typeLabels($allowedSources);

        if ($allowedSources === []) {
            return $this->combineSnapshots([], []);
        }

        $snapshots = [];

        foreach ($allowedSources as $source) {
            $snapshots[] = $this->sourceSnapshot($source, $user, $forceRefresh);
        }

        return $this->combineSnapshots($snapshots, $allowedTypes);
    }

    /** @return array<string, mixed>|null */
    public function findForUser(User $user, string $workKey, bool $forceRefresh = false): ?array
    {
        [$type] = array_pad(explode(':', $workKey, 2), 2, null);
        $source = $this->sources[$type] ?? null;

        if (! $source instanceof StaffWorkQueueSource || ! $this->sourceAuthorized($source, Gate::forUser($user))) {
            return null;
        }

        $snapshot = $this->sourceSnapshot($source, $user, $forceRefresh);

        foreach ($snapshot['items'] as $item) {
            if (($item['work_key'] ?? $item['key'] ?? null) === $workKey) {
                return $item;
            }
        }

        return null;
    }

    public function canView(User $user): bool
    {
        return $this->allowedTypes($user) !== [];
    }

    public function flushCache(?string $type = null): void
    {
        $sources = $type === null
            ? $this->sources
            : array_filter([$type => $this->sources[$type] ?? null]);

        foreach ($sources as $source) {
            if (! $source instanceof StaffWorkQueueSource) {
                continue;
            }

            $descriptor = $this->descriptor($source);

            foreach ([$this->sourceCacheKey($descriptor), $this->lastSuccessCacheKey($descriptor), $this->failureCacheKey($descriptor)] as $key) {
                Cache::forget($key);
            }
        }

        Cache::forget($this->legacyCacheKey());
        $this->requestSnapshots = [];
    }

    /**
     * @return array<string, string>
     */
    public function allowedTypes(User $user): array
    {
        return $this->typeLabels($this->allowedSources($user));
    }

    /** @return array<string, StaffWorkQueueSource> */
    private function allowedSources(User $user): array
    {
        $gate = Gate::forUser($user);

        return array_filter(
            $this->sources,
            fn (StaffWorkQueueSource $source): bool => $this->sourceAuthorized($source, $gate),
        );
    }

    private function sourceAuthorized(StaffWorkQueueSource $source, object $gate): bool
    {
        foreach ($this->descriptor($source)->viewAbilities as $ability) {
            if ($gate->allows($ability)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function sourceSnapshot(
        StaffWorkQueueSource $source,
        ?User $user,
        bool $forceRefresh,
    ): array {
        $descriptor = $this->descriptor($source);
        $memoKey = $this->memoKey($descriptor, $user);

        if ($forceRefresh) {
            foreach ([$this->sourceCacheKey($descriptor, $user), $this->failureCacheKey($descriptor, $user)] as $key) {
                Cache::forget($key);
            }
            unset($this->requestSnapshots[$memoKey]);
        }

        if (isset($this->requestSnapshots[$memoKey])) {
            return $this->withFreshness($this->requestSnapshots[$memoKey], $descriptor);
        }

        $failure = Cache::get($this->failureCacheKey($descriptor, $user));

        if ($this->isValidFailure($failure)) {
            $snapshot = $this->failedSnapshot($descriptor, $failure, $user);
            $this->requestSnapshots[$memoKey] = $snapshot;

            return $this->withFreshness($snapshot, $descriptor);
        }

        try {
            $snapshot = Cache::flexible(
                $this->sourceCacheKey($descriptor, $user),
                [$descriptor->freshSeconds, $descriptor->staleSeconds],
                function () use ($source, $descriptor, $user): array {
                    $snapshot = $this->buildSourceSnapshot($source, $descriptor);
                    Cache::put($this->lastSuccessCacheKey($descriptor, $user), $snapshot, now()->addDay());
                    Cache::forget($this->failureCacheKey($descriptor, $user));

                    return $snapshot;
                },
            );

            if (! $this->isValidSourceSnapshot($snapshot, $descriptor->type)) {
                throw new InvalidArgumentException("Operations source [{$descriptor->type}] returned an invalid cached snapshot.");
            }
        } catch (Throwable $exception) {
            $failure = [
                'schema' => self::CACHE_SCHEMA_VERSION,
                'label' => $descriptor->label,
                'error_code' => class_basename($exception),
                'failed_at' => now()->toIso8601String(),
            ];
            Cache::put(
                $this->failureCacheKey($descriptor, $user),
                $failure,
                now()->addSeconds((int) config('operations.source_failure_retry_seconds', 30)),
            );

            Log::error('Staff work queue source failed.', [
                'source' => $descriptor->type,
                'source_class' => $source::class,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $snapshot = $this->failedSnapshot($descriptor, $failure, $user);
        }

        $this->requestSnapshots[$memoKey] = $snapshot;

        return $this->withFreshness($snapshot, $descriptor);
    }

    /** @return array<string, mixed> */
    private function buildSourceSnapshot(
        StaffWorkQueueSource $source,
        StaffWorkQueueSourceDescriptor $descriptor,
    ): array {
        $result = $source instanceof StaffWorkQueueSourceV2
            ? $source->loadResult()
            : StaffWorkQueueSourceResult::complete($source->load());
        $items = [];
        $seenKeys = [];

        foreach ($result->items as $item) {
            if (! $item instanceof StaffWorkItem) {
                throw new InvalidArgumentException("Operations source [{$descriptor->type}] returned an invalid work item.");
            }

            if ($item->type !== $descriptor->type) {
                throw new InvalidArgumentException("Work queue source [{$descriptor->type}] returned an item for [{$item->type}].");
            }

            if (isset($seenKeys[$item->key()])) {
                throw new InvalidArgumentException("Operations source [{$descriptor->type}] returned duplicate work key [{$item->key()}].");
            }

            $seenKeys[$item->key()] = true;
            $serialized = $item->toArray();
            $serialized['team_key'] ??= $descriptor->teamKey;
            $serialized['team_key'] = $serialized['team_key'] ?: $descriptor->teamKey;
            $serialized['interested_teams'] = array_values(array_unique([
                ...$descriptor->interestedTeams,
                ...((array) ($serialized['interested_teams'] ?? [])),
            ]));
            $serialized['capability_keys'] = array_values(array_unique([
                ...$descriptor->actionKeys,
                ...((array) ($serialized['capability_keys'] ?? [])),
            ]));
            $serialized['sensitivity'] = $descriptor->sensitivity->value;
            $items[] = $serialized;
        }

        return [
            'schema' => self::CACHE_SCHEMA_VERSION,
            'type' => $descriptor->type,
            'descriptor' => $descriptor->toArray(),
            'items' => $items,
            'summary' => $result->summary,
            'source_observed_at' => $result->observedAt->toIso8601String(),
            'upstream_observed_at' => $result->upstreamObservedAt?->toIso8601String(),
            'projected_at' => now()->toIso8601String(),
            'complete' => $result->complete,
            'truncated' => $result->truncated,
            'warnings' => $result->warnings,
            'state' => $result->complete && ! $result->truncated ? 'available' : 'incomplete',
        ];
    }

    /** @return array<string, mixed> */
    private function failedSnapshot(
        StaffWorkQueueSourceDescriptor $descriptor,
        array $failure,
        ?User $user,
    ): array {
        $lastSuccess = Cache::get($this->lastSuccessCacheKey($descriptor, $user));
        $snapshot = $this->isValidSourceSnapshot($lastSuccess, $descriptor->type)
            ? $lastSuccess
            : [
                'schema' => self::CACHE_SCHEMA_VERSION,
                'type' => $descriptor->type,
                'descriptor' => $descriptor->toArray(),
                'items' => [],
                'summary' => [],
                'source_observed_at' => null,
                'upstream_observed_at' => null,
                'projected_at' => $failure['failed_at'],
                'warnings' => [],
            ];

        $snapshot['complete'] = false;
        $snapshot['truncated'] = false;
        $snapshot['state'] = 'failed';
        $snapshot['failure'] = [
            'label' => $descriptor->label,
            'error_code' => $failure['error_code'],
            'failed_at' => $failure['failed_at'],
        ];

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function withFreshness(array $snapshot, StaffWorkQueueSourceDescriptor $descriptor): array
    {
        $projectedAt = CarbonImmutable::parse((string) $snapshot['projected_at']);
        $now = now();
        $freshness = match (true) {
            ($snapshot['state'] ?? null) === 'failed' => 'stale',
            $now->lessThanOrEqualTo($projectedAt->addSeconds($descriptor->freshSeconds)) => 'fresh',
            $now->lessThanOrEqualTo($projectedAt->addSeconds($descriptor->staleSeconds)) => 'aging',
            default => 'stale',
        };
        $staleAfter = $projectedAt->addSeconds($descriptor->staleSeconds)->toIso8601String();

        $snapshot['freshness'] = $freshness;
        $snapshot['stale_after'] = $staleAfter;
        $snapshot['items'] = array_map(function (array $item) use ($snapshot, $freshness, $staleAfter): array {
            $item['projected_at'] = $snapshot['projected_at'];
            $item['source_observed_at'] = $snapshot['source_observed_at'];
            $item['upstream_observed_at'] = $snapshot['upstream_observed_at'];
            $item['stale_after'] = $staleAfter;
            $item['freshness'] = $freshness;
            $item['source_complete'] = (bool) $snapshot['complete'];
            $item['source_truncated'] = (bool) $snapshot['truncated'];

            if ($freshness === 'stale') {
                $item['attention_reasons'] = array_values(array_unique([
                    ...((array) ($item['attention_reasons'] ?? [])),
                    'stale_source',
                ]));
            }

            return $item;
        }, $snapshot['items']);

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function combineSnapshots(array $snapshots, array $types): array
    {
        $items = [];
        $counts = [];
        $failures = [];
        $sources = [];
        $complete = true;

        foreach ($snapshots as $snapshot) {
            $type = (string) $snapshot['type'];
            $items = [...$items, ...$snapshot['items']];

            if (($snapshot['state'] ?? null) !== 'failed' || $snapshot['items'] !== []) {
                $counts[$type] = count($snapshot['items']);
            }

            if (isset($snapshot['failure'])) {
                $failures[$type] = ['label' => (string) $snapshot['failure']['label']];
            }

            $sources[$type] = [
                'label' => $snapshot['descriptor']['label'],
                'team_key' => $snapshot['descriptor']['team_key'],
                'freshness' => $snapshot['freshness'],
                'complete' => (bool) $snapshot['complete'],
                'truncated' => (bool) $snapshot['truncated'],
                'projected_at' => $snapshot['projected_at'],
                'source_observed_at' => $snapshot['source_observed_at'],
                'upstream_observed_at' => $snapshot['upstream_observed_at'],
                'stale_after' => $snapshot['stale_after'],
                'warnings' => $snapshot['warnings'],
            ];
            $complete = $complete
                && (bool) $snapshot['complete']
                && ! (bool) $snapshot['truncated']
                && ($snapshot['state'] ?? null) !== 'failed';
        }

        return [
            'schema' => self::CACHE_SCHEMA_VERSION,
            'items' => array_values($items),
            'counts' => $counts,
            'failures' => $failures,
            'sources' => $sources,
            'generated_at' => now()->toIso8601String(),
            'complete' => $complete,
            'types' => $types,
            'total' => array_sum($counts),
        ];
    }

    private function descriptor(StaffWorkQueueSource $source): StaffWorkQueueSourceDescriptor
    {
        if ($source instanceof StaffWorkQueueSourceV2) {
            return $source->descriptor();
        }

        return new StaffWorkQueueSourceDescriptor(
            type: $source->type(),
            label: $source->label(),
            teamKey: 'systems',
            viewAbilities: [$source->ability()],
            freshSeconds: $this->legacyCacheTtl(),
            staleSeconds: $this->legacyCacheTtl() * 2,
            sensitivity: OperationsSensitivity::Restricted,
        );
    }

    /**
     * @param  array<string, StaffWorkQueueSource>  $sources
     * @return array<string, string>
     */
    private function typeLabels(array $sources): array
    {
        return collect($sources)
            ->mapWithKeys(fn (StaffWorkQueueSource $source): array => [$source->type() => $source->label()])
            ->all();
    }

    private function isValidSourceSnapshot(mixed $snapshot, string $type): bool
    {
        return is_array($snapshot)
            && ($snapshot['schema'] ?? null) === self::CACHE_SCHEMA_VERSION
            && ($snapshot['type'] ?? null) === $type
            && is_array($snapshot['descriptor'] ?? null)
            && is_array($snapshot['items'] ?? null)
            && is_array($snapshot['summary'] ?? null)
            && is_string($snapshot['projected_at'] ?? null)
            && is_bool($snapshot['complete'] ?? null)
            && is_bool($snapshot['truncated'] ?? null)
            && is_array($snapshot['warnings'] ?? null);
    }

    private function isValidFailure(mixed $failure): bool
    {
        return is_array($failure)
            && ($failure['schema'] ?? null) === self::CACHE_SCHEMA_VERSION
            && is_string($failure['label'] ?? null)
            && is_string($failure['error_code'] ?? null)
            && is_string($failure['failed_at'] ?? null);
    }

    private function memoKey(StaffWorkQueueSourceDescriptor $descriptor, ?User $user): string
    {
        return $descriptor->type.':'.($descriptor->cacheScope === 'user' ? (string) $user?->getKey() : 'shared');
    }

    private function sourceCacheKey(StaffWorkQueueSourceDescriptor $descriptor, ?User $user = null): string
    {
        return $this->baseCacheKey().':'.$this->memoKey($descriptor, $user);
    }

    private function lastSuccessCacheKey(StaffWorkQueueSourceDescriptor $descriptor, ?User $user = null): string
    {
        return $this->sourceCacheKey($descriptor, $user).':last-success';
    }

    private function failureCacheKey(StaffWorkQueueSourceDescriptor $descriptor, ?User $user = null): string
    {
        return $this->sourceCacheKey($descriptor, $user).':failure';
    }

    private function baseCacheKey(): string
    {
        return $this->legacyCacheKey().'.operations.v2';
    }

    private function legacyCacheKey(): string
    {
        return (string) config('pending_requests.projection_cache_key', 'pending_requests.work_queue.v1');
    }

    /** @param  array<string, mixed>  $snapshot */
    private function mirrorLegacySnapshot(array $snapshot): void
    {
        Cache::put($this->legacyCacheKey(), [
            'schema' => 1,
            'items' => $snapshot['items'],
            'counts' => $snapshot['counts'],
            'failures' => $snapshot['failures'],
            'generated_at' => $snapshot['generated_at'],
        ], now()->addSeconds($this->legacyCacheTtl()));
    }

    private function legacyCacheTtl(): int
    {
        $configured = (int) config('pending_requests.cache_ttl_seconds', 900);

        return min(max($configured, 600), 1800);
    }
}
