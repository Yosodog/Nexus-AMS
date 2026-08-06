<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomDispatch;
use App\Models\MilcomEvent;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarPlan;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MilcomQueryService
{
    /**
     * @param  list<int>  $ids
     * @return list<array<string, int|float|string|null>>
     */
    public function searchAlliances(string $search, array $ids = [], int $limit = 12): array
    {
        $limit = min(20, max(1, $limit));
        $search = trim($search);
        $normalizedIds = collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $query = Alliance::query()
            ->select(['id', 'name', 'acronym', 'flag', 'rank', 'score', 'average_score', 'color'])
            ->withCount([
                'nations as member_count' => fn (Builder $query) => $query
                    ->whereNotIn('alliance_position', ['APPLICANT', 'NOALLIANCE']),
            ]);

        if ($normalizedIds->isNotEmpty()) {
            $query->whereKey($normalizedIds->all())
                ->orderByRaw('CASE WHEN `rank` IS NULL OR `rank` <= 0 THEN 1 ELSE 0 END')
                ->orderBy('rank')
                ->orderBy('name')
                ->orderBy('id');
        } else {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $prefix = addcslashes($search, '\\%_').'%';
            $numericId = ctype_digit($search) ? (int) $search : null;

            $query->where(function (Builder $query) use ($like, $numericId): void {
                $query->where('name', 'like', $like)
                    ->orWhere('acronym', 'like', $like);

                if ($numericId !== null) {
                    $query->orWhereKey($numericId);
                }
            })->orderByRaw(
                'CASE WHEN id = ? THEN 0 WHEN name = ? OR acronym = ? THEN 1 WHEN name LIKE ? OR acronym LIKE ? THEN 2 ELSE 3 END',
                [$numericId ?? 0, $search, $search, $prefix, $prefix]
            )->orderByRaw('CASE WHEN `rank` IS NULL OR `rank` <= 0 THEN 1 ELSE 0 END')
                ->orderBy('rank')
                ->orderBy('name')
                ->orderBy('id');
        }

        return $query->limit($limit)
            ->get()
            ->map(fn (Alliance $alliance): array => [
                'id' => (int) $alliance->id,
                'name' => (string) $alliance->name,
                'acronym' => filled($alliance->acronym) ? (string) $alliance->acronym : null,
                'flag' => filled($alliance->flag) ? (string) $alliance->flag : null,
                'rank' => is_numeric($alliance->rank) ? (int) $alliance->rank : null,
                'score' => is_numeric($alliance->score) ? round((float) $alliance->score, 2) : null,
                'average_score' => is_numeric($alliance->average_score) ? round((float) $alliance->average_score, 2) : null,
                'member_count' => (int) $alliance->member_count,
                'url' => 'https://politicsandwar.com/alliance/id='.(int) $alliance->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{summary: array<string, int|float>, exceptions: list<array<string, mixed>>, operations: list<array<string, mixed>>}
     */
    public function dashboard(): array
    {
        $urgentIncidents = MilcomIncident::query()
            ->whereIn('status', [IncidentStatus::New->value, IncidentStatus::Countering->value])
            ->count();
        $criticalGaps = MilcomObjective::query()
            ->where('priority_tier', PriorityTier::Critical->value)
            ->whereIn('status', [ObjectiveStatus::Pending->value, ObjectiveStatus::Blocked->value])
            ->count();
        $staleRuns = MilcomRecommendationRun::query()
            ->where(function (Builder $query): void {
                $query->where('status', RecommendationRunStatus::Failed->value)
                    ->orWhere(function (Builder $query): void {
                        $query->whereIn('status', [
                            RecommendationRunStatus::Queued->value,
                            RecommendationRunStatus::Running->value,
                        ])->where('created_at', '<', now()->subMinutes(5));
                    });
            })
            ->count();
        $discordFailures = MilcomDispatch::query()->where('status', DispatchStatus::Failed->value)->count();
        $liveOperations = MilcomOperation::query()
            ->whereIn('status', [
                OperationStatus::Generating->value,
                OperationStatus::Review->value,
                OperationStatus::Dispatching->value,
                OperationStatus::Active->value,
                OperationStatus::Failed->value,
            ])
            ->count();

        $exceptions = collect()
            ->concat(MilcomIncident::query()
                ->with(['aggressorNation:id,nation_name', 'attackedNation:id,nation_name'])
                ->whereIn('status', [IncidentStatus::New->value, IncidentStatus::Countering->value])
                ->orderBy('detected_at')
                ->limit(20)
                ->get()
                ->map(fn (MilcomIncident $incident): array => [
                    'type' => 'counter',
                    'severity' => 'error',
                    'title' => 'Counter '.$incident->aggressorNation?->nation_name,
                    'title_prefix' => 'Counter',
                    'nation_id' => $incident->aggressor_nation_id,
                    'nation_name' => $incident->aggressorNation?->nation_name,
                    'description' => $incident->attackedNation?->nation_name.' needs a counter team.',
                    'description_nation_id' => $incident->attacked_nation_id,
                    'description_nation_name' => $incident->attackedNation?->nation_name,
                    'description_suffix' => 'needs a counter team.',
                    'detected_at' => $incident->detected_at,
                    'url' => route('admin.milcom.counters', ['incident' => $incident->id]),
                ]))
            ->concat(MilcomObjective::query()
                ->with(['operation:id,name,type', 'target:id,nation_name'])
                ->where('priority_tier', PriorityTier::Critical->value)
                ->where('status', ObjectiveStatus::Blocked->value)
                ->orderBy('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (MilcomObjective $objective): array => [
                    'type' => 'coverage',
                    'severity' => 'error',
                    'title' => 'Critical gap: '.$objective->target?->nation_name,
                    'title_prefix' => 'Critical gap:',
                    'nation_id' => $objective->target_nation_id,
                    'nation_name' => $objective->target?->nation_name,
                    'description' => $objective->operation?->name.' does not have enough assigned nations.',
                    'detected_at' => $objective->updated_at,
                    'url' => $objective->operation?->type === OperationType::Plan
                        ? route('admin.milcom.plans.show', [
                            'operation' => $objective->operation_id,
                            'objective' => $objective->id,
                            'filter' => 'critical',
                        ], false)
                        : route('admin.milcom.counters', ['objective' => $objective->id], false),
                ]))
            ->concat(MilcomDispatch::query()
                ->with(['operation:id,name,type', 'objective:id,target_nation_id'])
                ->where('status', DispatchStatus::Failed->value)
                ->orderBy('failed_at')
                ->limit(10)
                ->get()
                ->map(fn (MilcomDispatch $dispatch): array => [
                    'type' => 'discord',
                    'severity' => 'error',
                    'title' => 'Discord room failed',
                    'description' => $dispatch->operation?->name.' target #'.$dispatch->objective_id.' has a Discord room that needs another try.',
                    'detected_at' => $dispatch->failed_at ?? $dispatch->updated_at,
                    'url' => $dispatch->operation?->type === OperationType::Plan
                        ? route('admin.milcom.plans.show', [
                            'operation' => $dispatch->operation_id,
                            'objective' => $dispatch->objective_id,
                            'filter' => 'all',
                        ], false)
                        : route('admin.milcom.counters', ['objective' => $dispatch->objective_id], false),
                ]))
            ->concat(MilcomEvent::query()
                ->with('operation:id,type')
                ->where('event_type', 'like', 'capacity.conflict.%')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MilcomEvent $event): array => [
                    'type' => 'conflict',
                    'severity' => 'error',
                    'title' => 'Offensive capacity conflict',
                    'description' => "A nation's active wars and reserved team spots now exceed its offensive slots.",
                    'detected_at' => $event->occurred_at,
                    'url' => $event->operation?->type === OperationType::Plan
                        ? route('admin.milcom.plans.show', $event->operation_id, false)
                        : route('admin.wars', [], false),
                ]))
            ->concat(MilcomEvent::query()
                ->where('event_type', 'like', MilcomEvent::RAID_POLICY_VIOLATION_PREFIX.'%')
                ->whereNull('dismissed_at')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MilcomEvent $event): array => $this->raidPolicyExceptionRow($event)))
            ->sortBy('detected_at')
            ->take(50)
            ->values()
            ->all();

        $operations = MilcomOperation::query()
            ->withCount([
                'objectives',
                'objectives as objectives_attention' => fn (Builder $query) => $query->whereIn('status', [
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Blocked->value,
                    ObjectiveStatus::Review->value,
                ]),
                'assignmentsThroughObjectives as assignment_count' => fn (Builder $query) => $query
                    ->whereNotIn('milcom_assignments.status', [
                        AssignmentStatus::Released->value,
                        AssignmentStatus::Failed->value,
                    ]),
            ])
            ->withSum('objectives as desired_depth_total', 'desired_team_depth')
            ->whereNotIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value])
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (MilcomOperation $operation): array => $this->operationSummaryRow($operation))
            ->all();

        return [
            'summary' => [
                'urgent_counters' => $urgentIncidents,
                'critical_gaps' => $criticalGaps,
                'stale_runs' => $staleRuns,
                'discord_failures' => $discordFailures,
                'live_operations' => $liveOperations,
            ],
            'exceptions' => $exceptions,
            'operations' => $operations,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function operationsCursor(array $filters): CursorPaginator
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 25)));

        return MilcomOperation::query()
            ->withCount([
                'objectives',
                'objectives as objectives_attention' => fn (Builder $query) => $query->whereIn('status', [
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Blocked->value,
                    ObjectiveStatus::Review->value,
                ]),
                'assignmentsThroughObjectives as assignment_count' => fn (Builder $query) => $query
                    ->whereNotIn('milcom_assignments.status', [
                        AssignmentStatus::Released->value,
                        AssignmentStatus::Failed->value,
                    ]),
            ])
            ->withSum('objectives as desired_depth_total', 'desired_team_depth')
            ->when(isset($filters['type']), fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query
                ->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_').'%'))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->through(fn (MilcomOperation $operation): array => $this->operationSummaryRow($operation));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function objectivesCursor(MilcomOperation $operation, array $filters): CursorPaginator
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 50)));
        $statusFilter = match ($filters['filter'] ?? null) {
            'blocked' => [ObjectiveStatus::Blocked->value],
            'approved' => [ObjectiveStatus::Approved->value],
            'dispatched' => [ObjectiveStatus::Dispatching->value, ObjectiveStatus::Dispatched->value],
            'engaged' => [ObjectiveStatus::Engaged->value],
            'finished' => [
                ObjectiveStatus::Completed->value,
                ObjectiveStatus::Cancelled->value,
                ObjectiveStatus::Expired->value,
            ],
            default => null,
        };

        return MilcomObjective::query()
            ->where('operation_id', $operation->id)
            ->with([
                'target:id,nation_name,leader_name,alliance_id,score,num_cities,beige_turns,vacation_mode_turns',
                'target.alliance:id,name,acronym',
                'assignments' => fn (HasMany $query) => $query
                    ->select(['id', 'objective_id', 'friendly_nation_id', 'status', 'rank', 'declared_war_id'])
                    ->whereNotIn('status', [
                        AssignmentStatus::Released->value,
                        AssignmentStatus::Failed->value,
                    ])
                    ->orderBy('rank')
                    ->orderBy('id')
                    ->limit(5),
                'assignments.friendlyNation:id,nation_name,leader_name',
                'latestRecommendation' => fn (HasOne $query) => $query->select([
                    'milcom_objective_recommendations.id',
                    'milcom_objective_recommendations.objective_id',
                    'milcom_objective_recommendations.factor_explanations',
                ]),
            ])
            ->withCount([
                'assignments as staffed_depth' => fn (Builder $query) => $query->whereNotIn('status', [
                    AssignmentStatus::Released->value,
                    AssignmentStatus::Failed->value,
                ]),
                'events as attack_count' => fn (Builder $query) => $query
                    ->where('event_type', 'like', 'war.attack.outgoing.%'),
                'events as successful_attack_count' => fn (Builder $query) => $query
                    ->where('event_type', 'like', 'war.attack.outgoing.success.%'),
            ])
            ->when(($filters['filter'] ?? 'needs_attention') === 'needs_attention', function (Builder $query): void {
                $firstHitDeadline = now()->subMinutes((int) config('milcom.live.first_hit_grace_minutes', 15));
                $query->where(function (Builder $query) use ($firstHitDeadline): void {
                    $query->where(function (Builder $query): void {
                        $query->where('priority_tier', '!=', PriorityTier::Hold->value)
                            ->whereIn('status', [
                                ObjectiveStatus::Pending->value,
                                ObjectiveStatus::Blocked->value,
                                ObjectiveStatus::Review->value,
                            ]);
                    })->orWhere(function (Builder $query) use ($firstHitDeadline): void {
                        $query->where('status', ObjectiveStatus::Engaged->value)
                            ->where('engaged_at', '<=', $firstHitDeadline)
                            ->whereDoesntHave('events', fn (Builder $eventQuery) => $eventQuery
                                ->where('event_type', 'like', 'war.attack.outgoing.success.%'));
                    });
                });
            })
            ->when(($filters['filter'] ?? null) === 'critical', fn (Builder $query) => $query
                ->where('priority_tier', PriorityTier::Critical->value))
            ->when($statusFilter !== null, fn (Builder $query) => $query
                ->whereIn('status', $statusFilter))
            ->when(filled($filters['tier'] ?? null), fn (Builder $query) => $query
                ->where('priority_tier', $filters['tier']))
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query
                ->whereHas('target', fn (Builder $targetQuery) => $targetQuery
                    ->where('nation_name', 'like', '%'.addcslashes((string) $filters['search'], '%_').'%')
                    ->orWhere('leader_name', 'like', '%'.addcslashes((string) $filters['search'], '%_').'%')))
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->cursorPaginate($limit)
            ->through(fn (MilcomObjective $objective): array => $this->objectiveRow($objective));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function incidentsCursor(array $filters): CursorPaginator
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 50)));

        return MilcomIncident::query()
            ->with([
                'aggressorNation:id,nation_name,leader_name,alliance_id,score,num_cities',
                'attackedNation:id,nation_name,leader_name,alliance_id,score,num_cities',
                'objective:id,operation_id,status,generation_version',
                'objective.operation:id,generation_version,status',
            ])
            ->when(filled($filters['objective_id'] ?? null), fn (Builder $query) => $query
                ->where('objective_id', (int) $filters['objective_id']))
            ->when(filled($filters['operation_id'] ?? null), fn (Builder $query) => $query
                ->whereHas('objective', fn (Builder $objectiveQuery) => $objectiveQuery
                    ->where('operation_id', (int) $filters['operation_id'])))
            ->when(($filters['filter'] ?? 'urgent') === 'urgent', fn (Builder $query) => $query
                ->whereIn('status', [IncidentStatus::New->value, IncidentStatus::Countering->value]))
            ->when(($filters['filter'] ?? null) === 'covered_by_plan', fn (Builder $query) => $query
                ->where('status', IncidentStatus::CoveredByPlan->value))
            ->when(($filters['filter'] ?? null) === 'blocked', fn (Builder $query) => $query
                ->whereHas('objective', fn (Builder $objectiveQuery) => $objectiveQuery
                    ->where('status', ObjectiveStatus::Blocked->value)))
            ->when(($filters['filter'] ?? null) === 'recommending', fn (Builder $query) => $query
                ->whereHas('objective.latestRecommendationRun', fn (Builder $runQuery) => $runQuery
                    ->whereIn('status', [
                        RecommendationRunStatus::Queued->value,
                        RecommendationRunStatus::Running->value,
                    ])))
            ->when(($filters['filter'] ?? null) === 'all', fn (Builder $query) => $query
                ->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Ignored->value]))
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query
                ->where(function (Builder $query) use ($filters): void {
                    $search = '%'.addcslashes((string) $filters['search'], '%_').'%';
                    $query->whereHas('aggressorNation', fn (Builder $nation) => $nation
                        ->where('nation_name', 'like', $search)
                        ->orWhere('leader_name', 'like', $search))
                        ->orWhereHas('attackedNation', fn (Builder $nation) => $nation
                            ->where('nation_name', 'like', $search)
                            ->orWhere('leader_name', 'like', $search));
                }))
            ->orderBy('detected_at')
            ->orderBy('id')
            ->cursorPaginate($limit)
            ->through(fn (MilcomIncident $incident): array => $this->incidentRow($incident));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function assignmentsCursor(MilcomOperation $operation, array $filters): CursorPaginator
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 50)));

        return MilcomAssignment::query()
            ->whereHas('objective', fn (Builder $query) => $query->where('operation_id', $operation->id))
            ->with([
                'friendlyNation:id,nation_name,leader_name,alliance_id,score,num_cities,offensive_wars_count,defensive_wars_count',
                'friendlyNation.alliance:id,name,acronym',
            ])
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query
                ->where('status', $filters['status']))
            ->orderBy('id')
            ->cursorPaginate($limit)
            ->through(fn (MilcomAssignment $assignment): array => $this->assignmentRow($assignment));
    }

    /**
     * @return array<string, mixed>
     */
    public function objectiveDetail(MilcomObjective $objective): array
    {
        $objective->load([
            'operation:id,name,type,status,generation_version,metadata,discord_forum_id',
            'target.alliance',
            'target.military',
            'assignments' => fn ($query) => $query
                ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
                ->orderBy('rank'),
            'assignments.friendlyNation.alliance',
            'assignments.friendlyNation.military',
            'latestRecommendationRun',
            'recommendations' => fn ($query) => $query->latest('id')->limit(1),
            'dispatches' => fn ($query) => $query->latest('id')->limit(1),
            'events' => fn ($query) => $query->latest('id')->limit(20),
        ]);
        $recommendation = $objective->recommendations->first();
        $latestDispatch = $objective->dispatches->first();
        $friendlyNationIds = $objective->assignments->pluck('friendly_nation_id')->map('intval')->all();
        $storedAlternatives = array_values((array) ($recommendation?->alternatives ?? []));
        $storedAlternativeNationIds = collect($storedAlternatives)
            ->flatMap(fn (array $alternative): array => array_map('intval', $alternative['nation_ids'] ?? []))
            ->unique()
            ->values()
            ->all();
        $targetNationId = (int) $objective->target_nation_id;
        $activePairNationLookup = $storedAlternativeNationIds === [] ? [] : War::query()
            ->active()
            ->betweenNationSets($storedAlternativeNationIds, [$targetNationId])
            ->get(['att_id', 'def_id'])
            ->mapWithKeys(static fn (War $war): array => [
                (int) $war->att_id === $targetNationId
                    ? (int) $war->def_id
                    : (int) $war->att_id => true,
            ])
            ->all();
        $usableAlternatives = collect($storedAlternatives)
            ->reject(fn (array $alternative): bool => collect($alternative['nation_ids'] ?? [])
                ->contains(fn (int|string $nationId): bool => isset($activePairNationLookup[(int) $nationId])))
            ->values()
            ->all();
        $alternativeNationIds = collect($usableAlternatives)
            ->flatMap(fn (array $alternative): array => array_map('intval', $alternative['nation_ids'] ?? []))
            ->all();
        $readinessNationIds = collect($friendlyNationIds)
            ->push((int) $objective->target_nation_id)
            ->concat($alternativeNationIds)
            ->unique()
            ->values()
            ->all();
        $activeWarCounts = War::query()
            ->active()
            ->whereIn('att_id', $friendlyNationIds)
            ->selectRaw('att_id, COUNT(*) as aggregate')
            ->groupBy('att_id')
            ->pluck('aggregate', 'att_id');
        $reservationCounts = MilcomAssignment::query()
            ->whereIn('friendly_nation_id', $friendlyNationIds)
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
                AssignmentStatus::Engaged->value,
            ])
            ->selectRaw('friendly_nation_id, COUNT(*) as aggregate')
            ->groupBy('friendly_nation_id')
            ->pluck('aggregate', 'friendly_nation_id');
        $readinessSnapshots = MilcomReadinessSnapshot::query()
            ->where('recommendation_run_id', $objective->latest_recommendation_run_id)
            ->whereIn('nation_id', $readinessNationIds)
            ->get();
        $friendlySnapshotByNation = $readinessSnapshots
            ->where('role', 'friendly')
            ->keyBy('nation_id');
        $targetSnapshot = $readinessSnapshots
            ->first(fn (MilcomReadinessSnapshot $snapshot): bool => $snapshot->role === 'target'
                && (int) $snapshot->nation_id === (int) $objective->target_nation_id);
        $team = $objective->assignments
            ->map(function (MilcomAssignment $assignment) use (
                $activeWarCounts,
                $reservationCounts,
                $friendlySnapshotByNation,
            ): array {
                $nationId = (int) $assignment->friendly_nation_id;
                $snapshot = $friendlySnapshotByNation->get($nationId);
                $projects = (array) data_get($snapshot?->payload, 'projects', []);
                $capacity = (int) ($snapshot?->offensive_capacity
                    ?? config('milcom.game_rules.base_offensive_slots', 5));

                if ($snapshot?->offensive_capacity === null) {
                    foreach ((array) config('milcom.game_rules.offensive_slot_projects', []) as $project => $modifier) {
                        if ((bool) ($projects[$project] ?? false)) {
                            $capacity += (int) $modifier;
                        }
                    }
                }

                $activeWars = (int) ($activeWarCounts[$nationId] ?? 0);
                $reservations = (int) ($reservationCounts[$nationId] ?? 0);

                return [
                    ...$this->assignmentRow($assignment, $snapshot),
                    'offensive_capacity' => $capacity,
                    'offensive_wars' => $activeWars,
                    'reserved_slots' => $reservations,
                    'offensive_slots_available' => max(0, $capacity - $activeWars - $reservations),
                    'discord_linked' => (bool) data_get($snapshot?->payload, 'discord_linked', false),
                ];
            })
            ->values()
            ->all();
        $alternatives = $this->alternativeTeams(
            $usableAlternatives,
            $friendlySnapshotByNation,
        );
        $factorData = (array) ($recommendation?->factor_explanations ?? []);
        $warnings = $this->warningRows(
            array_values((array) ($factorData['warnings'] ?? [])),
            $team,
        );
        $blockers = count($team) < (int) $objective->minimum_team_depth
            ? $this->blockerRows((array) ($recommendation?->blocker_summary ?? $objective->blocker_summary ?? []))
            : [];

        if (count($team) < (int) $objective->minimum_team_depth && $blockers === []) {
            $blockers[] = [
                'code' => 'minimum_team_depth',
                'message' => sprintf(
                    'The team has %d of %d required nations.',
                    count($team),
                    (int) $objective->minimum_team_depth,
                ),
                'hard' => true,
            ];
        }
        $reasons = $this->factorReasonRows((array) ($factorData['members'] ?? []));
        $snapshotAt = $objective->latestRecommendationRun?->snapshots()->max('fetched_at');

        return [
            'objective' => [
                ...$this->objectiveRow($objective),
                'operation' => [
                    'id' => $objective->operation->id,
                    'name' => $objective->operation->name,
                    'type' => $objective->operation->type->value,
                    'status' => $objective->operation->status->value,
                    'generation_version' => $objective->operation->generation_version,
                ],
                'target' => $this->nationRow($objective->target, $targetSnapshot),
                'assignments' => $team,
                'dispatch' => $latestDispatch !== null ? [
                    'id' => $latestDispatch->id,
                    'version' => $latestDispatch->dispatch_version,
                    'status' => $latestDispatch->status->value,
                    'queue_id' => $latestDispatch->queue_id,
                    'error' => data_get($latestDispatch->errors, 'message')
                        ?? data_get($latestDispatch->errors, 'code'),
                    'failed_at' => $latestDispatch->failed_at,
                    'can_retry' => $latestDispatch->status === DispatchStatus::Failed,
                ] : null,
                'recommendation' => [
                    'run_id' => $objective->latest_recommendation_run_id,
                    'status' => $objective->latestRecommendationRun?->status?->value,
                    'progress_percent' => (int) ($objective->latestRecommendationRun?->progress_percent ?? 0),
                    'team_score' => $recommendation?->team_score,
                    'confidence' => $recommendation?->confidence,
                    'proposed_team' => $team,
                    'alternatives' => $alternatives,
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'explanations' => $reasons,
                    'snapshot_at' => $snapshotAt,
                    'snapshot_relative_time' => $snapshotAt !== null
                        ? Carbon::parse($snapshotAt)->diffForHumans()
                        : null,
                ],
                'preflight' => $this->preflightRows($objective, $team, $warnings, $snapshotAt),
                'events' => $objective->events
                    ->map(fn (MilcomEvent $event): array => $this->eventRow($event))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function incidentDetail(MilcomIncident $incident): array
    {
        $incident->load([
            'aggressorNation.alliance',
            'aggressorNation.military',
            'attackedNation.alliance',
            'attackedNation.military',
            'objective.operation',
            'objective.events' => fn ($query) => $query->latest('id')->limit(20),
        ]);
        $objectiveDetail = $incident->objective !== null
            ? $this->objectiveDetail($incident->objective)['objective']
            : null;

        return [
            'incident' => [
                ...$this->incidentRow($incident),
                'aggressor' => $this->nationRow($incident->aggressorNation),
                'attacked' => $this->nationRow($incident->attackedNation),
                'objective' => $objectiveDetail,
                'events' => $objectiveDetail['events'] ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operationDetail(MilcomOperation $operation): array
    {
        $operation->loadCount(['objectives']);

        return [
            'operation' => $this->operationSummaryRow($operation),
            'summary' => $this->coverageSummary($operation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coverageSummary(MilcomOperation $operation): array
    {
        $staffing = MilcomAssignment::query()
            ->selectRaw('objective_id, COUNT(*) as staffed_depth')
            ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
            ->groupBy('objective_id');
        $summary = MilcomObjective::query()
            ->leftJoinSub($staffing, 'milcom_staffing', fn ($join) => $join
                ->on('milcom_staffing.objective_id', '=', 'milcom_objectives.id'))
            ->where('milcom_objectives.operation_id', $operation->id)
            ->selectRaw(
                <<<'SQL'
COUNT(*) AS objective_count,
COALESCE(SUM(CASE WHEN priority_tier = ? THEN minimum_team_depth ELSE 0 END), 0) AS critical_required,
COALESCE(SUM(CASE WHEN priority_tier = ? THEN CASE WHEN COALESCE(staffed_depth, 0) < minimum_team_depth THEN COALESCE(staffed_depth, 0) ELSE minimum_team_depth END ELSE 0 END), 0) AS critical_staffed,
COALESCE(SUM(desired_team_depth), 0) AS desired_depth,
COALESCE(SUM(CASE WHEN COALESCE(staffed_depth, 0) < desired_team_depth THEN COALESCE(staffed_depth, 0) ELSE desired_team_depth END), 0) AS desired_staffed,
COALESCE(SUM(COALESCE(staffed_depth, 0)), 0) AS assignment_count,
COALESCE(SUM(CASE WHEN priority_tier = ? AND COALESCE(staffed_depth, 0) < minimum_team_depth THEN 1 ELSE 0 END), 0) AS critical_gaps,
COALESCE(SUM(CASE WHEN desired_team_depth > 0 AND COALESCE(staffed_depth, 0) = 0 THEN 1 ELSE 0 END), 0) AS unstaffed,
COALESCE(SUM(CASE WHEN priority_tier != ? AND status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) AS needs_attention,
COALESCE(SUM(CASE WHEN priority_tier != ? AND status IN (?, ?, ?) AND COALESCE(staffed_depth, 0) = 0 THEN 1 ELSE 0 END), 0) AS auto_hold_on_finalize,
COALESCE(SUM(CASE WHEN priority_tier != ? AND status IN (?, ?, ?) AND COALESCE(staffed_depth, 0) > 0 THEN 1 ELSE 0 END), 0) AS finalize_review_required,
COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS approved,
COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS waiting_to_declare,
COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS engaged,
COALESCE(SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) AS finished
SQL,
                [
                    PriorityTier::Critical->value,
                    PriorityTier::Critical->value,
                    PriorityTier::Critical->value,
                    PriorityTier::Hold->value,
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Review->value,
                    ObjectiveStatus::Blocked->value,
                    PriorityTier::Hold->value,
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Review->value,
                    ObjectiveStatus::Blocked->value,
                    PriorityTier::Hold->value,
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Review->value,
                    ObjectiveStatus::Blocked->value,
                    ObjectiveStatus::Approved->value,
                    ObjectiveStatus::Dispatching->value,
                    ObjectiveStatus::Dispatched->value,
                    ObjectiveStatus::Engaged->value,
                    ObjectiveStatus::Completed->value,
                    ObjectiveStatus::Cancelled->value,
                    ObjectiveStatus::Expired->value,
                ]
            )
            ->firstOrFail();
        $criticalRequired = (int) $summary->critical_required;
        $criticalStaffed = (int) $summary->critical_staffed;
        $desired = (int) $summary->desired_depth;
        $desiredStaffed = (int) $summary->desired_staffed;
        $friendlyAllianceIds = $operation->alliances()
            ->where('role', 'friendly')
            ->where('included', true)
            ->pluck('alliance_id');
        $explicitFriendlyIds = $operation->nations()
            ->where('role', 'friendly')
            ->where('included', true)
            ->pluck('nation_id');
        $excludedFriendlyIds = $operation->nations()
            ->where('role', 'friendly')
            ->where('included', false)
            ->pluck('nation_id');
        $fallbackCapacity = max(1, Nation::query()
            ->where(function (Builder $query) use ($friendlyAllianceIds, $explicitFriendlyIds): void {
                if ($friendlyAllianceIds->isNotEmpty()) {
                    $query->whereIn('alliance_id', $friendlyAllianceIds);
                }

                if ($explicitFriendlyIds->isNotEmpty()) {
                    $method = $friendlyAllianceIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('id', $explicitFriendlyIds);
                }
            })
            ->whereNotIn('id', $excludedFriendlyIds)
            ->whereNotIn('alliance_position', ['APPLICANT', 'NOALLIANCE'])
            ->count() * (int) config('milcom.game_rules.base_offensive_slots', 5));
        $runIds = $operation->objectives()
            ->whereNotNull('latest_recommendation_run_id')
            ->distinct()
            ->pluck('latest_recommendation_run_id');
        $snapshotCapacity = (int) MilcomReadinessSnapshot::query()
            ->whereIn('recommendation_run_id', $runIds)
            ->where('role', 'friendly')
            ->whereNotNull('offensive_capacity')
            ->selectRaw('nation_id, MAX(offensive_capacity) as capacity')
            ->groupBy('nation_id')
            ->pluck('capacity')
            ->sum();
        $totalCapacity = $snapshotCapacity > 0 ? $snapshotCapacity : $fallbackCapacity;
        $assignmentCount = (int) $summary->assignment_count;
        $lateFirstHits = $operation->objectives()
            ->where('status', ObjectiveStatus::Engaged->value)
            ->where('engaged_at', '<=', now()->subMinutes((int) config('milcom.live.first_hit_grace_minutes', 15)))
            ->whereDoesntHave('events', fn (Builder $query) => $query
                ->where('event_type', 'like', 'war.attack.outgoing.success.%'))
            ->count();
        $inGameDeliveries = MilcomAssignmentDelivery::query()
            ->where('operation_id', $operation->id)
            ->where('channel', 'in_game')
            ->selectRaw(
                <<<'SQL'
COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed
SQL
            )
            ->first();

        return [
            'objective_count' => (int) $summary->objective_count,
            'critical_minimum_coverage_percent' => $criticalRequired > 0
                ? round(($criticalStaffed / $criticalRequired) * 100, 1)
                : 100.0,
            'critical_gaps' => (int) $summary->critical_gaps,
            'desired_depth_percent' => $desired > 0 ? round(($desiredStaffed / $desired) * 100, 1) : 100.0,
            'member_utilization_percent' => round(min(1, $assignmentCount / $totalCapacity) * 100, 1),
            'conflicts' => MilcomEvent::query()
                ->where('operation_id', $operation->id)
                ->where('event_type', 'like', 'capacity.conflict.%')
                ->count(),
            'unstaffed' => (int) $summary->unstaffed,
            'needs_attention' => (int) $summary->needs_attention,
            'auto_hold_on_finalize' => (int) $summary->auto_hold_on_finalize,
            'finalize_review_required' => (int) $summary->finalize_review_required,
            'late_first_hits' => $lateFirstHits,
            'live_alerts' => (int) $summary->needs_attention + $lateFirstHits,
            'approved' => (int) $summary->approved,
            'waiting_to_declare' => (int) $summary->waiting_to_declare,
            'engaged' => (int) $summary->engaged,
            'finished' => (int) $summary->finished,
            'in_game_pending' => (int) ($inGameDeliveries?->pending ?? 0),
            'in_game_sent' => (int) ($inGameDeliveries?->sent ?? 0),
            'in_game_failed' => (int) ($inGameDeliveries?->failed ?? 0),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function counterSummary(): array
    {
        return [
            'urgent' => MilcomIncident::query()
                ->whereIn('status', [IncidentStatus::New->value, IncidentStatus::Countering->value])
                ->count(),
            'recommending' => MilcomRecommendationRun::query()
                ->whereHas('operation', fn (Builder $query) => $query->where('type', OperationType::Counter->value))
                ->whereIn('status', [
                    RecommendationRunStatus::Queued->value,
                    RecommendationRunStatus::Running->value,
                ])
                ->count(),
            'covered_by_plan' => MilcomIncident::query()
                ->where('status', IncidentStatus::CoveredByPlan->value)
                ->count(),
            'dispatch_failures' => MilcomDispatch::query()
                ->whereHas('operation', fn (Builder $query) => $query->where('type', OperationType::Counter->value))
                ->where('status', DispatchStatus::Failed->value)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recommendationProgress(MilcomRecommendationRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'progress_percent' => $run->progress_percent,
            'objectives_total' => $run->objectives_total,
            'objectives_processed' => $run->objectives_processed,
            'elapsed_ms' => $run->elapsed_ms,
            'failure_details' => $run->failure_details,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function events(array $filters): Collection
    {
        return MilcomEvent::query()
            ->when(isset($filters['operation_id']), fn (Builder $query) => $query
                ->where('operation_id', (int) $filters['operation_id']))
            ->when(isset($filters['objective_id']), fn (Builder $query) => $query
                ->where('objective_id', (int) $filters['objective_id']))
            ->when(isset($filters['incident_id']), fn (Builder $query) => $query
                ->where('incident_id', (int) $filters['incident_id']))
            ->where('id', '>', (int) ($filters['after_id'] ?? 0))
            ->orderBy('id')
            ->limit(min(200, max(1, (int) ($filters['limit'] ?? 100))))
            ->get()
            ->map(fn (MilcomEvent $event): array => $this->eventRow($event));
    }

    public function archivedOperations(string $search = ''): LengthAwarePaginator
    {
        $activeAssignmentStatuses = [AssignmentStatus::Released->value, AssignmentStatus::Failed->value];

        return MilcomOperation::query()
            ->withCount([
                'objectives',
                'assignmentsThroughObjectives as assignment_count' => fn (Builder $query) => $query
                    ->whereNotIn('milcom_assignments.status', $activeAssignmentStatuses),
            ])
            ->withSum('objectives as desired_depth_total', 'desired_team_depth')
            ->whereIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value])
            ->when($search !== '', fn (Builder $query) => $query
                ->where('name', 'like', '%'.addcslashes($search, '%_').'%'))
            ->orderByDesc('completed_at')
            ->paginate(50, pageName: 'v2_page')
            ->through(fn (MilcomOperation $operation): array => [
                ...$this->operationSummaryRow($operation),
                'objective_count' => $operation->objectives_count,
                'assignment_count' => (int) $operation->assignment_count,
                'final_coverage_percent' => (int) $operation->desired_depth_total > 0
                    ? round(min(1, (int) $operation->assignment_count / (int) $operation->desired_depth_total) * 100, 1)
                    : 100.0,
            ]);
    }

    public function legacyPlans(): LengthAwarePaginator
    {
        return WarPlan::query()
            ->withCount(['targets', 'assignments'])
            ->orderByDesc('archived_at')
            ->paginate(50, pageName: 'legacy_plans_page');
    }

    public function legacyCounters(): LengthAwarePaginator
    {
        return WarCounter::query()
            ->with(['aggressor:id,nation_name,leader_name'])
            ->withCount('assignments')
            ->orderByDesc('archived_at')
            ->paginate(50, pageName: 'legacy_counters_page');
    }

    /**
     * @return array<string, mixed>
     */
    public function legacyDetail(string $type, int $id): array
    {
        if ($type === 'plans') {
            $plan = WarPlan::query()->withCount(['targets', 'assignments'])->findOrFail($id);

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'status' => $plan->status,
                'summary' => 'Read-only legacy mass-war plan.',
                'targets_count' => $plan->targets_count,
                'assignments_count' => $plan->assignments_count,
                'archived_at' => $plan->archived_at,
            ];
        }

        $counter = WarCounter::query()
            ->with('aggressor:id,nation_name,leader_name')
            ->withCount('assignments')
            ->findOrFail($id);

        return [
            'id' => $counter->id,
            'aggressor' => $counter->aggressor?->only(['id', 'nation_name', 'leader_name']),
            'status' => $counter->status,
            'summary' => 'Read-only legacy fast counter.',
            'assignments_count' => $counter->assignments_count,
            'discord_channel_id' => $counter->discord_channel_id,
            'archived_at' => $counter->archived_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationSummaryRow(MilcomOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'name' => $operation->name,
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'current_stage' => $operation->current_stage,
            'doctrine_version' => $operation->doctrine_version,
            'generation_version' => $operation->generation_version,
            'deadline_at' => $operation->deadline_at,
            'updated_at' => $operation->updated_at,
            'objectives_count' => $operation->objectives_count ?? null,
            'objectives_attention' => (int) ($operation->objectives_attention ?? 0),
            'coverage_percent' => (int) ($operation->desired_depth_total ?? 0) > 0
                ? round(min(1, (int) ($operation->assignment_count ?? 0) / (int) $operation->desired_depth_total) * 100, 1)
                : 100.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function objectiveRow(MilcomObjective $objective): array
    {
        $recommendation = $objective->relationLoaded('latestRecommendation')
            ? $objective->latestRecommendation
            : ($objective->relationLoaded('recommendations') ? $objective->recommendations->first() : null);
        $warnings = collect((array) data_get($recommendation?->factor_explanations, 'warnings', []));

        return [
            'id' => $objective->id,
            'operation_id' => $objective->operation_id,
            'target_nation_id' => $objective->target_nation_id,
            'target_name' => $objective->target?->nation_name,
            'leader_name' => $objective->target?->leader_name,
            'alliance_name' => $objective->target?->alliance?->name,
            'target' => $objective->relationLoaded('target') && $objective->target !== null
                ? $this->nationRow($objective->target)
                : null,
            'priority_tier' => $objective->priority_tier->value,
            'priority_score' => (float) $objective->priority_score,
            'minimum_team_depth' => $objective->minimum_team_depth,
            'desired_team_depth' => $objective->desired_team_depth,
            'staffed_depth' => (int) ($objective->staffed_depth ?? $objective->assignments_count ?? 0),
            'assigned_nations' => $objective->relationLoaded('assignments')
                ? $objective->assignments
                    ->map(fn (MilcomAssignment $assignment): array => [
                        'id' => $assignment->friendly_nation_id,
                        'nation_name' => $assignment->friendlyNation?->nation_name,
                        'leader_name' => $assignment->friendlyNation?->leader_name,
                        'status' => $assignment->status->value,
                        'declared_war_id' => $assignment->declared_war_id,
                    ])
                    ->values()
                    ->all()
                : [],
            'warning_count' => $warnings->count(),
            'warning_summary' => $this->warningSummary($warnings),
            'attack_count' => (int) ($objective->attack_count ?? 0),
            'successful_attack_count' => (int) ($objective->successful_attack_count ?? 0),
            'first_hit_overdue' => $objective->status === ObjectiveStatus::Engaged
                && $objective->engaged_at?->lte(
                    now()->subMinutes((int) config('milcom.live.first_hit_grace_minutes', 15))
                ) === true
                && (int) ($objective->successful_attack_count ?? 0) === 0,
            'declaration_overdue' => in_array($objective->status, [
                ObjectiveStatus::Approved,
                ObjectiveStatus::Dispatching,
                ObjectiveStatus::Dispatched,
            ], true) && $objective->deadline_at?->isPast() === true,
            'status' => $objective->status->value,
            'war_type' => $objective->war_type,
            'war_reason' => $objective->war_reason,
            'deadline_at' => $objective->deadline_at,
            'generation_version' => $objective->generation_version,
            'updated_at' => $objective->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentRow(MilcomIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'war_id' => $incident->war_id,
            'status' => $incident->status->value,
            'handling_state' => $incident->status->value,
            'detected_at' => $incident->detected_at,
            'detected_relative_time' => $incident->detected_at?->diffForHumans(),
            'aggressor' => $incident->aggressorNation !== null
                ? $this->nationRow($incident->aggressorNation)
                : null,
            'attacked' => $incident->attackedNation !== null
                ? $this->nationRow($incident->attackedNation)
                : null,
            'objective' => $incident->objective !== null ? [
                'id' => $incident->objective->id,
                'status' => $incident->objective->status->value,
                'generation_version' => $incident->objective->generation_version,
                'operation' => $incident->objective->relationLoaded('operation') ? [
                    'generation_version' => $incident->objective->operation?->generation_version,
                ] : null,
            ] : null,
            'coverage_reason' => $incident->coverage_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(
        MilcomAssignment $assignment,
        ?MilcomReadinessSnapshot $snapshot = null,
    ): array {
        return [
            'id' => $assignment->id,
            'friendly_nation_id' => $assignment->friendly_nation_id,
            'friendly' => $this->nationRow($assignment->friendlyNation, $snapshot),
            'score' => (float) $assignment->score,
            'pair_score' => (float) $assignment->score,
            'confidence' => (float) $assignment->confidence,
            'rank' => $assignment->rank,
            'status' => $assignment->status->value,
            'locked' => (bool) $assignment->is_locked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nationRow(
        ?Nation $nation,
        ?MilcomReadinessSnapshot $snapshot = null,
    ): array {
        if ($nation === null) {
            return [];
        }

        $military = $nation->relationLoaded('military') ? $nation->military : null;

        return [
            'id' => $nation->id,
            'nation_name' => $nation->nation_name,
            'leader_name' => $nation->leader_name,
            'alliance_id' => $nation->alliance_id,
            'alliance' => $nation->relationLoaded('alliance') && $nation->alliance !== null ? [
                'id' => $nation->alliance->id,
                'name' => $nation->alliance->name,
                'acronym' => $nation->alliance->acronym,
            ] : null,
            'score' => (float) $nation->score,
            'num_cities' => (int) $nation->num_cities,
            'cities' => (int) $nation->num_cities,
            'beige_turns' => (int) $nation->beige_turns,
            'vacation_mode_turns' => (int) $nation->vacation_mode_turns,
            'soldiers' => $snapshot !== null ? (int) $snapshot->soldiers : ($military !== null ? (int) $military->soldiers : null),
            'tanks' => $snapshot !== null ? (int) $snapshot->tanks : ($military !== null ? (int) $military->tanks : null),
            'aircraft' => $snapshot !== null ? (int) $snapshot->aircraft : ($military !== null ? (int) $military->aircraft : null),
            'ships' => $snapshot !== null ? (int) $snapshot->ships : ($military !== null ? (int) $military->ships : null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $alternatives
     * @return list<array<string, mixed>>
     */
    private function alternativeTeams(array $alternatives, Collection $snapshotByNation): array
    {
        $nationIds = collect($alternatives)
            ->flatMap(fn (array $alternative): array => array_map('intval', $alternative['nation_ids'] ?? []))
            ->unique()
            ->values()
            ->all();
        $nations = Nation::query()
            ->with(['alliance', 'military'])
            ->whereIn('id', $nationIds)
            ->get()
            ->keyBy('id');

        return collect($alternatives)
            ->take(3)
            ->map(fn (array $alternative): array => [
                'nation_ids' => array_map('intval', $alternative['nation_ids'] ?? []),
                'team_score' => (float) ($alternative['score'] ?? 0),
                'score' => (float) ($alternative['score'] ?? 0),
                'partial' => (bool) ($alternative['partial'] ?? false),
                'team' => collect($alternative['nation_ids'] ?? [])
                    ->map(fn (int|string $id): array => $this->nationRow(
                        $nations[(int) $id] ?? null,
                        $snapshotByNation->get((int) $id),
                    ))
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $summary
     * @return list<array{code: string, message: string, hard: bool}>
     */
    private function blockerRows(array $summary): array
    {
        $labels = [
            'wrong_alliance' => 'Not on the friendly list',
            'invalid_alliance_position' => 'Applicant or no alliance',
            'vacation_mode' => 'Vacation mode',
            'target_beige' => 'Target is on beige',
            'out_of_range' => 'Outside war range',
            'no_offensive_slot' => 'No offensive slots',
            'duplicate_war' => 'Already at war with the target',
            'missing_military_data' => 'Missing military data',
            'conflicting_dispatched_assignment' => 'Already assigned to another sent target',
        ];

        return collect($summary)
            ->map(fn (int $count, string $code): array => [
                'code' => $code,
                'message' => ($labels[$code] ?? str($code)->headline()).': '.$count.' '.Str::plural('nation', $count),
                'hard' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>|string>  $warnings
     * @param  list<array<string, mixed>>  $team
     * @return list<array<string, mixed>>
     */
    private function warningRows(array $warnings, array $team): array
    {
        $nationNames = collect($team)
            ->mapWithKeys(function (array $member): array {
                $nationId = (int) ($member['friendly_nation_id'] ?? data_get($member, 'friendly.id', 0));

                return $nationId > 0
                    ? [$nationId => (string) data_get($member, 'friendly.nation_name', 'Nation '.$nationId)]
                    : [];
            });

        return collect($warnings)
            ->map(function (array|string $warning) use ($nationNames): array {
                $row = is_array($warning) ? $warning : ['message' => $warning];
                $nationId = (int) data_get($row, 'context.nation_id', 0);

                if ($nationId > 0 && $nationNames->has($nationId)) {
                    data_set($row, 'context.nation_name', $nationNames->get($nationId));
                }

                return $row;
            })
            ->unique(fn (array $warning): string => implode('|', [
                (string) ($warning['code'] ?? ''),
                (string) data_get($warning, 'context.nation_id', ''),
                (string) ($warning['message'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /** @param  Collection<int, array<string, mixed>|string>  $warnings */
    private function warningSummary(Collection $warnings): ?string
    {
        if ($warnings->isEmpty()) {
            return null;
        }

        $first = $warnings->first();
        $code = is_array($first) ? (string) ($first['code'] ?? '') : '';
        $matchingCount = $code !== ''
            ? $warnings->filter(fn (array|string $warning): bool => is_array($warning) && ($warning['code'] ?? '') === $code)->count()
            : 1;

        return match ($code) {
            'missing_discord_link' => $matchingCount.' assigned '.Str::plural('nation', $matchingCount).' '.($matchingCount === 1 ? 'has' : 'have').' no linked Discord account.',
            'inactive' => $matchingCount.' assigned '.Str::plural('nation', $matchingCount).' '.($matchingCount === 1 ? 'has' : 'have').' been inactive for more than 72 hours.',
            'stale_snapshot' => 'The team data is too old. Build the team again.',
            'existing_load' => $matchingCount.' assigned '.Str::plural('nation', $matchingCount).' already '.($matchingCount === 1 ? 'has' : 'have').' another Milcom team.',
            'low_tactical_score' => 'At least one matchup is weaker than usual.',
            default => is_array($first) ? (string) ($first['message'] ?? 'This target has a warning.') : (string) $first,
        };
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $members
     * @return list<array{message: string}>
     */
    private function factorReasonRows(array $members): array
    {
        $labels = [
            'air' => 'Air matchup',
            'ground' => 'Ground matchup',
            'naval' => 'Naval matchup',
            'readiness' => 'Military readiness',
            'tactical_fit' => 'City and score fit',
            'activity' => 'Recent activity',
        ];

        return collect($members)
            ->flatMap(function (array $member) use ($labels): array {
                return collect((array) ($member['factors'] ?? []))
                    ->map(fn (float|int $value, string $factor): array => [
                        'message' => ($labels[$factor] ?? str($factor)->headline()).': '.round((float) $value, 1),
                    ])
                    ->all();
            })
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $team
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array{label: string, status: string, detail: string}>
     */
    private function preflightRows(
        MilcomObjective $objective,
        array $team,
        array $warnings,
        mixed $snapshotAt,
    ): array {
        $forumId = $objective->operation?->discord_forum_id
            ?: SettingService::getDiscordWarRoomForumId();
        $slotChecks = collect($team)->map(function (array $member): array {
            $isReserved = in_array($member['status'] ?? null, [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
                AssignmentStatus::Engaged->value,
            ], true);
            $available = (int) ($member['offensive_slots_available'] ?? 0);
            $capacity = (int) ($member['offensive_capacity'] ?? 0);
            $active = (int) ($member['offensive_wars'] ?? 0);
            $reserved = (int) ($member['reserved_slots'] ?? 0);

            return [
                'label' => 'Slots for '.($member['friendly']['nation_name'] ?? 'assigned nation'),
                'status' => $isReserved || $available > 0 ? 'ready' : 'failed',
                'detail' => "{$active} active, {$reserved} reserved, {$capacity} total",
            ];
        })->all();

        return [
            [
                'label' => 'Team size',
                'status' => count($team) >= (int) $objective->minimum_team_depth ? 'ready' : 'failed',
                'detail' => count($team).' assigned, '.$objective->minimum_team_depth.' required',
            ],
            ...$slotChecks,
            [
                'label' => 'Data age',
                'status' => collect($warnings)->contains(fn (array $warning): bool => ($warning['code'] ?? '') === 'stale_snapshot')
                    ? 'warning'
                    : 'ready',
                'detail' => $snapshotAt !== null ? 'Updated '.$snapshotAt : 'No military or activity data',
            ],
            [
                'label' => 'Discord accounts',
                'status' => collect($warnings)->contains(fn (array $warning): bool => ($warning['code'] ?? '') === 'missing_discord_link')
                    ? 'warning'
                    : 'ready',
                'detail' => 'Add a reason for any missing accounts',
            ],
            [
                'label' => 'Discord forum',
                'status' => filled($forumId) ? 'ready' : 'failed',
                'detail' => filled($forumId) ? 'Forum is set' : 'Set a Discord forum before creating the room',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raidPolicyExceptionRow(MilcomEvent $event): array
    {
        $payload = $event->payload ?? [];
        $warId = (int) data_get($payload, 'war_id', 0);
        $attackerId = (int) data_get($payload, 'friendly_nation_id', 0);
        $defenderId = (int) data_get($payload, 'target_nation_id', 0);
        $attackerName = data_get($payload, 'friendly_nation_name') ?: "Nation #{$attackerId}";
        $defenderName = data_get($payload, 'target_nation_name') ?: "Nation #{$defenderId}";
        $reasons = collect(data_get($payload, 'raid_policy.reasons', []))
            ->filter(fn ($reason): bool => is_array($reason) && filled($reason['message'] ?? null))
            ->map(fn (array $reason): array => [
                'code' => (string) ($reason['code'] ?? 'raid_policy'),
                'message' => (string) $reason['message'],
                'context' => is_array($reason['context'] ?? null) ? $reason['context'] : [],
            ])
            ->values()
            ->all();

        return [
            'type' => 'raid_policy',
            'severity' => 'error',
            'event_id' => $event->id,
            'title' => 'Raid policy violation',
            'description' => "{$attackerName} declared war on {$defenderName}.",
            'attacker_nation_id' => $attackerId,
            'attacker_nation_name' => $attackerName,
            'defender_nation_id' => $defenderId,
            'defender_nation_name' => $defenderName,
            'reasons' => $reasons,
            'detected_at' => $event->occurred_at,
            'url' => "https://politicsandwar.com/nation/war/timeline/war={$warId}",
            'dismiss_url' => route('api.milcom.events.dismiss', ['event' => $event->id], false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(MilcomEvent $event): array
    {
        $labels = [
            'assignment.alternative_selected' => 'Alternative team selected',
            'assignment.completed' => 'War completed',
            'assignment.engaged' => 'War declared',
            'assignment.manually_set' => 'Nation added by an officer',
            'assignment.released' => 'Nation removed from the team',
            'incident.countering' => 'Counter started',
            'incident.covered_by_plan' => 'Covered by an active plan',
            'incident.detected_monitoring_disabled' => 'Incoming war saved while monitoring was off',
            'objective.approved' => 'Target approved',
            'objective.cancelled' => 'Target cancelled',
            'objective.completed' => 'Target completed',
            'objective.discord_archive_queued' => 'Discord room is being archived',
            'objective.discord_failed' => 'Discord room failed',
            'objective.discord_queued' => 'Discord room started',
            'objective.discord_retry_queued' => 'Discord room retry started',
            'objective.discord_room_attached' => 'Discord room created',
            'objective.expired' => 'Target expired',
            'objective.updated' => 'Target updated',
            'operation.archived' => 'Record archived',
            'operation.activated' => 'Operation started',
            'operation.cloned' => 'New wave created',
            'operation.completed' => 'Operation ended',
            'operation.created' => 'Plan created',
            'operation.scope_committed' => 'Alliances and targets saved',
            'recommendation.candidates_omitted' => 'Some friendly nations had missing data',
            'recommendation.failed' => 'Could not build teams',
            'recommendation.queued' => 'Team building queued',
            'recommendation.succeeded' => 'Teams built',
            'recommendation.targets_blocked' => 'Some targets had missing data',
        ];
        $title = match (true) {
            str_starts_with($event->event_type, 'war.attack.') => 'War attack recorded',
            str_starts_with($event->event_type, MilcomEvent::RAID_POLICY_VIOLATION_PREFIX) => 'Raid policy violation detected',
            str_starts_with($event->event_type, 'war.unplanned_declaration.') => 'Unplanned war declared',
            str_starts_with($event->event_type, 'capacity.conflict.') => 'Offensive slot conflict found',
            default => $labels[$event->event_type]
                ?? str($event->event_type)->replace('.', ' ')->headline()->toString(),
        };

        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'title' => $title,
            'source' => $event->source,
            'payload' => $event->payload,
            'occurred_at' => $event->occurred_at,
            'relative_time' => $event->occurred_at?->diffForHumans(),
            'dismissed_at' => $event->dismissed_at,
            'dismissed_by_user_id' => $event->dismissed_by_user_id,
        ];
    }
}
