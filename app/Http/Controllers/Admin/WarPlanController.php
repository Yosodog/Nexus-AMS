<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AutoGeneratePlanAssignmentsJob;
use App\Jobs\RecomputePlanTPSJob;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarPlan;
use App\Models\WarPlanAlliance;
use App\Models\WarPlanAssignment;
use App\Models\WarPlanSquad;
use App\Models\WarPlanTarget;
use App\Services\AllianceMembershipService;
use App\Services\War\LiveFeedQueryService;
use App\Services\War\NationMatchService;
use App\Services\War\NotificationService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin controller for managing war plans and detail view.
 */
class WarPlanController extends Controller
{
    /**
     * Display the planning room for a plan.
     */
    public function show(
        Request $request,
        WarPlan $plan,
        LiveFeedQueryService $liveFeed,
        AllianceMembershipService $membershipService
    ): View {
        $this->authorize('view-wars');

        $feedFilters = $request->only(['minutes', 'hours', 'attack_types', 'scope']);

        $plan->load([
            'friendlyAlliances.alliance',
            'enemyAlliances.alliance',
            'targets.nation.alliance',
            'targets.nation.military',
            'targets.assignments',
            'assignments.friendlyNation.alliance',
            'assignments.friendlyNation.military',
            'assignments.target.nation.alliance',
            'assignments.target.nation.military',
            'assignments.squad',
        ]);

        $friendlyAllianceIds = $plan->friendlyAlliances->pluck('alliance_id')->filter();

        if ($friendlyAllianceIds->isEmpty()) {
            $friendlyAllianceIds = $membershipService->getAllianceIds();
        }

        $friendliesQuery = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->orderBy('leader_name')
            ->select(['id', 'leader_name', 'nation_name', 'alliance_id', 'score', 'offensive_wars_count', 'defensive_wars_count', 'color']);

        $warTypes = config('war.war_types', []);

        [$topCandidates, $allFriendlies, $friendlyStats] = $this->recommendAssignments($plan, $friendliesQuery);

        return view('admin.war-room.plan', [
            'plan' => $plan,
            'targets' => $plan->targets()->with(['nation.alliance', 'nation.military', 'assignments'])->withCount('assignments')->orderByDesc('target_priority_score')->get(),
            'assignments' => $plan->assignments()->with(['friendlyNation.alliance', 'friendlyNation.military', 'target.nation.alliance', 'target.nation.military', 'squad'])->orderByDesc('match_score')->get(),
            'liveFeed' => $liveFeed->forPlan($plan, $feedFilters),
            'feedFilters' => $feedFilters,
            'topCandidates' => $topCandidates,
            'allFriendlies' => $allFriendlies,
            'friendlyStats' => $friendlyStats,
            'warTypes' => $warTypes,
        ]);
    }

    /**
     * Store a new plan based on admin input.
     */
    public function store(Request $request, PlanOrchestratorService $orchestrator): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $this->hydrateAllianceArrays($request);

        $payload = $this->validatePlanPayload($request);

        $plan = $orchestrator->createPlan($payload);

        return Redirect::route('admin.war-plans.show', $plan)
            ->with('alert-type', 'success')
            ->with('alert-message', 'War plan created successfully.');
    }

    /**
     * Update plan metadata and options.
     */
    public function update(
        Request $request,
        WarPlan $plan,
        PlanOrchestratorService $orchestrator
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $this->hydrateAllianceArrays($request);

        $payload = $this->validatePlanPayload($request, $plan->id);

        $orchestrator->updatePlan($plan, $payload);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'War plan updated.');
    }

    /**
     * Activate a plan.
     */
    public function activate(WarPlan $plan, PlanOrchestratorService $orchestrator): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $orchestrator->activatePlan($plan);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Plan activated.');
    }

    /**
     * Archive a plan.
     */
    public function archive(WarPlan $plan, PlanOrchestratorService $orchestrator): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $orchestrator->archivePlan($plan);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Plan archived.');
    }

    /**
     * Trigger recomputation of TPS.
     */
    public function recompute(WarPlan $plan): RedirectResponse
    {
        $this->authorize('manage-war-room');

        RecomputePlanTPSJob::dispatch($plan->id);

        return Redirect::back()
            ->with('alert-type', 'info')
            ->with('alert-message', 'TPS recompute queued.');
    }

    /**
     * Kick off auto assignment generation.
     */
    public function autoAssign(WarPlan $plan): RedirectResponse
    {
        $this->authorize('manage-war-room');

        AutoGeneratePlanAssignmentsJob::dispatch($plan->id);

        return Redirect::back()
            ->with('alert-type', 'info')
            ->with('alert-message', 'Assignment generation queued.');
    }

    /**
     * Publish assignments and queue notifications.
     */
    public function publish(
        Request $request,
        WarPlan $plan,
        PlanOrchestratorService $orchestrator,
        NotificationService $notificationService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $channels = [
            'in_game' => $request->boolean('notify_in_game'),
            'discord' => $request->boolean('notify_discord'),
            'create_room' => $request->boolean('notify_discord_room'),
        ];

        $plan = $orchestrator->markAssignmentsPublished($plan);

        $assignments = $plan->assignments()->with('friendlyNation')->get();

        $notificationService->queuePlanPublishNotifications($plan, $assignments, $channels);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Assignments published and notifications queued.');
    }

    /**
     * Export plan payload as JSON.
     *
     * Schema (version 1):
     * {
     *   "schema_version": 1,
     *   "metadata": {"id": int, "name": string, "plan_type": string, "status": string, "exported_at": ISO8601},
     *   "options": {
     *     "preferred_nations_per_target": int,
     *     "activity_window_hours": int,
     *     "max_squad_size": int,
     *     "squad_cohesion_tolerance": int,
     *     "suppress_counters_when_active": bool,
     *     "custom": array|null
     *   },
     *   "alliances": {"friendly": int[], "enemy": int[]},
     *   "targets": [
     *     {"nation_id": int, "tps": float, "meta": array|null, "computed_at": ISO8601|null}
     *   ],
     *   "targets" entries additionally include "preferred_war_type": string when exported.
     *   "assignments": [
     *     {"friendly_nation_id": int, "target_id": int, "match_score": float, "status": string, "meta": array|null, "squad_id": int|null}
     *   ]
     * }
     */
    public function export(WarPlan $plan, Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view-wars');

        $includeAssignments = ! $request->boolean('options_only');

        $plan->load(['friendlyAlliances', 'enemyAlliances', 'targets', 'assignments']);

        $payload = [
            'schema_version' => 1,
            'metadata' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'plan_type' => $plan->plan_type,
                'status' => $plan->status,
                'exported_at' => now()->toIso8601String(),
            ],
            'options' => [
                'preferred_nations_per_target' => $plan->preferred_nations_per_target,
                'activity_window_hours' => $plan->activity_window_hours,
                'max_squad_size' => $plan->max_squad_size,
                'squad_cohesion_tolerance' => $plan->squad_cohesion_tolerance,
                'suppress_counters_when_active' => $plan->suppress_counters_when_active,
                'custom' => $plan->options,
            ],
            'alliances' => [
                'friendly' => $plan->friendlyAlliances->pluck('alliance_id')->all(),
                'enemy' => $plan->enemyAlliances->pluck('alliance_id')->all(),
            ],
        ];

        if ($includeAssignments) {
            $payload['targets'] = $plan->targets->map(fn (WarPlanTarget $target) => [
                'nation_id' => $target->nation_id,
                'tps' => $target->target_priority_score,
                'preferred_war_type' => $target->preferred_war_type,
                'meta' => $target->meta,
                'computed_at' => optional($target->computed_at)->toIso8601String(),
            ])->all();

            $payload['assignments'] = $plan->assignments->map(fn (WarPlanAssignment $assignment) => [
                'friendly_nation_id' => $assignment->friendly_nation_id,
                'target_id' => $assignment->war_plan_target_id,
                'match_score' => $assignment->match_score,
                'status' => $assignment->status,
                'meta' => $assignment->meta,
                'squad_id' => $assignment->war_plan_squad_id,
            ])->all();
        }

        return response()->json($payload);
    }

    /**
     * Import plan data from JSON payload matching export schema v1.
     *
     * Required keys: schema_version (1), metadata, options, alliances.
     * Optional keys: targets[], assignments[]. When dry_run=true the calculated diff is flashed
     * without mutating the plan.
     */
    public function import(
        Request $request,
        WarPlan $plan,
        PlanOrchestratorService $orchestrator
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $request->validate([
            'payload' => 'required|string',
            'dry_run' => 'nullable|boolean',
        ]);

        $payload = json_decode($request->input('payload'), true);

        if (! is_array($payload)) {
            return Redirect::back()
                ->with('alert-type', 'danger')
                ->with('alert-message', 'Invalid JSON payload.');
        }

        if (($payload['schema_version'] ?? null) !== 1) {
            return Redirect::back()
                ->with('alert-type', 'danger')
                ->with('alert-message', 'Unsupported schema version.');
        }

        $dryRun = $request->boolean('dry_run');

        $diff = $this->buildImportDiff($plan, $payload);

        if ($dryRun) {
            return Redirect::back()
                ->with('import-preview', $diff)
                ->with('alert-type', 'info')
                ->with('alert-message', 'Dry-run complete. Review differences below.');
        }

        $orchestrator->updatePlan($plan, [
            'name' => $payload['metadata']['name'] ?? $plan->name,
            'plan_type' => $payload['metadata']['plan_type'] ?? $plan->plan_type,
            'preferred_nations_per_target' => $payload['options']['preferred_nations_per_target'] ?? $plan->preferred_nations_per_target,
            'activity_window_hours' => $payload['options']['activity_window_hours'] ?? $plan->activity_window_hours,
            'max_squad_size' => $payload['options']['max_squad_size'] ?? $plan->max_squad_size,
            'squad_cohesion_tolerance' => $payload['options']['squad_cohesion_tolerance'] ?? $plan->squad_cohesion_tolerance,
            'suppress_counters_when_active' => $payload['options']['suppress_counters_when_active'] ?? $plan->suppress_counters_when_active,
            'options' => $payload['options']['custom'] ?? $plan->options,
            'friendly_alliances' => $payload['alliances']['friendly'] ?? [],
            'enemy_alliances' => $payload['alliances']['enemy'] ?? [],
        ]);

        if (! empty($payload['targets'])) {
            foreach ($payload['targets'] as $target) {
                WarPlanTarget::query()->updateOrCreate(
                    [
                        'war_plan_id' => $plan->id,
                        'nation_id' => $target['nation_id'],
                    ],
                    [
                        'preferred_war_type' => $target['preferred_war_type'] ?? $plan->plan_type,
                        'target_priority_score' => $target['tps'] ?? 0,
                        'meta' => $target['meta'] ?? [],
                        'computed_at' => isset($target['computed_at']) ? Carbon::parse($target['computed_at']) : now(),
                    ]
                );
            }
        }

        if (! empty($payload['assignments'])) {
            foreach ($payload['assignments'] as $assignment) {
                WarPlanAssignment::query()->updateOrCreate(
                    [
                        'war_plan_id' => $plan->id,
                        'war_plan_target_id' => $assignment['target_id'],
                        'friendly_nation_id' => $assignment['friendly_nation_id'],
                    ],
                    [
                        'match_score' => $assignment['match_score'] ?? 0,
                        'status' => $assignment['status'] ?? 'proposed',
                        'meta' => $assignment['meta'] ?? [],
                        'war_plan_squad_id' => $assignment['squad_id'] ?? null,
                    ]
                );
            }
        }

        $this->rebuildSquads($plan);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Plan imported successfully.');
    }

    /**
     * Validate incoming plan payload.
     *
     * @return array<string, mixed>
     */
    protected function validatePlanPayload(Request $request, ?int $planId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'plan_type' => ['nullable', Rule::in(array_keys(config('war.war_types', [])))],
            'preferred_nations_per_target' => ['nullable', 'integer', 'min:1', 'max:10'],
            'activity_window_hours' => ['nullable', 'integer', 'min:12', 'max:240'],
            'max_squad_size' => ['nullable', 'integer', 'min:1', 'max:10'],
            'squad_cohesion_tolerance' => ['nullable', 'integer', 'min:1', 'max:50'],
            'suppress_counters_when_active' => ['nullable', 'boolean'],
            'friendly_alliances' => ['nullable', 'array'],
            'friendly_alliances.*' => ['integer'],
            'enemy_alliances' => ['nullable', 'array'],
            'enemy_alliances.*' => ['integer'],
            'options' => ['nullable', 'array'],
        ];

        $data = $request->validate($rules);

        if (array_key_exists('plan_type', $data) && $data['plan_type'] !== null) {
            $data['plan_type'] = strtolower($data['plan_type']);
        }

        $data['friendly_alliances'] = array_filter($data['friendly_alliances'] ?? []);
        $data['enemy_alliances'] = array_filter($data['enemy_alliances'] ?? []);

        return $data;
    }

    /**
     * Build a diff summary for dry-run imports.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function buildImportDiff(WarPlan $plan, array $payload): array
    {
        $existingTargets = $plan->targets()->pluck('nation_id')->all();
        $incomingTargets = collect($payload['targets'] ?? [])->pluck('nation_id')->all();

        return [
            'name_change' => [
                'current' => $plan->name,
                'incoming' => $payload['metadata']['name'] ?? $plan->name,
            ],
            'new_targets' => array_values(array_diff($incomingTargets, $existingTargets)),
            'removed_targets' => array_values(array_diff($existingTargets, $incomingTargets)),
            'alliances' => [
                'friendly' => [
                    'current' => $plan->friendlyAlliances()->pluck('alliance_id')->all(),
                    'incoming' => $payload['alliances']['friendly'] ?? [],
                ],
                'enemy' => [
                    'current' => $plan->enemyAlliances()->pluck('alliance_id')->all(),
                    'incoming' => $payload['alliances']['enemy'] ?? [],
                ],
            ],
        ];
    }

    public function updateTargetWarType(Request $request, WarPlan $plan, WarPlanTarget $target): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($target->war_plan_id !== $plan->id) {
            abort(404);
        }

        $data = $request->validate([
            'preferred_war_type' => ['required', Rule::in(array_keys(config('war.war_types', [])))],
        ]);

        $target->update([
            'preferred_war_type' => strtolower($data['preferred_war_type']),
        ]);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Target war type updated.');
    }

    public function addAlliance(Request $request, WarPlan $plan, PlanOrchestratorService $orchestrator): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'alliance_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::in(['friendly', 'enemy'])],
        ]);

        $plan->alliances()->firstOrCreate([
            'alliance_id' => $data['alliance_id'],
            'role' => $data['role'],
        ]);

        $orchestrator->refreshSuppressionCache();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Alliance added to plan.');
    }

    public function removeAlliance(WarPlan $plan, WarPlanAlliance $alliance, PlanOrchestratorService $orchestrator): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($alliance->war_plan_id !== $plan->id) {
            abort(404);
        }

        $alliance->delete();
        $orchestrator->refreshSuppressionCache();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Alliance removed from plan.');
    }

    public function addTarget(Request $request, WarPlan $plan): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'preferred_war_type' => ['nullable', Rule::in(array_keys(config('war.war_types', [])))],
        ]);

        $target = WarPlanTarget::query()->firstOrCreate([
            'war_plan_id' => $plan->id,
            'nation_id' => $data['nation_id'],
        ]);

        if (isset($data['preferred_war_type'])) {
            $target->preferred_war_type = strtolower($data['preferred_war_type']);
        } elseif (! $target->preferred_war_type) {
            $target->preferred_war_type = $plan->plan_type;
        }

        if (! $target->target_priority_score) {
            $target->target_priority_score = 0;
        }

        $target->save();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Target added to plan.');
    }

    public function removeTarget(WarPlan $plan, WarPlanTarget $target): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($target->war_plan_id !== $plan->id) {
            abort(404);
        }

        $target->delete();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Target removed from plan.');
    }

    public function storeManualAssignment(Request $request, WarPlan $plan): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'war_plan_target_id' => [
                'required',
                Rule::exists('war_plan_targets', 'id')->where('war_plan_id', $plan->id),
            ],
            'friendly_nation_id' => ['required', 'exists:nations,id'],
            'match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        /** @var WarPlanTarget $target */
        $target = WarPlanTarget::query()->findOrFail($data['war_plan_target_id']);

        WarPlanAssignment::query()->updateOrCreate(
            [
                'war_plan_id' => $plan->id,
                'war_plan_target_id' => $target->id,
                'friendly_nation_id' => $data['friendly_nation_id'],
            ],
            [
                'match_score' => $data['match_score'] ?? 50,
                'status' => 'confirmed',
                'is_overridden' => true,
                'is_locked' => true,
                'meta' => ['manual' => true],
            ]
        );

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Manual assignment saved.');
    }

    public function removeAssignment(WarPlan $plan, WarPlanAssignment $assignment): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($assignment->war_plan_id !== $plan->id) {
            abort(404);
        }

        $assignment->delete();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Assignment removed.');
    }

    /**
     * Generate recommended friendlies per target along with helper stats for the view.
     *
     * @return array{
     *     0: array<int, array<int, array{friendly: Nation, score: float, assignment_load: int, max_assignments: int, available_slots: int}>>,
     *     1: Collection<int, Nation>,
     *     2: Collection<int, array{friendly: Nation, assignment_load: int, available_slots: int, remaining_slots: int, max_assignments: int, offensive_wars: int, defensive_wars: int, remaining_offensive_capacity: int}>
     * }
     */
    protected function recommendAssignments(WarPlan $plan, Builder $friendliesQuery): array
    {
        $friendlies = $friendliesQuery->with(['military', 'latestSignIn', 'alliance'])->get();

        if ($friendlies->isEmpty()) {
            return [[], collect(), collect()];
        }

        $plan->loadMissing(['targets.assignments', 'targets.nation', 'assignments']);

        $friendlyStats = $this->prepareFriendlyStats($friendlies, $plan->assignments);

        if ($friendlyStats->isEmpty() || $plan->targets->isEmpty()) {
            return [[], $friendlyStats->map(fn ($stat) => $stat['friendly'])->sortBy('leader_name')->values(), $friendlyStats];
        }

        $matchService = app(NationMatchService::class);

        $recommendations = [];

        foreach ($plan->targets as $target) {
            if (! $target->nation) {
                continue;
            }

            $existingFriendlyIds = $target->assignments->pluck('friendly_nation_id')->all();

            $candidates = $friendlyStats
                ->reject(function (array $stat, int $friendlyId) use ($existingFriendlyIds) {
                    if (in_array($friendlyId, $existingFriendlyIds, true)) {
                        return true;
                    }

                    if ($stat['remaining_slots'] <= 0) {
                        return true;
                    }

                    return $stat['remaining_offensive_capacity'] <= 0;
                })
                ->map(function (array $stat) use ($matchService, $target, $plan) {
                    if (! $matchService->canAttack($stat['friendly'], $target->nation)) {
                        return null;
                    }

                    $context = [
                        'available_slots' => $stat['remaining_slots'],
                        'assignment_load' => $stat['assignment_load'],
                        'max_assignments' => max(1, min($stat['max_assignments'], $stat['remaining_offensive_capacity'])),
                        'cohesion_reference' => 0.5,
                        'cohesion_tolerance' => $plan->squad_cohesion_tolerance,
                        'enemy_tps' => $target->target_priority_score,
                        'evaluation_mode' => 'manual',
                    ];

                    $match = $matchService->evaluate($stat['friendly'], $target->nation, $context);

                    $relativeFactor = $match['meta']['factors']['relative_power'] ?? 0.0;
                    $relativePower = is_array($relativeFactor) ? ($relativeFactor['value'] ?? 0.0) : (float) $relativeFactor;
                    $relativeTuning = config('war.nation_match.relative_power', []);
                    $manualFloor = $relativeTuning['manual_floor'] ?? 0.05;

                    if ($relativePower < $manualFloor) {
                        return null;
                    }

                    $penalties = config('war.nation_match.penalties', []);
                    $offPenalty = $penalties['offensive_load'] ?? 4;
                    $defPenalty = $penalties['defensive_load'] ?? 6;

                    $score = max(0, $match['score']
                        - ($stat['offensive_wars'] + $stat['assignment_load']) * $offPenalty
                        - ($stat['defensive_wars'] * $defPenalty));

                    if ($score <= 0) {
                        $score = round(max(0.5, $match['meta']['bounded'] ?? 0.5), 2);
                    }

                    return array_merge($stat, [
                        'score' => round($score, 2),
                        'match_meta' => $match['meta'],
                    ]);
                })
                ->filter(fn (?array $stat) => $stat !== null && $stat['score'] > 0)
                ->sortByDesc('score')
                ->take(10)
                ->values()
                ->map(fn (array $stat) => [
                    'friendly' => $stat['friendly'],
                    'score' => $stat['score'],
                    'assignment_load' => $stat['assignment_load'],
                    'max_assignments' => $stat['max_assignments'],
                    'available_slots' => $stat['remaining_slots'],
                ])
                ->all();

            $recommendations[$target->id] = $candidates;
        }

        $allFriendlies = $friendlyStats
            ->map(fn (array $stat) => $stat['friendly'])
            ->sortBy('leader_name')
            ->values();

        return [$recommendations, $allFriendlies, $friendlyStats];
    }

    protected function prepareFriendlyStats(Collection $friendlies, Collection $assignments): Collection
    {
        $assignmentCounts = $assignments->groupBy('friendly_nation_id')->map->count();
        $activeWarCounts = $this->activeWarCounts($friendlies->pluck('id'));

        return $friendlies->mapWithKeys(function (Nation $friendly) use ($assignmentCounts, $activeWarCounts) {
            $friendlyId = $friendly->id;
            $assignmentLoad = (int) ($assignmentCounts[$friendlyId] ?? 0);
            $availableSlots = $this->calculateAvailableSlotsForNation($friendly, $activeWarCounts[$friendlyId] ?? 0);
            $maxAssignments = $this->maxAssignmentsForNation($friendly);
            $remainingSlots = max(0, $availableSlots - $assignmentLoad);
            $offensiveWars = (int) ($friendly->offensive_wars_count ?? 0);
            $defensiveWars = (int) ($friendly->defensive_wars_count ?? 0);
            $remainingOffensive = max(0, 6 - ($offensiveWars + $assignmentLoad));

            return [$friendlyId => [
                'friendly' => $friendly,
                'assignment_load' => $assignmentLoad,
                'available_slots' => $availableSlots,
                'remaining_slots' => $remainingSlots,
                'max_assignments' => $maxAssignments,
                'offensive_wars' => $offensiveWars,
                'defensive_wars' => $defensiveWars,
                'remaining_offensive_capacity' => $remainingOffensive,
            ]];
        });
    }

    protected function calculateAvailableSlotsForNation(Nation $friendly, int $activeWars): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 3);
        $modifiers = config('war.slot_caps.project_modifiers', []);

        $projects = [];

        try {
            $projects = $friendly->projects ?? [];
        } catch (\Throwable) {
            $projects = [];
        }

        if (is_array($projects)) {
            foreach ($modifiers as $project => $adjustment) {
                if (($projects[$project] ?? false) === true) {
                    $base += $adjustment;
                }
            }
        }

        return max(0, $base - $activeWars);
    }

    protected function maxAssignmentsForNation(Nation $friendly): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 3);

        if (in_array($friendly->alliance_position, ['LEADER', 'HEIR'], true)) {
            return max(1, $base - 1);
        }

        return $base;
    }

    protected function activeWarCounts(Collection $friendlyIds): array
    {
        $ids = $friendlyIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

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
            $counts[$id] = (int) (($attCounts[$id] ?? 0) + ($defCounts[$id] ?? 0));
        }

        return $counts;
    }

    /**
     * Convert friendly/enemy alliance comma lists into arrays understood by the validator.
     */
    protected function hydrateAllianceArrays(Request $request): void
    {
        $friendlyRaw = $request->input('friendly_alliances_raw');
        $enemyRaw = $request->input('enemy_alliances_raw');

        if ($friendlyRaw !== null && ! $request->has('friendly_alliances')) {
            $request->merge([
                'friendly_alliances' => $this->explodeAllianceList($friendlyRaw),
            ]);
        }

        if ($enemyRaw !== null && ! $request->has('enemy_alliances')) {
            $request->merge([
                'enemy_alliances' => $this->explodeAllianceList($enemyRaw),
            ]);
        }
    }

    /**
     * Break a comma separated string into a unique list of positive integers.
     *
     * @return array<int, int>
     */
    protected function explodeAllianceList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($value) => (int) trim($value))
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function rebuildSquads(WarPlan $plan): void
    {
        WarPlanAssignment::query()->where('war_plan_id', $plan->id)->update(['war_plan_squad_id' => null]);
        WarPlanSquad::query()->where('war_plan_id', $plan->id)->delete();

        $assignments = $plan->assignments()->orderBy('war_plan_target_id')->orderByDesc('match_score')->get();

        $counter = 1;
        $maxSize = max(1, $plan->max_squad_size);
        $labelPrefix = config('war.squads.label_prefix', 'Squad');

        foreach ($assignments->groupBy('war_plan_target_id') as $group) {
            foreach ($group->chunk($maxSize) as $chunk) {
                $squad = WarPlanSquad::query()->create([
                    'war_plan_id' => $plan->id,
                    'label' => $labelPrefix.' '.$counter,
                    'round' => 1,
                    'cohesion_score' => round($chunk->avg('match_score') ?? 0, 2),
                    'meta' => ['target_id' => $chunk->first()->war_plan_target_id],
                ]);

                WarPlanAssignment::query()
                    ->whereIn('id', $chunk->pluck('id'))
                    ->update(['war_plan_squad_id' => $squad->id]);

                $counter++;
            }
        }
    }
}
