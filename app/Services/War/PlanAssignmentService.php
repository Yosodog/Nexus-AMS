<?php

namespace App\Services\War;

use App\Models\Nation;
use App\Models\War;
use App\Models\WarPlan;
use App\Models\WarPlanAssignment;
use App\Models\WarPlanSquad;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
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
        $existingAssignments = $plan->assignments()
            ->with(['friendlyNation', 'target'])
            ->get()
            ->groupBy('war_plan_target_id');

        $friendlySquadMatrix = $this->buildSquadMatrix($plan);

        $friendlyAssignmentCounts = $plan->assignments()
            ->select('friendly_nation_id', DB::raw('count(*) as total'))
            ->groupBy('friendly_nation_id')
            ->pluck('total', 'friendly_nation_id');

        $results = collect();

        foreach ($targets->sortByDesc('target_priority_score') as $target) {
            $targetAssignments = $existingAssignments->get($target->id, collect());

            $preserved = $respectLocks
                ? $targetAssignments->filter(fn (WarPlanAssignment $assignment) => $assignment->is_locked || $assignment->is_overridden || $assignment->status !== 'proposed')
                : collect();

            $preservedFriendlyIds = $preserved->pluck('friendly_nation_id')->all();

            $needed = max(0, $plan->preferred_nations_per_target - $preserved->count());

            if ($needed === 0) {
                $results = $results->merge($preserved);

                continue;
            }

            $candidates = $this->scoreFriendlyCandidates(
                plan: $plan,
                target: $target,
                friendlies: $friendlies,
                preservedFriendlyIds: $preservedFriendlyIds,
                assignmentCounts: $friendlyAssignmentCounts
            );

            $selected = $this->selectCohesiveAssignments(
                candidates: $candidates,
                needed: $needed,
                preservedFriendlyIds: $preservedFriendlyIds,
                squadMatrix: $friendlySquadMatrix,
                preservedAssignments: $preserved
            );

            $finalFriendlyIds = array_merge($preservedFriendlyIds, $selected->pluck('friendly.id')->all());

            // Delete unlocked assignments not in the final list.
            $targetAssignments
                ->filter(fn (WarPlanAssignment $assignment) => ! in_array($assignment->friendly_nation_id, $finalFriendlyIds, true))
                ->each(function (WarPlanAssignment $assignment) {
                    if ($assignment->is_locked || $assignment->is_overridden) {
                        return;
                    }
                    $assignment->delete();
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

        $this->rebuildSquads($plan, $respectLocks);

        return $plan->assignments()->with(['friendlyNation', 'target', 'squad'])->get();
    }

    /**
     * Score friendly candidates for an enemy target.
     *
     * @param  array<int>  $preservedFriendlyIds
     * @param  SupportCollection<int, int>  $assignmentCounts
     */
    protected function scoreFriendlyCandidates(
        WarPlan $plan,
        \App\Models\WarPlanTarget $target,
        Collection $friendlies,
        array $preservedFriendlyIds,
        SupportCollection $assignmentCounts
    ): SupportCollection {
        $available = $friendlies
            ->reject(function (Nation $nation) use ($preservedFriendlyIds) {
                return in_array($nation->id, $preservedFriendlyIds, true);
            })
            ->values();

        $squadTolerance = $plan->squad_cohesion_tolerance;
        $existingAverage = $target->assignments()
            ->avg('match_score');

        $cohesionReference = $existingAverage !== null ? $existingAverage / 100 : 0.5;

        $candidates = $available->map(function (Nation $friendly) use ($target, $assignmentCounts, $squadTolerance, $cohesionReference) {
            if (! $target->nation || ! $this->matchService->canAttack($friendly, $target->nation)) {
                return null;
            }

            $availableSlots = $this->calculateAvailableSlots($friendly);
            $currentAssignments = $assignmentCounts[$friendly->id] ?? 0;
            $effectiveSlots = $availableSlots - $currentAssignments;

            if ($effectiveSlots <= 0) {
                return null;
            }

            $existingOffensive = (int) ($friendly->offensive_wars_count ?? 0);
            $remainingOffensive = 6 - ($existingOffensive + $currentAssignments);

            if ($remainingOffensive <= 0) {
                return null;
            }

            $context = [
                'available_slots' => max(0, $effectiveSlots),
                'assignment_load' => $currentAssignments,
                'max_assignments' => max(1, min($this->maxAssignmentsFor($friendly), $remainingOffensive)),
                'cohesion_reference' => $cohesionReference,
                'cohesion_tolerance' => $squadTolerance,
                'enemy_tps' => $target->target_priority_score,
                'evaluation_mode' => 'auto',
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
                - (($friendly->defensive_wars_count ?? 0) * $defPenalty));

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

        foreach ($ordered as $candidate) {
            if ($selected->count() >= $needed) {
                break;
            }

            $friendlyId = $candidate['friendly']->id;

            if (in_array($friendlyId, $selectedIds, true)) {
                continue;
            }

            $groupIds = $squadMatrix[$friendlyId] ?? [$friendlyId];
            $groupIds = array_values(array_filter($groupIds, function ($id) use ($selectedIds, $candidatesById) {
                return ! in_array($id, $selectedIds, true) && $candidatesById->has($id);
            }));

            if (empty($groupIds)) {
                continue;
            }

            $remainingSlots = $needed - $selected->count();

            if (count($groupIds) > $remainingSlots) {
                // Squad does not fit entirely; fall back to the initiating nation to avoid splitting another squad.
                $groupIds = [$friendlyId];
            }

            foreach ($groupIds as $id) {
                if (! $candidatesById->has($id)) {
                    continue;
                }

                $selected->push($candidatesById->get($id));
                $selectedIds[] = $id;

                if ($selected->count() >= $needed) {
                    break 2;
                }
            }
        }

        return $selected->values();
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
     * Estimate available slots by subtracting ongoing wars.
     */
    protected function calculateAvailableSlots(Nation $friendly): int
    {
        $base = config('war.slot_caps.default_offensive', 3);

        $projects = method_exists($friendly, 'getProjectsAttribute') ? $friendly->projects : [];
        $projectModifiers = config('war.slot_caps.project_modifiers', []);

        foreach ($projectModifiers as $project => $modifier) {
            if (is_array($projects) && Arr::get($projects, $project, false)) {
                $base += $modifier;
            }
        }

        $activeWars = War::query()
            ->where(function ($query) use ($friendly) {
                $query->where('att_id', $friendly->id)->orWhere('def_id', $friendly->id);
            })
            ->whereNull('end_date')
            ->count();

        return max(0, $base - $activeWars);
    }

    /**
     * Determine how many simultaneous assignments we allow for a nation before applying penalties.
     */
    protected function maxAssignmentsFor(Nation $friendly): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 3);

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
}
