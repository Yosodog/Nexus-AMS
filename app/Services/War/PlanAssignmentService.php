<?php

namespace App\Services\War;

use App\Models\Nation;
use App\Models\War;
use App\Models\WarPlan;
use App\Models\WarPlanAssignment;
use App\Models\WarPlanSquad;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates plan assignments using NationMatchService and forms squads while respecting manual locks.
 *
 * Design Notes:
 * - Assignment generation is idempotent; we only update unlocked rows so manual overrides survive re-runs.
 * - Slots are approximated from active wars. Future improvements could incorporate mission status APIs.
 * - Squads are rebuilt for unlocked assignments to keep UI simple; locked assignments retain their squad.
 */
class PlanAssignmentService
{
    public function __construct(
        private readonly NationMatchService $matchService,
        private readonly CacheFactory $cacheFactory
    ) {}

    /**
     * Generate plan assignments and squads.
     *
     * @param  Collection<int, \App\Models\WarPlanTarget>  $targets
     * @param  Collection<int, Nation>  $friendlies
     * @return Collection<int, WarPlanAssignment>
     */
    public function generate(
        WarPlan $plan,
        Collection $targets,
        Collection $friendlies,
        bool $respectLocks = true
    ): Collection {
        $assignments = collect();
        $lock = $this->cacheFactory->store()->lock("plan:{$plan->id}:assign", (int) config('war.plan_defaults.lock_ttl', 30));

        try {
            $lock->block((int) config('war.cache.lock_timeout', 10), function () use (
                $plan,
                $targets,
                $friendlies,
                $respectLocks,
                &$assignments
            ) {
                $assignments = DB::transaction(function () use ($plan, $targets, $friendlies, $respectLocks) {
                    return $this->runGeneration($plan, $targets, $friendlies, $respectLocks);
                });
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Plan assignment lock timed out', [
                'plan_id' => $plan->id,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // noop
            }
        }

        return $assignments ?? collect();
    }

    /**
     * Execute assignment generation within a transaction.
     *
     * @param  Collection<int, \App\Models\WarPlanTarget>  $targets
     * @param  Collection<int, Nation>  $friendlies
     */
    protected function runGeneration(
        WarPlan $plan,
        Collection $targets,
        Collection $friendlies,
        bool $respectLocks
    ): Collection {
        $targets->loadMissing(['nation.military', 'nation.accountProfile']);
        $friendlies->loadMissing(['military', 'latestSignIn', 'accountProfile']);

        $existingAssignments = $plan->assignments()
            ->with(['friendlyNation', 'target'])
            ->get()
            ->groupBy('war_plan_target_id');

        $preferredTargetsPerNation = min(
            max(1, (int) $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );
        $preferredAssignmentsPerTarget = $this->preferredAssignmentsPerTarget(
            friendlyCount: $friendlies->count(),
            targetCount: $targets->count(),
            preferredTargetsPerNation: $preferredTargetsPerNation
        );

        $friendlySquadMatrix = $this->buildSquadMatrix($plan);

        $activityWindowHours = $this->activityWindowHours($plan);

        $friendlyAssignmentCounts = $plan->assignments()
            ->select('friendly_nation_id', DB::raw('count(*) as total'))
            ->groupBy('friendly_nation_id')
            ->pluck('total', 'friendly_nation_id');

        $friendlyProfiles = $this->buildFriendlyProfiles($plan, $friendlies, $friendlyAssignmentCounts);
        $friendlyStrength = $this->buildFriendlyStrength($friendlies, $activityWindowHours);
        $friendlyStrengthRanks = $friendlyStrength['ranks'];
        $friendlyActivityFlags = $friendlyStrength['active_flags'];

        $enemyThreatRanks = $this->buildEnemyThreatRanks($targets);

        $results = collect();
        $targetAssignmentsMap = [];
        $targetOpenings = [];

        foreach ($targets->sortByDesc('target_priority_score') as $target) {
            $targetAssignments = $existingAssignments->get($target->id, collect());
            $existingAverage = $targetAssignments->avg('match_score');

            $preserved = $respectLocks
                ? $targetAssignments->filter(fn (WarPlanAssignment $assignment) => $assignment->is_locked || $assignment->is_overridden || $assignment->status !== 'proposed')
                : collect();

            $preservedFriendlyIds = $preserved->pluck('friendly_nation_id')->all();

            $needed = max(0, $preferredAssignmentsPerTarget - $preserved->count());

            if ($needed === 0) {
                $results = $results->merge($preserved);

                continue;
            }

            $candidates = $this->scoreFriendlyCandidates(
                plan: $plan,
                target: $target,
                friendlyProfiles: $friendlyProfiles,
                preservedFriendlyIds: $preservedFriendlyIds,
                assignmentCounts: $friendlyAssignmentCounts,
                existingAverage: $existingAverage,
                friendlyStrengthRanks: $friendlyStrengthRanks,
                enemyThreatRank: $enemyThreatRanks[$target->id] ?? 0.0,
                activityWindowHours: $activityWindowHours
            );

            $selected = $this->selectCohesiveAssignments(
                candidates: $candidates,
                needed: $needed,
                preservedFriendlyIds: $preservedFriendlyIds,
                squadMatrix: $friendlySquadMatrix,
                preservedAssignments: $preserved
            );

            $finalFriendlyIds = array_merge($preservedFriendlyIds, $selected->pluck('friendly.id')->all());
            $targetAssignmentsMap[$target->id] = $finalFriendlyIds;
            $targetOpenings[$target->id] = max(0, $preferredAssignmentsPerTarget - count($finalFriendlyIds));

            // Delete unlocked assignments not in the final list.
            $targetAssignments
                ->filter(fn (WarPlanAssignment $assignment) => ! in_array($assignment->friendly_nation_id, $finalFriendlyIds, true))
                ->each(function (WarPlanAssignment $assignment) use (&$friendlyAssignmentCounts) {
                    if ($assignment->is_locked || $assignment->is_overridden) {
                        return;
                    }
                    $friendlyId = $assignment->friendly_nation_id;
                    $assignment->delete();
                    if ($friendlyId !== null) {
                        $this->decrementAssignmentCount($friendlyAssignmentCounts, (int) $friendlyId);
                    }
                });

            foreach ($selected as $candidate) {
                $friendly = $candidate['friendly'];
                $match = $candidate['match'];

                /** @var WarPlanAssignment $assignment */
                $assignment = WarPlanAssignment::query()->updateOrCreate(
                    [
                        'war_plan_id' => $plan->id,
                        'war_plan_target_id' => $target->id,
                        'friendly_nation_id' => $friendly->id,
                    ],
                    [
                        'match_score' => $match['score'],
                        'meta' => $match['meta'],
                        'status' => 'proposed',
                        'is_overridden' => false,
                        'is_locked' => false,
                    ]
                );

                $friendlyAssignmentCounts[$friendly->id] = ($friendlyAssignmentCounts[$friendly->id] ?? 0) + 1;

                $results->push($assignment);
            }

            // Include preserved assignments in results
            $results = $results->merge($preserved);
        }

        $additionalAssignments = $this->fillActiveFriendlies(
            $plan,
            $targets,
            $friendlies,
            $friendlyProfiles,
            $friendlyStrengthRanks,
            $enemyThreatRanks,
            $friendlyAssignmentCounts,
            $friendlySquadMatrix,
            $targetAssignmentsMap,
            $targetOpenings,
            $activityWindowHours,
            $respectLocks,
            $friendlyActivityFlags
        );

        $results = $results->merge($additionalAssignments);

        $this->rebuildSquads($plan, $respectLocks);

        return $plan->assignments()->with(['friendlyNation', 'target', 'squad'])->get();
    }

    /**
     * Score friendly candidates for an enemy target.
     *
     * @param  array<int>  $preservedFriendlyIds
     * @param  SupportCollection<int, int>  $assignmentCounts
     * @param  SupportCollection<int, array{
     *     friendly: Nation,
     *     available_slots: int,
     *     assignment_load: int,
     *     max_assignments: int,
     *     offensive_wars: int,
     *     defensive_wars: int
     * }>  $friendlyProfiles
     * @param  array<int, float>  $friendlyStrengthRanks
     */
    protected function scoreFriendlyCandidates(
        WarPlan $plan,
        \App\Models\WarPlanTarget $target,
        SupportCollection $friendlyProfiles,
        array $preservedFriendlyIds,
        SupportCollection $assignmentCounts,
        ?float $existingAverage = null,
        array $friendlyStrengthRanks = [],
        float $enemyThreatRank = 0.0,
        int $activityWindowHours = 72
    ): SupportCollection {
        if (! $target->nation) {
            return collect();
        }

        $available = $friendlyProfiles
            ->reject(function (array $profile, int $friendlyId) use ($preservedFriendlyIds) {
                return in_array($friendlyId, $preservedFriendlyIds, true);
            });

        $squadTolerance = $plan->squad_cohesion_tolerance;
        $cohesionReference = $existingAverage !== null ? $existingAverage / 100 : 0.5;

        $candidates = $available->map(function (array $profile, int $friendlyId) use (
            $target,
            $assignmentCounts,
            $squadTolerance,
            $cohesionReference,
            $friendlyStrengthRanks,
            $enemyThreatRank,
            $activityWindowHours
        ) {
            $friendly = $profile['friendly'];

            if (! $this->matchService->canAttack($friendly, $target->nation)) {
                return null;
            }

            $currentAssignments = $assignmentCounts[$friendlyId] ?? 0;
            $availableSlots = $profile['available_slots'];
            $effectiveSlots = $availableSlots - $currentAssignments;

            if ($effectiveSlots <= 0) {
                return null;
            }

            $existingOffensive = $profile['offensive_wars'] ?? (int) ($friendly->offensive_wars_count ?? 0);
            $offensiveCap = (int) config('war.slot_caps.default_offensive', 6);
            $remainingOffensive = $offensiveCap - ($existingOffensive + $currentAssignments);

            if ($remainingOffensive <= 0) {
                return null;
            }

            $context = [
                'available_slots' => max(0, $effectiveSlots),
                'assignment_load' => $currentAssignments,
                'max_assignments' => max(1, min($profile['max_assignments'], $remainingOffensive)),
                'cohesion_reference' => $cohesionReference,
                'cohesion_tolerance' => $squadTolerance,
                'enemy_tps' => $target->target_priority_score,
                'evaluation_mode' => 'auto',
                'friendly_strength_rank' => $friendlyStrengthRanks[$friendlyId] ?? 0.0,
                'enemy_threat_rank' => $enemyThreatRank,
                'activity_window_hours' => $activityWindowHours,
            ];

            $match = $this->matchService->evaluate($friendly, $target->nation, $context);

            $relativeFactor = $match['meta']['factors']['relative_power'] ?? 0.0;
            $relativePower = is_array($relativeFactor) ? ($relativeFactor['value'] ?? 0.0) : (float) $relativeFactor;
            $relativeTuning = config('war.nation_match.relative_power', []);
            $autoFloor = $relativeTuning['auto_floor'] ?? 0.18;

            if ($relativePower < $autoFloor) {
                return null;
            }

            $penalties = config('war.nation_match.penalties', []);
            $offPenalty = $penalties['offensive_load'] ?? 4;
            $defPenalty = $penalties['defensive_load'] ?? 6;

            $penalizedScore = max(0, $match['score']
                - ($existingOffensive + $currentAssignments) * $offPenalty
                - (($profile['defensive_wars'] ?? ($friendly->defensive_wars_count ?? 0)) * $defPenalty));

            if ($penalizedScore <= 0) {
                return null;
            }

            return [
                'friendly' => $friendly,
                'match' => [
                    'score' => $penalizedScore,
                    'meta' => $match['meta'],
                ],
            ];
        })->filter();

        return $candidates->sortByDesc(fn ($candidate) => $candidate['match']['score'])->values();
    }

    /**
     * Prefer intact squads when selecting candidates to keep units operating together.
     *
     * @param  SupportCollection<int, array{friendly:Nation, match:array{score:float, meta:array}}>  $candidates
     * @param  array<int>  $preservedFriendlyIds
     * @param  array<int, array<int>>  $squadMatrix
     * @param  SupportCollection<int, WarPlanAssignment>  $preservedAssignments
     * @return SupportCollection<int, array{friendly:Nation, match:array{score:float, meta:array}}>
     */
    protected function selectCohesiveAssignments(
        SupportCollection $candidates,
        int $needed,
        array $preservedFriendlyIds,
        array $squadMatrix,
        SupportCollection $preservedAssignments
    ): SupportCollection {
        if ($needed === 0) {
            return collect();
        }

        $strictMode = config('war.squads.strict_mode', true);
        $allowPartialFallback = config('war.squads.allow_partial_fallback', true);

        $prioritySquadMembers = $preservedAssignments
            ->flatMap(fn (WarPlanAssignment $assignment) => $squadMatrix[$assignment->friendly_nation_id] ?? [$assignment->friendly_nation_id])
            ->unique()
            ->values()
            ->all();

        // Move squadmates of preserved assignments to the front so we try to keep squads intact.
        $orderedCandidates = $candidates->partition(function ($candidate) use ($prioritySquadMembers) {
            return in_array($candidate['friendly']->id, $prioritySquadMembers, true);
        });

        /** @var SupportCollection<int, array{friendly:Nation, match:array{score:float, meta:array}}> $ordered */
        $ordered = $orderedCandidates[0]->merge($orderedCandidates[1])->values();

        $selected = collect();
        $selectedIds = $preservedFriendlyIds;
        $candidatesById = $ordered->keyBy(fn ($candidate) => $candidate['friendly']->id);

        $groupScores = [];

        foreach ($ordered as $candidate) {
            $friendlyId = $candidate['friendly']->id;

            if (in_array($friendlyId, $selectedIds, true)) {
                continue;
            }

            $eligibleGroup = array_values(array_filter(
                $squadMatrix[$friendlyId] ?? [$friendlyId],
                fn ($id) => ! in_array($id, $selectedIds, true) && $candidatesById->has($id)
            ));

            if (empty($eligibleGroup)) {
                continue;
            }

            $groupKey = implode('-', $eligibleGroup);

            if (isset($groupScores[$groupKey])) {
                continue;
            }

            $scores = array_map(fn ($id) => $candidatesById->get($id)['match']['score'], $eligibleGroup);
            $avgScore = array_sum($scores) / max(1, count($scores));
            $variancePenalty = (max($scores) - min($scores)) * 0.05;
            $bonus = array_intersect($eligibleGroup, $prioritySquadMembers) ? 1 : 0;

            $groupScores[$groupKey] = [
                'members' => $eligibleGroup,
                'score' => $avgScore - $variancePenalty + $bonus,
                'size' => count($eligibleGroup),
            ];
        }

        $groupQueue = collect($groupScores)->sort(function (array $left, array $right) {
            if ($left['score'] === $right['score']) {
                return $right['size'] <=> $left['size'];
            }

            return $right['score'] <=> $left['score'];
        });

        foreach ($groupQueue as $group) {
            if ($selected->count() >= $needed) {
                break;
            }

            $remainingSlots = $needed - $selected->count();

            if ($strictMode && $group['size'] > $remainingSlots) {
                continue;
            }

            if ($group['size'] > $remainingSlots) {
                continue;
            }

            foreach ($group['members'] as $id) {
                if (! $candidatesById->has($id) || in_array($id, $selectedIds, true)) {
                    continue;
                }

                $selected->push($candidatesById->get($id));
                $selectedIds[] = $id;

                if ($selected->count() >= $needed) {
                    break;
                }
            }
        }

        if ($selected->count() < $needed) {
            foreach ($ordered as $candidate) {
                if ($selected->count() >= $needed) {
                    break;
                }

                $friendlyId = $candidate['friendly']->id;

                if (in_array($friendlyId, $selectedIds, true)) {
                    continue;
                }

                $groupIds = array_values(array_filter(
                    $squadMatrix[$friendlyId] ?? [$friendlyId],
                    fn ($id) => ! in_array($id, $selectedIds, true) && $candidatesById->has($id)
                ));

                if (empty($groupIds)) {
                    continue;
                }

                $remainingSlots = $needed - $selected->count();

                if (count($groupIds) > $remainingSlots) {
                    if (! $allowPartialFallback) {
                        continue;
                    }

                    $groupIds = collect($groupIds)
                        ->sortByDesc(fn ($id) => $candidatesById->get($id)['match']['score'])
                        ->take($remainingSlots)
                        ->all();
                }

                foreach ($groupIds as $id) {
                    if ($selected->count() >= $needed) {
                        break;
                    }

                    if (! $candidatesById->has($id) || in_array($id, $selectedIds, true)) {
                        continue;
                    }

                    $selected->push($candidatesById->get($id));
                    $selectedIds[] = $id;
                }
            }
        }

        return $selected->values();
    }

    /**
     * Second-pass filler to keep active friendlies engaged on open targets without breaking cohesion.
     *
     * @param  array<int, float>  $friendlyStrengthRanks
     * @param  array<int, float>  $enemyThreatRanks
     * @param  array<int, array<int>>  $squadMatrix
     * @param  array<int, array<int>>  $targetAssignmentsMap
     * @param  array<int, int>  $targetOpenings
     * @param  array<int, bool>  $friendlyActivityFlags
     */
    protected function fillActiveFriendlies(
        WarPlan $plan,
        Collection $targets,
        Collection $friendlies,
        SupportCollection $friendlyProfiles,
        array $friendlyStrengthRanks,
        array $enemyThreatRanks,
        SupportCollection &$assignmentCounts,
        array $squadMatrix,
        array &$targetAssignmentsMap,
        array &$targetOpenings,
        int $activityWindowHours,
        bool $respectLocks,
        array $friendlyActivityFlags
    ): SupportCollection {
        $openTargets = $targets->filter(fn ($target) => ($targetOpenings[$target->id] ?? 0) > 0)
            ->sortByDesc('target_priority_score');

        if ($openTargets->isEmpty()) {
            return collect();
        }

        $activeFriendlyIds = $friendlies
            ->filter(fn (Nation $friendly) => $friendlyActivityFlags[$friendly->id] ?? false)
            ->pluck('id')
            ->all();

        if (empty($activeFriendlyIds)) {
            return collect();
        }

        $activeProfiles = $friendlyProfiles->only($activeFriendlyIds);
        $additional = collect();

        foreach ($openTargets as $target) {
            $needed = $targetOpenings[$target->id] ?? 0;

            if ($needed <= 0) {
                continue;
            }

            $preservedAssignments = $plan->assignments()
                ->where('war_plan_target_id', $target->id)
                ->get()
                ->filter(fn (WarPlanAssignment $assignment) => ! $respectLocks || $assignment->is_locked || $assignment->is_overridden || $assignment->status !== 'proposed');

            $preservedFriendlyIds = $targetAssignmentsMap[$target->id] ?? [];

            $candidates = $this->scoreFriendlyCandidates(
                plan: $plan,
                target: $target,
                friendlyProfiles: $activeProfiles,
                preservedFriendlyIds: $preservedFriendlyIds,
                assignmentCounts: $assignmentCounts,
                existingAverage: $target->assignments()->avg('match_score'),
                friendlyStrengthRanks: $friendlyStrengthRanks,
                enemyThreatRank: $enemyThreatRanks[$target->id] ?? 0.0,
                activityWindowHours: $activityWindowHours
            );

            $selected = $this->selectCohesiveAssignments(
                candidates: $candidates,
                needed: $needed,
                preservedFriendlyIds: $preservedFriendlyIds,
                squadMatrix: $squadMatrix,
                preservedAssignments: $preservedAssignments
            );

            foreach ($selected as $candidate) {
                $friendly = $candidate['friendly'];
                $match = $candidate['match'];

                /** @var WarPlanAssignment $assignment */
                $assignment = WarPlanAssignment::query()->updateOrCreate(
                    [
                        'war_plan_id' => $plan->id,
                        'war_plan_target_id' => $target->id,
                        'friendly_nation_id' => $friendly->id,
                    ],
                    [
                        'match_score' => $match['score'],
                        'meta' => $match['meta'],
                        'status' => 'proposed',
                        'is_overridden' => false,
                        'is_locked' => false,
                    ]
                );

                $assignmentCounts[$friendly->id] = ($assignmentCounts[$friendly->id] ?? 0) + 1;
                $targetAssignmentsMap[$target->id] = array_values(array_unique(array_merge(
                    $targetAssignmentsMap[$target->id] ?? [],
                    [$friendly->id]
                )));
                $targetOpenings[$target->id] = max(0, ($targetOpenings[$target->id] ?? 0) - 1);

                $additional->push($assignment);
            }
        }

        return $additional;
    }

    protected function preferredAssignmentsPerTarget(int $friendlyCount, int $targetCount, int $preferredTargetsPerNation): int
    {
        if ($friendlyCount <= 0 || $targetCount <= 0) {
            return 0;
        }

        $desiredAssignments = $friendlyCount * max(1, $preferredTargetsPerNation);
        $defensiveCap = (int) config('war.slot_caps.default_defensive', 3);

        return (int) min(
            $defensiveCap,
            max(1, ceil($desiredAssignments / $targetCount))
        );
    }

    protected function buildFriendlyStrength(Collection $friendlies, int $activityWindowHours): array
    {
        $avgCities = max(1, (float) ($friendlies->avg('num_cities') ?? 1));

        $scores = [];
        $activityFactors = [];
        $activeFlags = [];

        foreach ($friendlies as $friendly) {
            $activity = $this->recentActivityFromWindow($friendly->accountProfile?->last_active, $activityWindowHours);
            $activityFactors[$friendly->id] = $activity['value'];
            $activeFlags[$friendly->id] = $activity['hours_ago'] <= $activityWindowHours;

            $composition = $this->militaryCompositionScore($friendly);
            $cityFactor = $this->cityStrengthFactor($friendly->num_cities ?? 0, $avgCities);
            $mmrFactor = $this->normalizeValue($friendly->latestSignIn?->mmr_score ?? 0, 100);

            $scores[$friendly->id] = round(
                (0.35 * $composition) + (0.25 * $cityFactor) + (0.25 * $activity['value']) + (0.15 * $mmrFactor),
                4
            );
        }

        return [
            'scores' => $scores,
            'ranks' => $this->normalizeRanks($scores),
            'activity_factors' => $activityFactors,
            'active_flags' => $activeFlags,
        ];
    }

    /**
     * @return array<int, float>
     */
    protected function buildEnemyThreatRanks(Collection $targets): array
    {
        $scores = [];

        foreach ($targets as $target) {
            $scores[$target->id] = (float) ($target->target_priority_score ?? 0);
        }

        return $this->normalizeRanks($scores, true);
    }

    /**
     * @param  array<int, float>  $scores
     * @return array<int, float>
     */
    protected function normalizeRanks(array $scores, bool $useSpread = false): array
    {
        if (empty($scores)) {
            return [];
        }

        $values = array_values($scores);
        $max = max($values);
        $min = min($values);
        $range = $max - $min;

        $ranks = [];

        foreach ($scores as $key => $score) {
            if ($useSpread && $range > 0) {
                $ranks[$key] = round(max(0, ($score - $min) / $range), 4);

                continue;
            }

            $ranks[$key] = $max > 0 ? round(max(0, $score) / $max, 4) : 0.0;
        }

        return $ranks;
    }

    protected function recentActivityFromWindow(?CarbonInterface $lastSeen, int $activityWindowHours): array
    {
        $halfLife = max(1, $activityWindowHours / 2);
        $maxHours = max($activityWindowHours * 2, $activityWindowHours + 12);
        $hoursAgo = $lastSeen ? $lastSeen->diffInHours(Carbon::now()) : ($activityWindowHours + 1);
        $hoursAgo = min($maxHours, max(0, $hoursAgo));

        $factor = pow(0.5, $hoursAgo / $halfLife);

        return [
            'value' => round($factor, 4),
            'hours_ago' => (int) $hoursAgo,
        ];
    }

    protected function militaryCompositionScore(Nation $nation): float
    {
        $numCities = max(1, $nation->num_cities ?? 1);
        $military = $nation->military;

        if (! $military) {
            return 0.25;
        }

        $caps = [
            'soldiers' => 15_000 * $numCities,
            'tanks' => 1_250 * $numCities,
            'ships' => 15 * $numCities,
            'aircraft' => 75 * $numCities,
        ];

        $weights = [
            'soldiers' => 0.1,
            'ships' => 0.25,
            'tanks' => 0.3,
            'aircraft' => 0.35,
        ];

        $totals = [
            'soldiers' => $military->soldiers ?? 0,
            'tanks' => $military->tanks ?? 0,
            'ships' => $military->ships ?? 0,
            'aircraft' => $military->aircraft ?? 0,
        ];

        $score = 0.0;

        foreach ($weights as $unit => $weight) {
            $score += $weight * $this->normalizeValue($totals[$unit] ?? 0, $caps[$unit] ?? 1);
        }

        return round(min(1, $score), 4);
    }

    protected function cityStrengthFactor(int $friendlyCities, float $averageCities): float
    {
        $ratio = $averageCities <= 0 ? 0.0 : ($friendlyCities / $averageCities);
        $factor = $ratio / (1 + $ratio);

        return round(max(0, min(1, $factor)), 4);
    }

    protected function activityWindowHours(WarPlan $plan): int
    {
        return max(1, $plan->activity_window_hours ?? config('war.plan_defaults.activity_window_hours', 72));
    }

    protected function normalizeValue(float|int $value, float $cap): float
    {
        if ($cap <= 0) {
            return 0.0;
        }

        return round(min(1, max(0, $value) / $cap), 4);
    }

    /**
     * Rebuild squads for unlocked assignments.
     */
    protected function rebuildSquads(WarPlan $plan, bool $respectLocks): void
    {
        $labelPrefix = config('war.squads.label_prefix', 'Squad');
        $maxSize = max(1, $plan->max_squad_size);

        $assignments = $plan->assignments()->with(['target', 'squad'])->get();
        $squads = $plan->squads()->get()->keyBy('label');

        // Keep locked squads intact
        $lockedAssignmentIds = $assignments
            ->filter(fn (WarPlanAssignment $assignment) => $respectLocks && ($assignment->is_locked || $assignment->is_overridden))
            ->pluck('id')
            ->all();

        $friendlyPreferred = [];
        $squadSizes = [];
        $targetSquadPool = [];
        $maxExistingIndex = 0;

        foreach ($squads as $label => $squad) {
            $squadSizes[$label] = 0;
            $targetId = $squad->meta['target_id'] ?? null;
            if ($targetId !== null) {
                $targetSquadPool[$targetId][$label] = $squad;
            }

            if (preg_match('/'.preg_quote($labelPrefix, '/').'\s+(\d+)/i', $label, $matches)) {
                $maxExistingIndex = max($maxExistingIndex, (int) $matches[1]);
            }
        }

        foreach ($assignments as $assignment) {
            if (! $assignment->squad) {
                continue;
            }

            $label = $assignment->squad->label;
            $targetId = $assignment->war_plan_target_id;

            // Remember previous squad for continuity even if unlocked
            $friendlyPreferred[$assignment->friendly_nation_id] = $label;

            if (in_array($assignment->id, $lockedAssignmentIds, true)) {
                $squadSizes[$label] = ($squadSizes[$label] ?? 0) + 1;
            }

            if ($targetId !== null) {
                $targetSquadPool[$targetId][$label] = $assignment->squad;
            }
        }

        // Clear squad linkage for assignments we control
        WarPlanAssignment::query()
            ->where('war_plan_id', $plan->id)
            ->whereNotIn('id', $lockedAssignmentIds)
            ->update(['war_plan_squad_id' => null]);

        $unlocked = $assignments
            ->reject(fn (WarPlanAssignment $assignment) => in_array($assignment->id, $lockedAssignmentIds, true))
            ->sortByDesc('match_score')
            ->groupBy('war_plan_target_id');

        foreach ($unlocked as $targetId => $group) {
            $targetSquads = $targetSquadPool[$targetId] ?? [];

            foreach ($group->sortByDesc('match_score') as $assignment) {
                $preferredLabel = $friendlyPreferred[$assignment->friendly_nation_id] ?? null;
                $label = null;

                if ($preferredLabel && isset($squadSizes[$preferredLabel]) && $squadSizes[$preferredLabel] < $maxSize) {
                    $label = $preferredLabel;
                } else {
                    foreach ($targetSquads as $candidateLabel => $squad) {
                        if (($squadSizes[$candidateLabel] ?? 0) < $maxSize) {
                            $label = $candidateLabel;
                            break;
                        }
                    }
                }

                if (! $label) {
                    $maxExistingIndex++;
                    $label = "{$labelPrefix} {$maxExistingIndex}";
                    $newSquad = WarPlanSquad::query()->create([
                        'war_plan_id' => $plan->id,
                        'label' => $label,
                        'round' => 1,
                        'cohesion_score' => 0,
                        'meta' => ['target_id' => $targetId],
                    ]);
                    $squads[$label] = $newSquad;
                    $squadSizes[$label] = 0;
                    $targetSquads[$label] = $newSquad;
                    $targetSquadPool[$targetId][$label] = $newSquad;
                }

                $assignment->war_plan_squad_id = $squads[$label]->id;
                $assignment->save();

                $friendlyPreferred[$assignment->friendly_nation_id] = $label;
                $squadSizes[$label] = ($squadSizes[$label] ?? 0) + 1;

                $meta = $squads[$label]->meta ?? [];
                $meta['target_id'] = $targetId;
                $squads[$label]->meta = $meta;
                $squads[$label]->save();
            }
        }

        // Update cohesion scores and clean up empty squads
        foreach ($squads as $label => $squad) {
            if (! $squad->exists) {
                continue;
            }

            $assignmentCount = $squad->assignments()->count();
            if ($assignmentCount === 0) {
                $squad->delete();

                continue;
            }

            $cohesion = $squad->assignments()->avg('match_score');
            $squad->forceFill([
                'cohesion_score' => round($cohesion ?? 0, 2),
                'meta' => array_merge($squad->meta ?? [], [
                    'last_updated' => now()->toIso8601String(),
                ]),
            ])->save();
        }
    }

    /**
     * Estimate available offensive slots by subtracting active offensive wars.
     */
    protected function calculateAvailableSlots(Nation $friendly, int $activeOffensiveWars): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 6);

        $projects = method_exists($friendly, 'getProjectsAttribute') ? $friendly->projects : [];
        $projectModifiers = config('war.slot_caps.project_modifiers', []);

        foreach ($projectModifiers as $project => $modifier) {
            if (is_array($projects) && Arr::get($projects, $project, false)) {
                $base += $modifier;
            }
        }

        return max(0, $base - $activeOffensiveWars);
    }

    /**
     * Safely decrement assignment count tracking for a friendly nation.
     */
    protected function decrementAssignmentCount(SupportCollection &$assignmentCounts, int $friendlyId): void
    {
        $current = (int) ($assignmentCounts[$friendlyId] ?? 0);
        $assignmentCounts[$friendlyId] = max(0, $current - 1);
    }

    /**
     * Determine how many simultaneous assignments we allow for a nation before applying penalties.
     */
    protected function maxAssignmentsFor(Nation $friendly): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 6);

        if ($friendly->alliance_position === 'LEADER') {
            $base = max(1, $base - 1);
        }

        return $base;
    }

    /**
     * Build a quick lookup of squad companions by friendly nation ID.
     *
     * @return array<int, array<int>>
     */
    protected function buildSquadMatrix(WarPlan $plan): array
    {
        $matrix = [];

        $plan->squads()
            ->with(['assignments' => fn ($query) => $query->select('id', 'war_plan_squad_id', 'friendly_nation_id')])
            ->get()
            ->each(function (WarPlanSquad $squad) use (&$matrix) {
                $members = $squad->assignments
                    ->pluck('friendly_nation_id')
                    ->filter()
                    ->unique()
                    ->all();

                if (empty($members)) {
                    return;
                }

                foreach ($members as $memberId) {
                    $existing = $matrix[$memberId] ?? [$memberId];
                    $matrix[$memberId] = array_values(array_unique(array_merge($existing, $members)));
                }
            });

        return $matrix;
    }

    /**
     * Build cached friendly profiles so slot math and war counts are reused across targets.
     *
     * @param  SupportCollection<int, int>  $assignmentCounts
     * @return SupportCollection<int, array{
     *     friendly: Nation,
     *     available_slots: int,
     *     assignment_load: int,
     *     max_assignments: int,
     *     offensive_wars: int,
     *     defensive_wars: int
     * }>
     */
    protected function buildFriendlyProfiles(WarPlan $plan, Collection $friendlies, SupportCollection $assignmentCounts): SupportCollection
    {
        $friendliesById = $friendlies->keyBy('id');
        $activeWarCounts = $this->activeWarCounts($friendliesById->keys());
        $planPreference = min(
            max(1, (int) $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );

        return $friendliesById->map(function (Nation $friendly) use ($assignmentCounts, $activeWarCounts, $planPreference) {
            $friendlyId = $friendly->id;
            $assignmentLoad = (int) ($assignmentCounts[$friendlyId] ?? 0);
            $friendlyCounts = $activeWarCounts[$friendlyId] ?? ['offensive' => 0, 'defensive' => 0];
            $availableSlots = $this->calculateAvailableSlots($friendly, $friendlyCounts['offensive']);

            return [
                'friendly' => $friendly,
                'available_slots' => min($availableSlots, $planPreference),
                'assignment_load' => $assignmentLoad,
                'max_assignments' => min($this->maxAssignmentsFor($friendly), $planPreference),
                'offensive_wars' => (int) ($friendlyCounts['offensive'] ?? $friendly->offensive_wars_count ?? 0),
                'defensive_wars' => (int) ($friendlyCounts['defensive'] ?? $friendly->defensive_wars_count ?? 0),
            ];
        });
    }

    /**
     * Cache and return active offensive/defensive war counts for the provided nation IDs.
     *
     * @param  SupportCollection<int, int>  $friendlyIds
     * @return array<int, array{offensive:int, defensive:int}>
     */
    protected function activeWarCounts(SupportCollection $friendlyIds): array
    {
        $ids = $friendlyIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $cache = $this->cacheFactory->store();
        $cacheKey = 'war:active_wars:'.md5($ids->join(','));

        return $cache->remember(
            $cacheKey,
            (int) config('war.cache.active_war_counts_ttl', 60),
            function () use ($ids) {
                $attCounts = War::query()
                    ->whereNull('end_date')
                    ->whereIn('att_id', $ids)
                    ->selectRaw('att_id as nation_id, COUNT(*) as total')
                    ->groupBy('att_id')
                    ->pluck('total', 'nation_id');

                $defCounts = War::query()
                    ->whereNull('end_date')
                    ->whereIn('def_id', $ids)
                    ->selectRaw('def_id as nation_id, COUNT(*) as total')
                    ->groupBy('def_id')
                    ->pluck('total', 'nation_id');

                $counts = [];

                foreach ($ids as $id) {
                    $counts[$id] = [
                        'offensive' => (int) ($attCounts[$id] ?? 0),
                        'defensive' => (int) ($defCounts[$id] ?? 0),
                    ];
                }

                return $counts;
            }
        );
    }
}
