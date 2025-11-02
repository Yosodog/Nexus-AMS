<?php

namespace App\Services\War;

use App\Models\WarAttack;
use App\Models\WarCounter;
use App\Models\WarPlan;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fetches war attack feeds for plan and counter dashboards with lightweight caching.
 *
 * Design Notes:
 * - We memoize queries for short periods to keep dashboards snappy while respecting recent events.
 * - Filters are intentionally minimal; UI builds them via query params and we translate to scopes here.
 */
class LiveFeedQueryService
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Fetch attacks relevant to a plan.
     *
     * @param array{
     *     minutes?:int,
     *     hours?:int,
     *     attack_types?:array<int,string>,
     *     scope?:'ours'|'theirs'|'both',
     *     limit?:int
     * } $filters
     */
    public function forPlan(WarPlan $plan, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey('plan', $plan->id, $filters);

        return $this->cache->remember(
            $cacheKey,
            (int) config('war.cache.live_feed_ttl', 90),
            function () use ($plan, $filters) {
                $enemyIds = $plan->targets()->pluck('nation_id')->all();
                $friendlyIds = $plan->assignments()->pluck('friendly_nation_id')->unique()->all();

                $query = $this->baseQuery($filters);

                $scope = $filters['scope'] ?? 'both';

                $query->where(function (Builder $builder) use ($scope, $enemyIds, $friendlyIds) {
                    if (in_array($scope, ['both', 'ours'], true) && ! empty($friendlyIds)) {
                        $builder->whereIn('att_id', $friendlyIds)
                            ->orWhereIn('def_id', $friendlyIds);
                    }

                    if (in_array($scope, ['both', 'theirs'], true) && ! empty($enemyIds)) {
                        $builder->orWhereIn('att_id', $enemyIds)
                            ->orWhereIn('def_id', $enemyIds);
                    }
                });

                return $query
                    ->latest('date')
                    ->limit($filters['limit'] ?? config('war.live_feed.page_size', 25))
                    ->get();
            }
        );
    }

    /**
     * Fetch attacks relevant to a counter aggressor.
     *
     * @param array{
     *     minutes?:int,
     *     hours?:int,
     *     attack_types?:array<int,string>,
     *     limit?:int
     * } $filters
     */
    public function forCounter(WarCounter $counter, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey('counter', $counter->id, $filters);

        return $this->cache->remember(
            $cacheKey,
            (int) config('war.cache.live_feed_ttl', 90),
            function () use ($counter, $filters) {
                $query = $this->baseQuery($filters);

                $query->where(function (Builder $builder) use ($counter) {
                    $builder->where('att_id', $counter->aggressor_nation_id)
                        ->orWhere('def_id', $counter->aggressor_nation_id);
                });

                return $query
                    ->latest('date')
                    ->limit($filters['limit'] ?? 15)
                    ->get();
            }
        );
    }

    /**
     * Quick lookup for sidebar nation context.
     */
    public function recentForNation(int $nationId, int $limit = 5): Collection
    {
        return WarAttack::query()
            ->where(function (Builder $builder) use ($nationId) {
                $builder->where('att_id', $nationId)->orWhere('def_id', $nationId);
            })
            ->latest('date')
            ->limit($limit)
            ->get();
    }

    /**
     * Base query applying time and type filters.
     *
     * @param array{
     *     minutes?:int,
     *     hours?:int,
     *     attack_types?:array<int,string>
     * } $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = WarAttack::query()->with(['attacker', 'defender']);

        $windowMinutes = $this->resolveWindowMinutes($filters);

        $query->where('date', '>=', now()->subMinutes($windowMinutes));

        $attackTypes = $filters['attack_types'] ?? null;

        if (is_string($attackTypes)) {
            $attackTypes = array_filter(array_map('trim', explode(',', $attackTypes)));
        }

        if ($attackTypes) {
            $query->whereIn('type', $attackTypes);
        }

        return $query;
    }

    /**
     * Determine time window respecting configured maximum.
     *
     * @param  array{minutes?:int, hours?:int}  $filters
     */
    protected function resolveWindowMinutes(array $filters): int
    {
        $minutes = $filters['minutes'] ?? null;
        if ($minutes) {
            return min(
                $minutes,
                config('war.live_feed.max_window_hours', 24) * 60
            );
        }

        $hours = $filters['hours'] ?? config('war.live_feed.default_window_minutes', 60) / 60;

        return min(
            (int) $hours * 60,
            config('war.live_feed.max_window_hours', 24) * 60
        );
    }

    /**
     * Build a deterministic cache key from filters.
     */
    protected function cacheKey(string $prefix, int $id, array $filters): string
    {
        ksort($filters);

        return "war:feed:{$prefix}:{$id}:".md5(json_encode($filters));
    }
}
