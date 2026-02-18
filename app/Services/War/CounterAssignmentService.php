<?php

namespace App\Services\War;

use App\Events\CounterFinalized;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates reactive counter assignments for aggressor nations.
 *
 * Design Notes:
 * - Counter picks favour quick availability and high readiness; TPS is assumed high due to live aggression.
 * - Idempotent generation lets us safely rerun after manual edits; only unlocked rows are overwritten.
 * - Finalization flips assignment status for notification pipelines handled by NotificationService.
 */
class CounterAssignmentService
{
    public function __construct(
        private readonly NationMatchService $matchService,
        private readonly CacheFactory $cacheFactory
    ) {}

    /**
     * Manually add or lock an assignment for a specific friendly nation.
     * Always marks the assignment locked so auto-pick won't overwrite it.
     */
    public function addManualAssignment(WarCounter $counter, Nation $friendly, ?float $overrideScore = null, string $status = 'assigned'): WarCounterAssignment
    {
        $availableSlots = $this->calculateAvailableSlots($friendly);
        $context = [
            'available_slots' => $availableSlots,
            'assignment_load' => $this->currentAssignments($friendly),
            'max_assignments' => $this->maxAssignmentsFor($friendly),
            'cohesion_reference' => 0.8,
            'enemy_tps' => 90,
            'evaluation_mode' => 'manual',
        ];

        $match = $this->matchService->evaluate($friendly, $counter->aggressor, $context);

        /** @var WarCounterAssignment $assignment */
        $assignment = WarCounterAssignment::query()->updateOrCreate(
            [
                'war_counter_id' => $counter->id,
                'friendly_nation_id' => $friendly->id,
            ],
            [
                'match_score' => $overrideScore !== null ? (float) $overrideScore : ($match['score'] ?? 50.0),
                'meta' => array_merge($match['meta'] ?? [], ['manual' => true]),
                'status' => $status,
                'is_locked' => true,
            ]
        );

        return $assignment->refresh();
    }

    /**
     * Confirm (lock) an assignment for the provided nation. Creates it if missing.
     */
    public function confirmAssignmentForNation(WarCounter $counter, Nation $friendly): WarCounterAssignment
    {
        $existing = WarCounterAssignment::query()
            ->where('war_counter_id', $counter->id)
            ->where('friendly_nation_id', $friendly->id)
            ->first();

        if ($existing) {
            $existing->update(['is_locked' => true]);

            return $existing->refresh();
        }

        return $this->addManualAssignment($counter, $friendly, null, 'assigned');
    }

    /**
     * Ensure the number of proposed assignments does not exceed the counter team size.
     * Extra proposals (lowest score first) are deleted.
     */
    public function enforceProposedLimit(WarCounter $counter): void
    {
        $teamSize = $counter->team_size ?? (int) config('war.counters.default_team_size', 3);

        $proposed = $counter->assignments()->where('status', 'proposed')->orderByDesc('match_score')->get();

        if ($proposed->count() <= $teamSize) {
            return;
        }

        $toDelete = $proposed->slice($teamSize); // keep top N, drop the rest

        foreach ($toDelete as $assignment) {
            $assignment->delete();
        }
    }

    /**
     * Compute ranked candidate friendlies for a counter using the same scoring engine.
     *
     * @param  Collection<int, Nation>  $friendlies
     * @return SupportCollection<int, array{friendly:Nation, score:float, meta:array, available_slots:int, assignment_load:int, max_assignments:int, relative_power:float, recommended:bool}>
     */
    public function listCandidates(WarCounter $counter, Collection $friendlies): SupportCollection
    {
        $autoFloor = (float) config('war.nation_match.relative_power.auto_floor', 0.18);

        return $friendlies
            ->map(function (Nation $friendly) use ($counter) {
                if (! $this->matchService->canAttack($friendly, $counter->aggressor)) {
                    return null;
                }

                $availableSlots = $this->calculateAvailableSlots($friendly);
                $context = [
                    'available_slots' => $availableSlots,
                    'assignment_load' => $this->currentAssignments($friendly),
                    'max_assignments' => $this->maxAssignmentsFor($friendly),
                    'cohesion_reference' => 0.8,
                    'enemy_tps' => 90,
                    'evaluation_mode' => 'browse',
                ];
                $match = $this->matchService->evaluate($friendly, $counter->aggressor, $context);

                $relativeFactor = $match['meta']['factors']['relative_power'] ?? 0.0;
                $relativePower = is_array($relativeFactor) ? ($relativeFactor['value'] ?? 0.0) : (float) $relativeFactor;
                $recommended = $relativePower >= $autoFloor && $availableSlots > 0;

                return [
                    'friendly' => $friendly,
                    'score' => $match['score'],
                    'meta' => $match['meta'],
                    'available_slots' => $availableSlots,
                    'assignment_load' => $context['assignment_load'],
                    'max_assignments' => $context['max_assignments'],
                    'relative_power' => $relativePower,
                    'recommended' => $recommended,
                ];
            })
            ->filter()
            ->sortBy([
                ['recommended', 'desc'],
                ['score', 'desc'],
            ])
            ->values();
    }

    /**
     * Propose assignments for the counter.
     *
     * @param  Collection<int, Nation>  $friendlies
     * @return Collection<int, WarCounterAssignment>
     */
    public function proposeAssignments(
        WarCounter $counter,
        Collection $friendlies,
        bool $respectLocks = true
    ): Collection {
        $lock = $this->cacheFactory->store()->lock("counter:{$counter->id}:assign", (int) config('war.counters.lock_ttl', 30));

        $assignments = collect();

        try {
            $lock->block((int) config('war.cache.lock_timeout', 10), function () use (
                $counter,
                $friendlies,
                $respectLocks,
                &$assignments
            ) {
                $assignments = DB::transaction(function () use ($counter, $friendlies, $respectLocks) {
                    return $this->runProposal($counter, $friendlies, $respectLocks);
                });
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Counter assignment lock timed out', [
                'counter_id' => $counter->id,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // noop
            }
        }

        return $assignments;
    }

    /**
     * Finalize assignments, promoting counter to active state.
     */
    public function finalize(WarCounter $counter): WarCounter
    {
        DB::transaction(function () use ($counter) {
            $assignedCount = $counter->assignments()->where('status', 'assigned')->count();

            if ($assignedCount > 0) {
                $counter->assignments()
                    ->where('status', 'assigned')
                    ->update(['status' => 'finalized']);
            } else {
                // Backwards-compat: if nothing explicitly assigned, finalize proposed.
                $counter->assignments()
                    ->where('status', 'proposed')
                    ->update(['status' => 'finalized']);
            }

            $counter->update([
                'status' => 'active',
                'finalized_at' => now(),
            ]);
        });

        $counter = $counter->refresh();

        event(new CounterFinalized($counter));

        return $counter;
    }

    /**
     * Archive counter and release future suppression.
     */
    public function archive(WarCounter $counter): WarCounter
    {
        $counter->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return $counter->refresh();
    }

    /**
     * @param  Collection<int, Nation>  $friendlies
     */
    protected function runProposal(
        WarCounter $counter,
        Collection $friendlies,
        bool $respectLocks
    ): Collection {
        $existing = $counter->assignments()->with('friendlyNation')->get();

        $preserved = $respectLocks
            ? $existing->filter(fn (WarCounterAssignment $assignment) => $assignment->is_locked || $assignment->status === 'finalized')
            : collect();

        $teamSize = $counter->team_size ?? (int) config('war.counters.default_team_size', 3);
        $needed = max(0, $teamSize - $preserved->count());

        if ($needed === 0) {
            return $existing;
        }

        $preservedIds = $preserved->pluck('friendly_nation_id')->all();

        $candidates = $friendlies
            ->reject(fn (Nation $nation) => in_array($nation->id, $preservedIds, true))
            ->map(function (Nation $friendly) use ($counter) {
                $availableSlots = $this->calculateAvailableSlots($friendly);

                if ($availableSlots <= 0) {
                    return null;
                }

                $context = [
                    'available_slots' => $availableSlots,
                    'assignment_load' => $this->currentAssignments($friendly),
                    'max_assignments' => $this->maxAssignmentsFor($friendly),
                    'cohesion_reference' => 0.8,
                    'enemy_tps' => 90,
                    'evaluation_mode' => 'auto',
                ];

                $match = $this->matchService->evaluate($friendly, $counter->aggressor, $context);

                $relativeFactor = $match['meta']['factors']['relative_power'] ?? 0.0;
                $relativePower = is_array($relativeFactor) ? ($relativeFactor['value'] ?? 0.0) : (float) $relativeFactor;
                $autoFloor = config('war.nation_match.relative_power.auto_floor', 0.18);

                if ($relativePower < $autoFloor) {
                    return null;
                }

                return [
                    'friendly' => $friendly,
                    'match' => $match,
                ];
            })
            ->filter()
            ->sortByDesc(fn ($candidate) => $candidate['match']['score'])
            ->values();

        $selected = $candidates->take($needed);

        $finalIds = array_merge(
            $preservedIds,
            $selected->pluck('friendly.id')->all()
        );

        // Delete unlocked assignments not selected
        $existing
            ->filter(fn (WarCounterAssignment $assignment) => ! in_array($assignment->friendly_nation_id, $finalIds, true))
            ->each(function (WarCounterAssignment $assignment) {
                if ($assignment->is_locked || $assignment->status === 'finalized') {
                    return;
                }
                $assignment->delete();
            });

        foreach ($selected as $candidate) {
            /** @var Nation $friendly */
            $friendly = $candidate['friendly'];
            $match = $candidate['match'];

            WarCounterAssignment::query()->updateOrCreate(
                [
                    'war_counter_id' => $counter->id,
                    'friendly_nation_id' => $friendly->id,
                ],
                [
                    'match_score' => $match['score'],
                    'meta' => $match['meta'],
                    'status' => 'proposed',
                    'is_locked' => false,
                ]
            );
        }

        return $counter->assignments()->with('friendlyNation')->get();
    }

    /**
     * Estimate available slots similar to plan assignments.
     */
    protected function calculateAvailableSlots(Nation $friendly): int
    {
        $base = config('war.slot_caps.default_defensive', 3);

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

    protected function currentAssignments(Nation $friendly): int
    {
        return WarCounterAssignment::query()
            ->where('friendly_nation_id', $friendly->id)
            ->whereIn('status', ['proposed', 'finalized'])
            ->count();
    }

    protected function maxAssignmentsFor(Nation $friendly): int
    {
        $base = (int) config('war.slot_caps.default_defensive', 3);

        if ($friendly->alliance_position === 'LEADER') {
            $base = max(1, $base - 1);
        }

        return $base;
    }
}
