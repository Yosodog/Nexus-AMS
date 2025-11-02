<?php

namespace App\Services\War;

use App\Events\AssignmentsPublished;
use App\Events\WarPlanActivated;
use App\Jobs\AutoGeneratePlanAssignmentsJob;
use App\Jobs\RecomputePlanTPSJob;
use App\Models\WarPlan;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinates the lifecycle of war plans and maintains suppression state shared with counters.
 *
 * Design Notes:
 * - We persist top-level options on the plan record so queries render instantly without JSON parsing.
 * - Enemy alliance suppression is cached because it is a hot-path check for counter creation.
 * - Locks are intentionally short; background jobs are idempotent and will reschedule on contention.
 */
class PlanOrchestratorService
{
    private const CACHE_KEY_ACTIVE_ENEMIES = 'war:plans:active_enemy_alliances';

    public function __construct(
        private readonly CacheFactory $cacheFactory
    ) {}

    /**
     * Create a new plan with sensible defaults and associated alliances.
     *
     * @param array{
     *     name:string,
     *     plan_type?:string,
     *     friendly_alliances?:array<int,int>,
     *     enemy_alliances?:array<int,int>,
     *     options?:array<string,mixed>,
     *     preferred_nations_per_target?:int,
     *     max_squad_size?:int,
     *     squad_cohesion_tolerance?:int,
     *     activity_window_hours?:int,
     *     suppress_counters_when_active?:bool
     * } $attributes
     *
     * @throws Throwable
     */
    public function createPlan(array $attributes): WarPlan
    {
        return DB::transaction(function () use ($attributes): WarPlan {
            $normalized = $this->applyPlanDefaults($attributes);

            /** @var WarPlan $plan */
            $plan = WarPlan::query()->create(Arr::except($normalized, ['friendly_alliances', 'enemy_alliances']));

            $this->syncAlliances($plan, $normalized['friendly_alliances'], 'friendly');
            $this->syncAlliances($plan, $normalized['enemy_alliances'], 'enemy');

            $this->refreshSuppressionCache();

            return $plan;
        });
    }

    /**
     * Update plan settings in bulk and refresh cached suppression state when relevant.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    public function updatePlan(WarPlan $plan, array $attributes): WarPlan
    {
        return DB::transaction(function () use ($plan, $attributes): WarPlan {
            $normalized = $this->applyPlanDefaults($attributes, $plan);

            $plan->fill(Arr::except($normalized, ['friendly_alliances', 'enemy_alliances']))->save();

            if (array_key_exists('friendly_alliances', $normalized)) {
                $this->syncAlliances($plan, $normalized['friendly_alliances'], 'friendly');
            }

            if (array_key_exists('enemy_alliances', $normalized)) {
                $this->syncAlliances($plan, $normalized['enemy_alliances'], 'enemy');
            }

            if ($plan->wasChanged('suppress_counters_when_active') || array_key_exists('enemy_alliances', $normalized)) {
                $this->refreshSuppressionCache();
            }

            return $plan->refresh();
        });
    }

    /**
     * Activate a plan, marking activation time and triggering suppression cache rebuild.
     */
    public function activatePlan(WarPlan $plan): WarPlan
    {
        $plan->fill([
            'status' => 'active',
            'activated_at' => Carbon::now(),
        ])->save();

        $this->refreshSuppressionCache();

        $plan = $plan->refresh();

        event(new WarPlanActivated($plan));

        return $plan;
    }

    /**
     * Archive a plan and release it from suppression logic.
     */
    public function archivePlan(WarPlan $plan): WarPlan
    {
        $plan->fill([
            'status' => 'archived',
            'archived_at' => Carbon::now(),
        ])->save();

        $this->refreshSuppressionCache();

        return $plan->refresh();
    }

    /**
     * Mark assignments as published.
     */
    public function markAssignmentsPublished(WarPlan $plan): WarPlan
    {
        $plan->fill(['assignments_published_at' => Carbon::now()])->save();
        $plan = $plan->refresh();

        event(new AssignmentsPublished($plan));

        return $plan;
    }

    /**
     * Request asynchronous recomputation for TPS and assignments.
     */
    public function triggerRecompute(WarPlan $plan, bool $alsoAssign = false): void
    {
        RecomputePlanTPSJob::dispatch($plan->id);

        if ($alsoAssign) {
            AutoGeneratePlanAssignmentsJob::dispatch($plan->id);
        }
    }

    /**
     * Return cached set of enemy alliance IDs for active plans.
     *
     * @return array<int, int>
     */
    public function getActiveEnemyAllianceIds(bool $forceRefresh = false): array
    {
        $cache = $this->cache();

        if ($forceRefresh) {
            $cache->forget(self::CACHE_KEY_ACTIVE_ENEMIES);
        }

        return $cache->remember(
            self::CACHE_KEY_ACTIVE_ENEMIES,
            (int) config('war.cache.active_enemy_alliances_ttl', 300),
            fn () => $this->collectActiveEnemyAlliances()->all()
        );
    }

    /**
     * Hard refresh the suppression cache; used when plans or alliances change.
     */
    public function refreshSuppressionCache(): void
    {
        $cache = $this->cache();
        $cache->forget(self::CACHE_KEY_ACTIVE_ENEMIES);
        $cache->remember(
            self::CACHE_KEY_ACTIVE_ENEMIES,
            (int) config('war.cache.active_enemy_alliances_ttl', 300),
            fn () => $this->collectActiveEnemyAlliances()->all()
        );
    }

    /**
     * Compile distinct enemy alliance IDs from active plans.
     *
     * @return Collection<int, int>
     */
    protected function collectActiveEnemyAlliances(): Collection
    {
        $plans = WarPlan::query()
            ->active()
            ->with('enemyAlliances')
            ->get();

        return $plans->flatMap(function (WarPlan $plan) {
            if (! $plan->suppress_counters_when_active) {
                return collect();
            }

            return $plan->enemyAlliances->pluck('alliance_id');
        })->unique()->values();
    }

    /**
     * Sync the provided alliance IDs for the given role.
     *
     * @param  array<int, int>  $ids
     */
    protected function syncAlliances(WarPlan $plan, array $ids, string $role): void
    {
        $existing = $plan->alliances()->where('role', $role)->get()->keyBy('alliance_id');

        $ids = collect($ids)->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique();

        if ($ids->isEmpty()) {
            $plan->alliances()->where('role', $role)->delete();

            return;
        }

        $ids->diff($existing->keys())->each(function (int $id) use ($plan, $role) {
            $plan->alliances()->create([
                'alliance_id' => $id,
                'role' => $role,
                'meta' => ['origin' => 'manual'],
            ]);
        });

        $existing->keys()->diff($ids)->each(function (int $id) use ($plan, $role) {
            $plan->alliances()->where([
                'alliance_id' => $id,
                'role' => $role,
            ])->delete();
        });
    }

    /**
     * Apply defaults without overriding explicit nulls.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function applyPlanDefaults(array $attributes, ?WarPlan $existing = null): array
    {
        $defaults = [
            'plan_type' => config('war.plan_defaults.plan_type', 'ordinary'),
            'preferred_nations_per_target' => config('war.plan_defaults.preferred_nations_per_target', 3),
            'max_squad_size' => config('war.squads.max_size', 3),
            'squad_cohesion_tolerance' => config('war.squads.cohesion_tolerance', 10),
            'activity_window_hours' => config('war.plan_defaults.activity_window_hours', 72),
            'suppress_counters_when_active' => config('war.plan_defaults.suppress_counters_when_active', true),
        ];

        $payload = $existing
            ? array_merge($defaults, $existing->only(array_keys($defaults)), $attributes)
            : array_merge($defaults, $attributes);

        $payload['plan_type'] = $this->sanitizeWarType($payload['plan_type'] ?? $defaults['plan_type']);

        $payload['options'] = Arr::get($attributes, 'options', $existing?->options ?? []);

        $payload['friendly_alliances'] = Arr::get(
            $attributes,
            'friendly_alliances',
            $existing ? $existing->friendlyAlliances()->pluck('alliance_id')->all() : []
        );

        $payload['enemy_alliances'] = Arr::get(
            $attributes,
            'enemy_alliances',
            $existing ? $existing->enemyAlliances()->pluck('alliance_id')->all() : []
        );

        return $payload;
    }

    /**
     * Ensure provided war type matches supported list.
     */
    protected function sanitizeWarType(?string $warType): string
    {
        $warType = $warType ? strtolower($warType) : null;
        $allowed = array_keys(config('war.war_types', []));

        return in_array($warType, $allowed, true)
            ? $warType
            : config('war.plan_defaults.plan_type', 'ordinary');
    }

    /**
     * Resolve cache instance for internal use.
     */
    protected function cache(): CacheRepository
    {
        return $this->cacheFactory->store();
    }
}
