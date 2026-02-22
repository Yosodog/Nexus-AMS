<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AutoGeneratePlanAssignmentsJob;
use App\Jobs\RecomputePlanTPSJob;
use App\Models\Nation;
use App\Models\NationMilitary;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $plan->load(['friendlyAlliances.alliance', 'enemyAlliances.alliance']);

        $friendlyAllianceIds = $this->resolveFriendlyAllianceIds($plan, $membershipService);
        $friendliesQuery = $this->buildFriendliesQuery($friendlyAllianceIds);

        $targetsCount = $plan->targets()->count();
        $assignmentQuery = $plan->assignments();
        $assignmentCount = (clone $assignmentQuery)->count();
        $lockedCount = (clone $assignmentQuery)->where('is_locked', true)->count();
        $enemyCount = $plan->targets()->distinct('nation_id')->count('nation_id');
        $friendlyCount = (clone $friendliesQuery)->count();

        $preferredTargetsPerNation = min(
            max(1, $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );
        $preferredAssignmentsPerTarget = $this->calculatePreferredAssignmentsPerTarget(
            $targetsCount,
            $friendlyCount,
            $plan
        );
        $preferredSlotsTotal = $friendlyCount * $preferredTargetsPerNation;
        $coverage = $preferredSlotsTotal > 0 ? round(min(100, ($assignmentCount / $preferredSlotsTotal) * 100)) : null;

        $friendlyCityStats = $this->cityStatsForFriendlies($friendlyAllianceIds);
        $enemyNationIds = $plan->targets()->pluck('nation_id');
        $enemyCityStats = $this->cityStatsForNationIds($enemyNationIds);

        $friendlyMilTotals = $this->militaryTotalsForAlliances($friendlyAllianceIds);
        $enemyMilTotals = $this->militaryTotalsForNationIds($enemyNationIds);

        $warTypes = config('war.war_types', []);

        return view('admin.war-room.plan', [
            'plan' => $plan,
            'liveFeed' => $liveFeed->forPlan($plan, $feedFilters),
            'feedFilters' => $feedFilters,
            'warTypes' => $warTypes,
            'targetCount' => $targetsCount,
            'assignmentCount' => $assignmentCount,
            'lockedCount' => $lockedCount,
            'enemyCount' => $enemyCount,
            'preferredTargetsPerNation' => $preferredTargetsPerNation,
            'preferredAssignmentsPerTarget' => $preferredAssignmentsPerTarget,
            'preferredSlotsTotal' => $preferredSlotsTotal,
            'coverage' => $coverage,
            'friendlyCityTotal' => $friendlyCityStats['total'],
            'enemyCityTotal' => $enemyCityStats['total'],
            'friendlyCityAvg' => $friendlyCityStats['avg'],
            'enemyCityAvg' => $enemyCityStats['avg'],
            'friendlyMilTotals' => $friendlyMilTotals,
            'enemyMilTotals' => $enemyMilTotals,
            'friendlyCount' => $friendlyCount,
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
            'create_room' => $request->boolean('notify_discord_room'),
        ];

        $plan = $orchestrator->markAssignmentsPublished($plan);

        $assignments = $plan->assignments()->with(['friendlyNation', 'target.nation'])->get();

        $result = $notificationService->queuePlanPublishNotifications($plan, $assignments, $channels);

        $message = 'Assignments published.';

        if ($result['rooms_queued'] > 0) {
            $message .= " {$result['rooms_queued']} Discord war room job(s) queued.";
        }

        if ($result['in_game_skipped'] > 0) {
            $message .= ' In-game mail selected but not implemented yet.';
        }

        if ($result['skipped_no_forum']) {
            $message .= ' No default/override forum ID is configured, so Discord war rooms were not queued.';
        }

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', $message);
    }

    /**
     * Export plan payload as JSON.
     *
     * Schema (version 1):
     * {
     *   "schema_version": 1,
     *   "metadata": {"id": int, "name": string, "plan_type": string, "status": string, "exported_at": ISO8601},
     *   "options": {
     *     "preferred_targets_per_nation": int,
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
                'preferred_targets_per_nation' => $plan->preferred_targets_per_nation,
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

    public function exportTargetsCsv(WarPlan $plan): StreamedResponse
    {
        $this->authorize('view-wars');

        $filename = sprintf('war-plan-%d-targets-%s.csv', $plan->id, now()->format('Ymd-His'));

        $targets = $plan->targets()
            ->with([
                'nation:id,alliance_id,leader_name,nation_name,score,num_cities,vacation_mode_turns,beige_turns,offensive_wars_count,defensive_wars_count',
                'nation.alliance:id,name,acronym',
                'nation.accountProfile:nation_id,last_active',
            ])
            ->withCount('assignments')
            ->orderByDesc('target_priority_score')
            ->get();

        return response()->streamDownload(function () use ($targets): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'target_id',
                'nation_id',
                'leader_name',
                'nation_name',
                'alliance_name',
                'alliance_acronym',
                'score',
                'cities',
                'tps',
                'assignments_count',
                'preferred_war_type',
                'vacation_mode_turns',
                'beige_turns',
                'offensive_wars_count',
                'defensive_wars_count',
                'last_active',
            ]);

            foreach ($targets as $target) {
                fputcsv($handle, [
                    $target->id,
                    $target->nation_id,
                    $target->nation?->leader_name,
                    $target->nation?->nation_name,
                    $target->nation?->alliance?->name,
                    $target->nation?->alliance?->acronym,
                    $target->nation?->score,
                    $target->nation?->num_cities,
                    $target->target_priority_score,
                    $target->assignments_count,
                    $target->preferred_war_type,
                    $target->nation?->vacation_mode_turns,
                    $target->nation?->beige_turns,
                    $target->nation?->offensive_wars_count,
                    $target->nation?->defensive_wars_count,
                    optional($target->nation?->accountProfile?->last_active)?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportAssignmentsCsv(WarPlan $plan): StreamedResponse
    {
        $this->authorize('view-wars');

        $filename = sprintf('war-plan-%d-assignments-%s.csv', $plan->id, now()->format('Ymd-His'));

        $assignments = $plan->assignments()
            ->with([
                'target:id,war_plan_id,nation_id,target_priority_score,preferred_war_type',
                'target.nation:id,alliance_id,leader_name,nation_name,score,num_cities',
                'target.nation.alliance:id,name,acronym',
                'friendlyNation:id,alliance_id,leader_name,nation_name,score,num_cities,offensive_wars_count,defensive_wars_count,beige_turns',
                'friendlyNation.alliance:id,name,acronym',
                'squad:id,war_plan_id,label,round,cohesion_score',
            ])
            ->orderBy('war_plan_target_id')
            ->orderByDesc('match_score')
            ->get();

        return response()->streamDownload(function () use ($assignments): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'assignment_id',
                'target_id',
                'target_nation_id',
                'target_leader_name',
                'target_nation_name',
                'target_alliance_name',
                'target_alliance_acronym',
                'target_tps',
                'target_preferred_war_type',
                'friendly_nation_id',
                'friendly_leader_name',
                'friendly_nation_name',
                'friendly_alliance_name',
                'friendly_alliance_acronym',
                'friendly_score',
                'friendly_cities',
                'friendly_offensive_wars_count',
                'friendly_defensive_wars_count',
                'friendly_beige_turns',
                'match_score',
                'status',
                'is_locked',
                'is_overridden',
                'squad_label',
                'squad_round',
                'squad_cohesion_score',
                'created_at',
                'updated_at',
            ]);

            foreach ($assignments as $assignment) {
                fputcsv($handle, [
                    $assignment->id,
                    $assignment->war_plan_target_id,
                    $assignment->target?->nation_id,
                    $assignment->target?->nation?->leader_name,
                    $assignment->target?->nation?->nation_name,
                    $assignment->target?->nation?->alliance?->name,
                    $assignment->target?->nation?->alliance?->acronym,
                    $assignment->target?->target_priority_score,
                    $assignment->target?->preferred_war_type,
                    $assignment->friendly_nation_id,
                    $assignment->friendlyNation?->leader_name,
                    $assignment->friendlyNation?->nation_name,
                    $assignment->friendlyNation?->alliance?->name,
                    $assignment->friendlyNation?->alliance?->acronym,
                    $assignment->friendlyNation?->score,
                    $assignment->friendlyNation?->num_cities,
                    $assignment->friendlyNation?->offensive_wars_count,
                    $assignment->friendlyNation?->defensive_wars_count,
                    $assignment->friendlyNation?->beige_turns,
                    $assignment->match_score,
                    $assignment->status,
                    $assignment->is_locked ? '1' : '0',
                    $assignment->is_overridden ? '1' : '0',
                    $assignment->squad?->label,
                    $assignment->squad?->round,
                    $assignment->squad?->cohesion_score,
                    optional($assignment->created_at)?->toIso8601String(),
                    optional($assignment->updated_at)?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
            'preferred_targets_per_nation' => $payload['options']['preferred_targets_per_nation']
                ?? $payload['options']['preferred_nations_per_target']
                ?? $plan->preferred_targets_per_nation,
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
            'preferred_targets_per_nation' => ['nullable', 'integer', 'min:1', 'max:6'],
            'preferred_nations_per_target' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:6'],
            'activity_window_hours' => ['nullable', 'integer', 'min:12', 'max:240'],
            'max_squad_size' => ['nullable', 'integer', 'min:1', 'max:10'],
            'squad_cohesion_tolerance' => ['nullable', 'integer', 'min:1', 'max:50'],
            'suppress_counters_when_active' => ['nullable', 'boolean'],
            'discord_forum_channel_id' => ['nullable', 'string', 'max:190'],
            'friendly_alliances' => ['nullable', 'array'],
            'friendly_alliances.*' => ['integer', 'distinct', 'exists:alliances,id'],
            'enemy_alliances' => ['nullable', 'array'],
            'enemy_alliances.*' => ['integer', 'distinct', 'exists:alliances,id'],
            'options' => ['nullable', 'array'],
        ];

        $data = $request->validate($rules);

        if (array_key_exists('plan_type', $data) && $data['plan_type'] !== null) {
            $data['plan_type'] = strtolower($data['plan_type']);
        }

        if (! array_key_exists('preferred_targets_per_nation', $data) && array_key_exists('preferred_nations_per_target', $data)) {
            $data['preferred_targets_per_nation'] = $data['preferred_nations_per_target'];
        }

        unset($data['preferred_nations_per_target']);

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
            'alliance_id' => ['required', 'integer', 'min:1', 'exists:alliances,id'],
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

    public function targetsData(
        WarPlan $plan,
        AllianceMembershipService $membershipService
    ): JsonResponse {
        $this->authorize('view-wars');

        $friendliesQuery = $this->buildFriendliesQuery(
            $this->resolveFriendlyAllianceIds($plan, $membershipService)
        );
        $friendlyCount = (clone $friendliesQuery)->count();

        $targets = $plan->targets()
            ->with([
                'nation' => fn ($query) => $query->select([
                    'id',
                    'alliance_id',
                    'leader_name',
                    'nation_name',
                    'score',
                    'num_cities',
                    'color',
                    'vacation_mode_turns',
                    'offensive_wars_count',
                    'defensive_wars_count',
                ]),
                'nation.alliance:id,name,acronym',
                'nation.military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
                'nation.accountProfile:nation_id,last_active',
            ])
            ->withCount('assignments')
            ->orderByDesc('target_priority_score')
            ->get();

        return response()->json([
            'targets' => $targets,
            'preferred_assignments_per_target' => $this->calculatePreferredAssignmentsPerTarget(
                $targets->count(),
                $friendlyCount,
                $plan
            ),
        ]);
    }

    public function assignmentsData(WarPlan $plan): JsonResponse
    {
        $this->authorize('view-wars');

        $assignments = $plan->assignments()
            ->select([
                'id',
                'war_plan_id',
                'war_plan_target_id',
                'friendly_nation_id',
                'war_plan_squad_id',
                'match_score',
                'status',
                'is_overridden',
                'is_locked',
                'meta',
                'created_at',
                'updated_at',
            ])
            ->with([
                'friendlyNation' => fn ($query) => $query->select([
                    'id',
                    'alliance_id',
                    'leader_name',
                    'nation_name',
                    'score',
                    'num_cities',
                    'color',
                    'offensive_wars_count',
                    'defensive_wars_count',
                ]),
                'friendlyNation.alliance:id,name,acronym',
                'friendlyNation.military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
                'friendlyNation.accountProfile:nation_id,last_active',
                'target:id,war_plan_id,nation_id,target_priority_score,preferred_war_type,meta,computed_at',
                'target.nation:id,alliance_id,leader_name,nation_name,score,num_cities,color,vacation_mode_turns,offensive_wars_count,defensive_wars_count',
                'target.nation.alliance:id,name,acronym',
                'target.nation.military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
                'squad:id,war_plan_id,label,round,cohesion_score,meta',
            ])
            ->orderByDesc('match_score')
            ->get();

        return response()->json(['assignments' => $assignments]);
    }

    public function targetCandidatesData(
        WarPlan $plan,
        WarPlanTarget $target,
        AllianceMembershipService $membershipService
    ): JsonResponse {
        $this->authorize('view-wars');

        if ($target->war_plan_id !== $plan->id) {
            abort(404);
        }

        $friendliesQuery = $this->buildFriendliesQuery(
            $this->resolveFriendlyAllianceIds($plan, $membershipService)
        );

        $target->loadMissing([
            'nation:id,alliance_id,leader_name,nation_name,score,num_cities,color,vacation_mode_turns,offensive_wars_count,defensive_wars_count',
            'nation.military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
            'nation.accountProfile:nation_id,last_active',
            'assignments:id,war_plan_id,war_plan_target_id,friendly_nation_id,war_plan_squad_id,match_score,status,is_overridden,is_locked,meta',
        ]);

        return response()->json([
            'target_id' => $target->id,
            'candidates' => $this->recommendAssignmentsForTarget($plan, $target, $friendliesQuery),
        ]);
    }

    public function friendliesData(
        WarPlan $plan,
        AllianceMembershipService $membershipService
    ): JsonResponse {
        $this->authorize('view-wars');

        $friendliesQuery = $this->buildFriendliesQuery(
            $this->resolveFriendlyAllianceIds($plan, $membershipService)
        );

        $friendlies = $friendliesQuery
            ->with(['military', 'latestSignIn', 'alliance', 'accountProfile'])
            ->get();

        $assignments = $plan->assignments()->select(['id', 'friendly_nation_id'])->get();
        $friendlyStats = $this->prepareFriendlyStats($plan, $friendlies, $assignments);
        $assignmentFriendlyIds = $assignments->pluck('friendly_nation_id')->filter()->unique();

        return response()->json([
            'friendlies' => $friendlies,
            'friendly_stats' => $this->transformFriendlyStats($friendlyStats),
            'unassigned' => $friendlies->whereNotIn('id', $assignmentFriendlyIds)->values(),
        ]);
    }

    /**
     * Generate recommended friendlies per target along with helper stats for the view.
     *
     * @return array{
     *     0: array<int, array<int, array{friendly: Nation, score: float, recommended: bool, assignment_load: int, max_assignments: int, available_slots: int}>>,
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

        $plan->loadMissing(['targets.assignments', 'targets.nation', 'targets.nation.military', 'assignments']);

        $friendlyStats = $this->prepareFriendlyStats($plan, $friendlies, $plan->assignments);

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
                ->reject(fn (array $stat, int $friendlyId) => in_array($friendlyId, $existingFriendlyIds, true))
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
                    $hasCapacity = $stat['remaining_slots'] > 0 && $stat['remaining_offensive_capacity'] > 0;

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
                        'recommended' => $hasCapacity && $relativePower >= $manualFloor,
                        'match_meta' => $match['meta'],
                    ]);
                })
                ->filter(fn (?array $stat) => $stat !== null)
                ->sortBy([
                    ['recommended', 'desc'],
                    ['score', 'desc'],
                ])
                ->values()
                ->map(fn (array $stat) => [
                    'friendly' => $stat['friendly'],
                    'score' => $stat['score'],
                    'recommended' => (bool) ($stat['recommended'] ?? false),
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

    /**
     * Build recommendation rows for a single target to avoid generating an entire target x friendly matrix.
     *
     * @return array<int, array{
     *     friendly: Nation,
     *     score: float,
     *     recommended: bool,
     *     assignment_load: int,
     *     max_assignments: int,
     *     available_slots: int
     * }>
     */
    protected function recommendAssignmentsForTarget(WarPlan $plan, WarPlanTarget $target, Builder $friendliesQuery): array
    {
        $friendlies = $friendliesQuery
            ->with([
                'military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
                'latestSignIn' => fn ($query) => $query->select(
                    'nation_sign_ins.id',
                    'nation_sign_ins.nation_id',
                    'nation_sign_ins.created_at',
                    'nation_sign_ins.mmr_score'
                ),
                'alliance:id,name,acronym',
            ])
            ->get();

        if ($friendlies->isEmpty() || ! $target->nation) {
            return [];
        }

        $assignments = $plan->assignments()
            ->select(['id', 'friendly_nation_id'])
            ->get();
        $friendlyStats = $this->prepareFriendlyStats($plan, $friendlies, $assignments);

        if ($friendlyStats->isEmpty()) {
            return [];
        }

        $matchService = app(NationMatchService::class);
        $existingFriendlyIds = $target->assignments->pluck('friendly_nation_id')->all();

        return $friendlyStats
            ->reject(fn (array $stat, int $friendlyId) => in_array($friendlyId, $existingFriendlyIds, true))
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
                $hasCapacity = $stat['remaining_slots'] > 0 && $stat['remaining_offensive_capacity'] > 0;

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
                    'recommended' => $hasCapacity && $relativePower >= $manualFloor,
                ]);
            })
            ->filter(fn (?array $stat) => $stat !== null)
            ->sortBy([
                ['recommended', 'desc'],
                ['score', 'desc'],
            ])
            ->values()
            ->map(fn (array $stat) => [
                'friendly' => $stat['friendly'],
                'score' => $stat['score'],
                'recommended' => (bool) ($stat['recommended'] ?? false),
                'assignment_load' => $stat['assignment_load'],
                'max_assignments' => $stat['max_assignments'],
                'available_slots' => $stat['remaining_slots'],
            ])
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function prepareFriendlyStats(WarPlan $plan, Collection $friendlies, Collection $assignments): Collection
    {
        $assignmentCounts = $assignments->groupBy('friendly_nation_id')->map->count();
        $activeWarCounts = $this->activeWarCounts($friendlies->pluck('id'));
        $planPreference = min(
            max(1, (int) $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );

        return $friendlies->mapWithKeys(function (Nation $friendly) use ($assignmentCounts, $activeWarCounts, $planPreference) {
            $friendlyId = $friendly->id;
            $assignmentLoad = (int) ($assignmentCounts[$friendlyId] ?? 0);
            $friendlyCounts = $activeWarCounts[$friendlyId] ?? ['offensive' => 0, 'defensive' => 0];
            $availableSlots = min($this->calculateAvailableSlotsForNation($friendly, $friendlyCounts['offensive']), $planPreference);
            $maxAssignments = min($this->maxAssignmentsForNation($friendly), $planPreference);
            $remainingSlots = max(0, $availableSlots - $assignmentLoad);
            $offensiveWars = (int) ($friendlyCounts['offensive'] ?? $friendly->offensive_wars_count ?? 0);
            $defensiveWars = (int) ($friendlyCounts['defensive'] ?? $friendly->defensive_wars_count ?? 0);
            $offensiveCap = (int) config('war.slot_caps.default_offensive', 6);
            $remainingOffensive = max(0, min(
                $planPreference - $assignmentLoad,
                $offensiveCap - ($offensiveWars + $assignmentLoad)
            ));

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

    protected function calculateAvailableSlotsForNation(Nation $friendly, int $activeOffensiveWars): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 6);
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

        return max(0, $base - $activeOffensiveWars);
    }

    protected function maxAssignmentsForNation(Nation $friendly): int
    {
        $base = (int) config('war.slot_caps.default_offensive', 6);

        if (in_array($friendly->alliance_position, ['LEADER', 'HEIR'], true)) {
            return max(1, $base - 1);
        }

        return $base;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function activeWarCounts(Collection $friendlyIds): array
    {
        $ids = $friendlyIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $cacheKey = 'war:plan:active_wars:'.md5($ids->join(','));

        return Cache::remember(
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

    protected function resolveFriendlyAllianceIds(
        WarPlan $plan,
        AllianceMembershipService $membershipService
    ): Collection {
        $friendlyAllianceIds = $plan->friendlyAlliances->pluck('alliance_id')->filter();

        if ($friendlyAllianceIds->isEmpty()) {
            $friendlyAllianceIds = $membershipService->getAllianceIds();
        }

        return $friendlyAllianceIds->unique()->values();
    }

    protected function buildFriendliesQuery(Collection $friendlyAllianceIds): Builder
    {
        return Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->orderBy('leader_name')
            ->select([
                'id',
                'leader_name',
                'nation_name',
                'alliance_id',
                'score',
                'offensive_wars_count',
                'defensive_wars_count',
                'color',
                'num_cities',
                'projects',
                'project_bits',
                'alliance_position',
                'alliance_position_id',
                'vacation_mode_turns',
                'beige_turns',
            ]);
    }

    protected function calculatePreferredAssignmentsPerTarget(
        int $targetCount,
        int $friendlyCount,
        WarPlan $plan
    ): int {
        if ($targetCount === 0 || $friendlyCount === 0) {
            return 0;
        }

        $defensiveCap = (int) config('war.slot_caps.default_defensive', 3);
        $preferredTargetsPerNation = min(
            max(1, $plan->preferred_targets_per_nation),
            (int) config('war.slot_caps.default_offensive', 6)
        );

        return (int) min(
            $defensiveCap,
            max(1, ceil(($friendlyCount * $preferredTargetsPerNation) / $targetCount))
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function cityStatsForFriendlies(Collection $friendlyAllianceIds): array
    {
        $baseQuery = Nation::query()->when($friendlyAllianceIds->isNotEmpty(), function (Builder $query) use ($friendlyAllianceIds) {
            $query->whereIn('alliance_id', $friendlyAllianceIds);
        })->where(function (Builder $query) {
            $query->whereNull('alliance_position')
                ->orWhere('alliance_position', '!=', 'APPLICANT');
        });

        $count = (clone $baseQuery)->count();

        $stats = (clone $baseQuery)
            ->selectRaw('SUM(num_cities) as total_cities, AVG(num_cities) as avg_cities')
            ->first();

        return [
            'count' => $count,
            'total' => (int) ($stats->total_cities ?? 0),
            'avg' => $stats->avg_cities !== null ? (float) $stats->avg_cities : null,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function cityStatsForNationIds(Collection $nationIds): array
    {
        if ($nationIds->isEmpty()) {
            return ['count' => 0, 'total' => 0, 'avg' => null];
        }

        $stats = Nation::query()
            ->whereIn('id', $nationIds)
            ->selectRaw('COUNT(*) as total_count, SUM(num_cities) as total_cities, AVG(num_cities) as avg_cities')
            ->first();

        return [
            'count' => (int) ($stats->total_count ?? 0),
            'total' => (int) ($stats->total_cities ?? 0),
            'avg' => $stats->avg_cities !== null ? (float) $stats->avg_cities : null,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function militaryTotalsForAlliances(Collection $friendlyAllianceIds): array
    {
        if ($friendlyAllianceIds->isEmpty()) {
            return ['soldiers' => 0, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0];
        }

        $totals = Nation::query()
            ->join('nation_military', 'nation_military.nation_id', '=', 'nations.id')
            ->whereIn('nations.alliance_id', $friendlyAllianceIds)
            ->where(function ($query) {
                $query->whereNull('nations.alliance_position')
                    ->orWhere('nations.alliance_position', '!=', 'APPLICANT');
            })
            ->selectRaw('SUM(nation_military.soldiers) as soldiers, SUM(nation_military.tanks) as tanks, SUM(nation_military.aircraft) as aircraft, SUM(nation_military.ships) as ships')
            ->first();

        return [
            'soldiers' => (int) ($totals->soldiers ?? 0),
            'tanks' => (int) ($totals->tanks ?? 0),
            'aircraft' => (int) ($totals->aircraft ?? 0),
            'ships' => (int) ($totals->ships ?? 0),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function militaryTotalsForNationIds(Collection $nationIds): array
    {
        if ($nationIds->isEmpty()) {
            return ['soldiers' => 0, 'tanks' => 0, 'aircraft' => 0, 'ships' => 0];
        }

        $totals = NationMilitary::query()
            ->whereIn('nation_id', $nationIds)
            ->selectRaw('SUM(soldiers) as soldiers, SUM(tanks) as tanks, SUM(aircraft) as aircraft, SUM(ships) as ships')
            ->first();

        return [
            'soldiers' => (int) ($totals->soldiers ?? 0),
            'tanks' => (int) ($totals->tanks ?? 0),
            'aircraft' => (int) ($totals->aircraft ?? 0),
            'ships' => (int) ($totals->ships ?? 0),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function transformFriendlyStats(Collection $friendlyStats): array
    {
        return $friendlyStats
            ->map(fn (array $stat, int $friendlyId) => [
                'friendly_nation_id' => $friendlyId,
                'assignment_load' => $stat['assignment_load'],
                'available_slots' => $stat['available_slots'],
                'remaining_slots' => $stat['remaining_slots'],
                'max_assignments' => $stat['max_assignments'],
                'offensive_wars' => $stat['offensive_wars'],
                'defensive_wars' => $stat['defensive_wars'],
                'remaining_offensive_capacity' => $stat['remaining_offensive_capacity'],
            ])
            ->values()
            ->all();
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
