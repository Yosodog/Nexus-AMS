<?php

namespace App\Services\War;

use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\War;
use App\Models\WarPlan;
use App\Models\WarPlanTarget;
use App\Services\AllianceMembershipService;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calculates Target Priority Scores (TPS) for enemy nations within a war plan.
 *
 * Design Notes:
 * - Scores are cached per (plan, enemy) for a short TTL to absorb dashboard refresh spam.
 * - We persist factor breakdowns for leadership tooltips and future tuning.
 * - Scarcity is derived from friendly candidate availability relative to preferred assignment count.
 */
class TargetPriorityService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly CacheFactory $cacheFactory
    ) {}

    /**
     * Calculate and persist TPS for the provided enemies.
     *
     * @param  Collection<int, \App\Models\Nation>  $enemies
     * @param  Collection<int, \App\Models\Nation>  $friendlyPool
     * @return Collection<int, WarPlanTarget>
     */
    public function computeAndStore(WarPlan $plan, Collection $enemies, Collection $friendlyPool): Collection
    {
        $enemies->loadMissing(['accountProfile', 'military']);
        $friendlyPool->loadMissing(['military', 'latestSignIn']);

        $results = collect();

        $cache = $this->cacheFactory->store();
        $ttl = (int) config('war.target_priority.default_ttl', 600);
        $lockSeconds = (int) config('war.plan_defaults.lock_ttl', 30);

        $averageEnemyCities = max(1, (float) ($enemies->avg('num_cities') ?? 1));
        $averageFriendlyCities = max(
            1,
            (float) ($friendlyPool->avg('num_cities') ?? $averageEnemyCities)
        );
        $preferredTargetsPerNation = min(
            max(1, (int) $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );
        $friendlyPerTargetBaseline = $enemies->count() > 0
            ? max(1, (int) ceil(($friendlyPool->count() * $preferredTargetsPerNation) / $enemies->count()))
            : $preferredTargetsPerNation;
        $friendlyPerTargetBaseline = min(
            (int) config('war.slot_caps.default_defensive', 3),
            $friendlyPerTargetBaseline
        );
        $activityWindowHours = $this->activityWindowHours($plan);

        $membershipIds = $this->membershipService->getAllianceIds();

        $friendlyAvailabilityRatio = $friendlyPool->count() > 0
            ? min(1, $friendlyPool->count() / ($friendlyPerTargetBaseline * max(1, $enemies->count())))
            : 0;

        foreach ($enemies as $enemy) {
            $cacheKey = $this->cacheKey($plan->id, $enemy->id);

            $payload = $cache->get($cacheKey);
            if (! $payload) {
                $lock = $cache->lock("lock:{$cacheKey}", $lockSeconds);

                try {
                    if ($lock->get()) {
                        $payload = $this->buildPayload(
                            plan: $plan,
                            enemy: $enemy,
                            membershipIds: $membershipIds->all(),
                            averageEnemyCities: $averageEnemyCities,
                            averageFriendlyCities: $averageFriendlyCities,
                            scarcityRatio: $friendlyAvailabilityRatio,
                            activityWindowHours: $activityWindowHours
                        );

                        $cache->put($cacheKey, $payload, $ttl);
                    } else {
                        $lock->block($lockSeconds, function () use (&$payload, $cache, $cacheKey) {
                            $payload = $cache->get($cacheKey);
                        });
                    }
                } catch (LockTimeoutException $exception) {
                    Log::warning('TPS lock acquisition timed out', [
                        'plan_id' => $plan->id,
                        'nation_id' => $enemy->id,
                        'message' => $exception->getMessage(),
                    ]);
                    $payload = $this->buildPayload(
                        $plan,
                        $enemy,
                        $membershipIds->all(),
                        $averageEnemyCities,
                        $averageFriendlyCities,
                        $friendlyAvailabilityRatio,
                        $activityWindowHours
                    );
                } finally {
                    try {
                        $lock->release();
                    } catch (Throwable) {
                        // Lock already released.
                    }
                }
            }

            $targetModel = WarPlanTarget::query()->firstOrNew([
                'war_plan_id' => $plan->id,
                'nation_id' => $enemy->id,
            ]);

            if (! $targetModel->exists && ! $targetModel->preferred_war_type) {
                $targetModel->preferred_war_type = $plan->plan_type;
            }

            $targetModel->fill([
                'target_priority_score' => $payload['score'],
                'meta' => $payload['meta'],
                'computed_at' => Carbon::now(),
            ])->save();

            $results->push($targetModel);
        }

        return new Collection($results->all());
    }

    /**
     * Build the structured payload for an enemy TPS calculation.
     *
     * @param  int[]  $membershipIds
     * @return array{score: float, meta: array<string, mixed>}
     */
    protected function buildPayload(
        WarPlan $plan,
        Nation $enemy,
        array $membershipIds,
        float $averageEnemyCities,
        float $averageFriendlyCities,
        float $scarcityRatio,
        int $activityWindowHours
    ): array {
        $weights = collect(config('war.target_priority.weights', []));
        $strategicAdjustments = config('war.target_priority.strategic_adjustments', []);
        $unitWeights = config('war.target_priority.unit_weights', []);

        $positionScores = [
            'LEADER' => 1.0,
            'HEIR' => 0.92,
            'OFFICER' => 0.85,
            'MEMBER' => 0.6,
            'APPLICANT' => 0.1,
            'NOALLIANCE' => 0.2,
        ];

        $meta = [
            'weights' => $weights,
            'factors' => [],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $score = 0.0;

        $positionFactor = $positionScores[$enemy->alliance_position ?? 'MEMBER'] ?? 0.5;
        $meta['factors']['alliance_position'] = $positionFactor;
        $score += $weights->get('alliance_position', 0) * $positionFactor * 100;

        $citySize = $this->citySizeFactor($enemy->num_cities ?? 0, $averageEnemyCities);
        $meta['factors']['city_size'] = $citySize;
        $score += $weights->get('city_size', 0) * $citySize * 100;

        $cityAdvantage = $this->cityAdvantageFactor($enemy->num_cities ?? 0, $averageFriendlyCities);
        $meta['factors']['city_advantage'] = $cityAdvantage;
        $score += $weights->get('city_advantage', 0) * $cityAdvantage * 100;

        $recentActivityFactor = $this->planAwareRecentActivityFactor($activityWindowHours, $enemy->accountProfile);
        $meta['factors']['recent_activity'] = $recentActivityFactor;
        $score += $weights->get('recent_activity', 0) * $recentActivityFactor * 100;

        $composition = $this->militaryCompositionFactor($enemy, $unitWeights);
        $meta['factors']['military_composition'] = $composition;
        $score += $weights->get('military_composition', 0) * $composition * 100;

        $militaryOutput = $this->normalize($enemy->total_infrastructure_destroyed ?? 0, 1_000_000);
        $meta['factors']['military_output'] = $militaryOutput;
        $score += $weights->get('military_output', 0) * $militaryOutput * 100;

        $scarcityFactor = 1 - $scarcityRatio;
        $meta['factors']['scarcity'] = $scarcityFactor;
        $score += $weights->get('scarcity', 0) * $scarcityFactor * 100;

        $strategicSum = 0;
        if ($this->isAtWarWithUs($enemy->id, $membershipIds)) {
            $strategicSum += $strategicAdjustments['at_war_with_us'] ?? 0;
        }
        if ($enemy->vacation_mode_turns > 0) {
            $strategicSum += $strategicAdjustments['vacation_mode'] ?? 0;
        }
        if ($enemy->beige_turns > 0) {
            $strategicSum += $strategicAdjustments['beige'] ?? 0;
        }
        if ($enemy->offensive_wars_count > 0 || $enemy->defensive_wars_count > 0) {
            $strategicSum += $strategicAdjustments['declared_recently'] ?? 0;
        }
        $meta['factors']['strategic_flags'] = $strategicSum;
        $score += $weights->get('strategic_flags', 0) * $strategicSum;

        $warsWonFactor = $this->normalize($enemy->wars_won ?? 0, 100);
        $meta['factors']['wars_won'] = $warsWonFactor;
        $score += $weights->get('wars_won', 0) * $warsWonFactor * 100;

        $infraDestroyed = $this->normalize($enemy->total_infrastructure_destroyed ?? 0, 100_000);
        $meta['factors']['infrastructure_destroyed'] = $infraDestroyed;
        $score += $weights->get('infrastructure_destroyed', 0) * $infraDestroyed * 100;

        $bounded = max(
            config('war.target_priority.bounded_range.0', 0),
            min($score, config('war.target_priority.bounded_range.1', 100))
        );

        $meta['raw_score'] = $score;
        $meta['bounded'] = $bounded;

        return [
            'score' => round($bounded, 2),
            'meta' => $meta,
        ];
    }

    protected function planAwareRecentActivityFactor(int $activityWindowHours, ?NationAccount $account): float
    {
        $halfLife = max(1, $activityWindowHours / 2);
        $maxHours = max($activityWindowHours * 2, $activityWindowHours + 12);
        $lastSeen = $account?->last_active ?? Carbon::now()->subHours($activityWindowHours + 1);
        $hoursAgo = min($maxHours, max(0, $lastSeen->diffInHours(Carbon::now())));

        $factor = pow(0.5, $hoursAgo / $halfLife);

        return round($factor, 4);
    }

    protected function militaryCompositionFactor(Nation $enemy, array $unitWeights): float
    {
        $numCities = max(1, $enemy->num_cities ?? 1);
        $military = $enemy->military;

        if (! $military) {
            return 0.3;
        }

        $caps = [
            'soldiers' => 15_000 * $numCities,
            'tanks' => 1_250 * $numCities,
            'ships' => 15 * $numCities,
            'aircraft' => 75 * $numCities,
        ];

        $weights = array_merge([
            'soldiers' => 0.1,
            'ships' => 0.25,
            'tanks' => 0.3,
            'aircraft' => 0.35,
        ], $unitWeights);

        $totals = [
            'soldiers' => $military->soldiers ?? 0,
            'tanks' => $military->tanks ?? 0,
            'ships' => $military->ships ?? 0,
            'aircraft' => $military->aircraft ?? 0,
        ];

        $score = 0.0;

        foreach ($weights as $unit => $weight) {
            $cap = $caps[$unit] ?? 1;
            $score += $weight * $this->normalize($totals[$unit] ?? 0, $cap);
        }

        return round(min(1, $score), 4);
    }

    protected function citySizeFactor(int $enemyCities, float $averageEnemyCities): float
    {
        $sizeRatio = ($averageEnemyCities <= 0) ? 0.0 : ($enemyCities / $averageEnemyCities);
        $sizeFactor = $sizeRatio / (1 + $sizeRatio);

        return round(max(0, min(1, $sizeFactor)), 4);
    }

    protected function cityAdvantageFactor(int $enemyCities, float $averageFriendlyCities): float
    {
        $delta = $enemyCities - $averageFriendlyCities;

        if ($delta <= 0) {
            return 0.0;
        }

        $softness = max(3, $averageFriendlyCities / 2);
        $advantageFactor = $delta / ($delta + $softness);

        return round(max(0, min(1, $advantageFactor)), 4);
    }

    protected function activityWindowHours(WarPlan $plan): int
    {
        return max(1, $plan->activity_window_hours ?? config('war.plan_defaults.activity_window_hours', 72));
    }

    /**
     * Determine whether the nation currently has an active war against us.
     *
     * @param  int[]  $membershipIds
     */
    protected function isAtWarWithUs(int $enemyNationId, array $membershipIds): bool
    {
        return War::query()
            ->where(function ($query) use ($enemyNationId, $membershipIds) {
                $query->where('att_id', $enemyNationId)
                    ->whereIn('def_alliance_id', $membershipIds);
            })
            ->orWhere(function ($query) use ($enemyNationId, $membershipIds) {
                $query->where('def_id', $enemyNationId)
                    ->whereIn('att_alliance_id', $membershipIds);
            })
            ->whereNull('end_date')
            ->exists();
    }

    /**
     * Normalize raw values into a 0-1 range against a soft cap.
     */
    protected function normalize(float|int $value, float $cap): float
    {
        if ($cap <= 0) {
            return 0;
        }

        return round(min(1, $value / $cap), 4);
    }

    protected function cacheKey(int $planId, int $nationId): string
    {
        return "war:plan:{$planId}:tps:{$nationId}";
    }
}
