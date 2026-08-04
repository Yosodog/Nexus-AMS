<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Http\Controllers\Controller;
use App\Models\DiscordQueue;
use App\Models\MilcomAssignment;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarPlan;
use App\Services\Milcom\MilcomOperationStatsService;
use App\Services\Milcom\MilcomQueryService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MilcomPageController extends Controller
{
    public function __construct(
        private readonly MilcomQueryService $queries,
        private readonly MilcomOperationStatsService $operationStats,
    ) {}

    public function dashboard(): View
    {
        $this->authorize('manage-war-room');
        $dashboard = $this->queries->dashboard();

        return view('admin.milcom.dashboard', [
            'dashboard' => $dashboard,
            'summary' => $dashboard['summary'],
            'exceptions' => $dashboard['exceptions'],
            'operations' => $dashboard['operations'],
        ]);
    }

    public function plans(Request $request): View
    {
        $this->authorize('manage-war-room');

        $plans = MilcomOperation::query()
            ->plans()
            ->withCount('objectives')
            ->when($request->filled('search'), fn ($query) => $query
                ->where('name', 'like', '%'.addcslashes($request->string('search')->toString(), '%_').'%'))
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->query('filter') === 'critical_gaps', fn ($query) => $query
                ->whereHas('objectives', fn ($objectiveQuery) => $objectiveQuery
                    ->where('priority_tier', PriorityTier::Critical->value)
                    ->whereIn('status', [
                        ObjectiveStatus::Pending->value,
                        ObjectiveStatus::Blocked->value,
                        ObjectiveStatus::Review->value,
                    ])))
            ->whereNotIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value])
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.milcom.plans.index', [
            'plans' => $plans,
            'summary' => $this->planPortfolioSummary(),
            'filters' => $request->only(['search', 'status', 'filter']),
        ]);
    }

    public function createPlan(): View
    {
        $this->authorize('manage-war-room');

        return view('admin.milcom.plans.create', [
            'settings' => [
                'default_war_type' => SettingService::getValue('milcom_default_war_type')
                    ?: config('milcom.discord.default_war_type', 'ORDINARY'),
                'default_war_reason' => SettingService::getValue('milcom_default_war_reason')
                    ?: config('milcom.discord.default_war_reason', 'Alliance operations'),
                'forum_id' => SettingService::getDiscordWarRoomForumId(),
            ],
        ]);
    }

    public function showPlan(Request $request, MilcomOperation $operation): View
    {
        $this->authorize('manage-war-room');
        abort_unless($operation->type === OperationType::Plan, 404);
        $operation->load(['alliances.alliance', 'nations']);
        $defaultFilter = $operation->status === OperationStatus::Active ? 'all' : 'needs_attention';
        $filter = $request->query('filter', $defaultFilter);
        $isStatsView = $request->query('stage') === 'stats';
        $objectives = collect();
        $selectedDetail = null;

        if (! $isStatsView) {
            $objectives = $this->queries->objectivesCursor($operation, [
                'filter' => $filter,
                'tier' => $request->query('tier'),
                'search' => $request->query('search'),
                'limit' => 50,
                'cursor' => $request->query('cursor'),
            ]);
            $selectedId = (int) $request->query('objective', 0);
            $selectedRow = collect($objectives->items())->first(
                fn (array $row): bool => (int) $row['id'] === $selectedId
            ) ?? collect($objectives->items())->first();
            $selectedObjective = $selectedId > 0
                ? MilcomObjective::query()
                    ->where('operation_id', $operation->id)
                    ->find($selectedId)
                : null;
            $selectedObjective ??= $selectedRow !== null
                ? MilcomObjective::query()->findOrFail($selectedRow['id'])
                : null;
            $selectedDetail = $selectedObjective !== null
                ? $this->queries->objectiveDetail($selectedObjective)['objective']
                : null;
        }

        return view('admin.milcom.plans.show', [
            'operation' => $operation,
            'objectives' => $objectives,
            'selectedObjective' => $selectedDetail,
            'selectedRecommendation' => $selectedDetail['recommendation'] ?? [],
            'selectedTeam' => $selectedDetail['recommendation']['proposed_team'] ?? [],
            'selectedAlternatives' => $selectedDetail['recommendation']['alternatives'] ?? [],
            'selectedBlockers' => $selectedDetail['recommendation']['blockers'] ?? [],
            'selectedWarnings' => $selectedDetail['recommendation']['warnings'] ?? [],
            'selectedReasons' => $selectedDetail['recommendation']['explanations'] ?? [],
            'stats' => $isStatsView ? $this->operationStats->forOperation($operation) : [],
            'summary' => $this->queries->coverageSummary($operation),
            'waves' => $this->wavesForOperation($operation),
            'filters' => [
                ...$request->only(['search', 'tier']),
                'filter' => $filter,
            ],
        ]);
    }

    public function exportPlanCsv(MilcomOperation $operation): StreamedResponse
    {
        $this->authorize('manage-war-room');
        abort_unless($operation->type === OperationType::Plan, 404);

        $wave = (int) data_get($operation->metadata, 'wave', 1);
        $filename = Str::slug($operation->name)."-wave-{$wave}-targets.csv";

        return response()->streamDownload(function () use ($operation, $wave): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, [
                'Wave',
                'Target nation',
                'Target leader',
                'Target URL',
                'Target alliance',
                'Priority',
                'War type',
                'War reason',
                'Declare by',
                'Target status',
                'Assigned nation',
                'Assigned leader',
                'Assigned URL',
                'Assignment status',
                'Match score',
                'Confidence',
                'Declared war ID',
                'Recorded attacks',
            ]);

            $objectives = MilcomObjective::query()
                ->where('operation_id', $operation->id)
                ->with([
                    'target:id,nation_name,leader_name,alliance_id',
                    'target.alliance:id,name',
                    'assignments' => fn ($query) => $query
                        ->whereNotIn('status', [
                            AssignmentStatus::Released->value,
                            AssignmentStatus::Failed->value,
                        ])
                        ->orderBy('rank')
                        ->orderBy('id'),
                    'assignments.friendlyNation:id,nation_name,leader_name',
                ])
                ->withCount([
                    'events as attack_count' => fn ($query) => $query
                        ->where('event_type', 'like', 'war.attack.outgoing.%'),
                ])
                ->lazyById(200);

            foreach ($objectives as $objective) {
                $assignments = $objective->assignments->isNotEmpty()
                    ? $objective->assignments
                    : collect([null]);

                foreach ($assignments as $assignment) {
                    $target = $objective->target;
                    $friendly = $assignment?->friendlyNation;
                    $row = [
                        $wave,
                        $target?->nation_name,
                        $target?->leader_name,
                        $target !== null ? 'https://politicsandwar.com/nation/id='.$target->id : null,
                        $target?->alliance?->name,
                        $objective->priority_tier->value,
                        $objective->war_type,
                        $objective->war_reason,
                        $objective->deadline_at?->toIso8601String(),
                        $objective->status->value,
                        $friendly?->nation_name,
                        $friendly?->leader_name,
                        $friendly !== null ? 'https://politicsandwar.com/nation/id='.$friendly->id : null,
                        $assignment?->status->value,
                        $assignment?->score,
                        $assignment?->confidence,
                        $assignment?->declared_war_id,
                        (int) ($objective->attack_count ?? 0),
                    ];

                    fputcsv($stream, array_map($this->escapeCsvCell(...), $row));
                }
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function counters(Request $request): View
    {
        $this->authorize('manage-war-room');
        $objectiveId = (int) $request->query('objective', 0);
        $operationId = (int) $request->query('operation', 0);
        $incidentId = (int) $request->query('incident', 0);
        $isDeepLink = $objectiveId > 0 || $operationId > 0 || $incidentId > 0;
        $filter = $isDeepLink ? 'any' : $request->query('filter', 'urgent');

        $incidents = $this->queries->incidentsCursor([
            'filter' => $filter,
            'search' => $request->query('search'),
            'objective_id' => $objectiveId ?: null,
            'operation_id' => $operationId ?: null,
            'limit' => 50,
            'cursor' => $request->query('cursor'),
        ]);
        $selectedRow = collect($incidents->items())->first(
            fn (array $row): bool => (int) $row['id'] === $incidentId
        ) ?? collect($incidents->items())->first();
        $selectedDetail = $selectedRow !== null
            ? $this->queries->incidentDetail(
                MilcomIncident::query()->findOrFail($selectedRow['id'])
            )['incident']
            : null;
        $objective = $selectedDetail['objective'] ?? null;

        return view('admin.milcom.counters.index', [
            'incidents' => $incidents,
            'selectedIncident' => $selectedDetail,
            'selectedObjective' => $objective,
            'recommendation' => $objective['recommendation'] ?? [],
            'recommendedTeam' => $objective['recommendation']['proposed_team'] ?? [],
            'alternativeTeams' => $objective['recommendation']['alternatives'] ?? [],
            'blockers' => $objective['recommendation']['blockers'] ?? [],
            'warnings' => $objective['recommendation']['warnings'] ?? [],
            'preflight' => $objective['preflight'] ?? [],
            'timeline' => $objective['events'] ?? [],
            'summary' => $this->queries->counterSummary(),
            'filters' => $request->only(['filter', 'search']),
        ]);
    }

    public function archive(Request $request): View
    {
        $this->authorize('manage-war-room');

        return view('admin.milcom.archive.index', [
            'activeTab' => $request->query('tab', 'v2'),
            'legacyType' => $request->query('legacy_type', 'plans'),
            'archivedOperations' => $this->queries->archivedOperations($request->string('search')->toString()),
            'legacyPlans' => $this->queries->legacyPlans(),
            'legacyCounters' => $this->queries->legacyCounters(),
            'summary' => [
                'v2_operations' => MilcomOperation::query()
                    ->whereIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value])
                    ->count(),
                'legacy_plans' => WarPlan::query()->count(),
                'legacy_counters' => WarCounter::query()->count(),
            ],
        ]);
    }

    public function settings(): View
    {
        $this->authorize('manage-war-room');
        $tagValue = SettingService::getValue('milcom_forum_tag_ids');
        $tags = is_string($tagValue) ? json_decode($tagValue, true) : [];

        return view('admin.milcom.settings', [
            'settings' => [
                'forum_id' => SettingService::getDiscordWarRoomForumId(),
                'defense_role_id' => SettingService::getDiscordWarRoomDefenseRoleId(),
                'forum_tag_ids' => is_array($tags) ? $tags : [],
                'counter_monitoring_enabled' => SettingService::getValue('milcom_counter_monitoring_enabled') !== '0',
                'default_war_type' => SettingService::getValue('milcom_default_war_type')
                    ?: config('milcom.discord.default_war_type', 'ORDINARY'),
                'default_war_reason' => SettingService::getValue('milcom_default_war_reason')
                    ?: config('milcom.discord.default_war_reason', 'Alliance operations'),
                'doctrine_version' => config('milcom.doctrine.version', 'fixed-v1'),
            ],
            'warTypes' => [
                'RAID' => 'Raid',
                'ORDINARY' => 'Ordinary',
                'ATTRITION' => 'Attrition',
            ],
            'health' => $this->integrationHealth(),
        ]);
    }

    public function operationHistory(MilcomOperation $operation): View
    {
        $this->authorize('manage-war-room');
        abort_unless(in_array($operation->status, [
            OperationStatus::Completed,
            OperationStatus::Archived,
        ], true), 404);

        return view('admin.milcom.archive.show', [
            'operation' => $operation,
            'summary' => $this->queries->coverageSummary($operation),
            'objectives' => $operation->objectives()
                ->with([
                    'target:id,nation_name,leader_name,alliance_id',
                    'target.alliance:id,name',
                    'assignments' => fn ($query) => $query
                        ->where('status', '!=', AssignmentStatus::Failed->value)
                        ->orderBy('rank')
                        ->orderBy('id'),
                    'assignments.friendlyNation:id,nation_name,leader_name',
                ])
                ->withCount('assignments')
                ->orderByDesc('priority_score')
                ->orderBy('id')
                ->paginate(50),
            'events' => $operation->events()->latest('id')->limit(100)->get(),
        ]);
    }

    public function legacyHistory(string $type, int $id): View
    {
        $this->authorize('manage-war-room');
        abort_unless(in_array($type, ['plans', 'counters'], true), 404);

        if ($type === 'plans') {
            $record = WarPlan::query()->findOrFail($id);
            $rows = $record->targets()
                ->with(['nation:id,nation_name,leader_name,alliance_id'])
                ->withCount('assignments')
                ->orderByDesc('target_priority_score')
                ->paginate(50);
        } else {
            $record = WarCounter::query()
                ->with('aggressor:id,nation_name,leader_name,alliance_id')
                ->findOrFail($id);
            $rows = $record->assignments()
                ->with('friendlyNation:id,nation_name,leader_name,alliance_id')
                ->orderByDesc('match_score')
                ->paginate(50);
        }

        return view('admin.milcom.archive.legacy', [
            'type' => $type,
            'record' => $record,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function planPortfolioSummary(): array
    {
        $operationIds = MilcomOperation::query()
            ->plans()
            ->whereNotIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value])
            ->pluck('id');
        $staffing = MilcomAssignment::query()
            ->selectRaw('objective_id, COUNT(*) as staffed_depth')
            ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
            ->groupBy('objective_id');
        $summary = MilcomObjective::query()
            ->leftJoinSub($staffing, 'milcom_staffing', fn ($join) => $join
                ->on('milcom_staffing.objective_id', '=', 'milcom_objectives.id'))
            ->whereIn('milcom_objectives.operation_id', $operationIds)
            ->selectRaw(
                <<<'SQL'
COALESCE(SUM(CASE WHEN priority_tier = ? THEN minimum_team_depth ELSE 0 END), 0) AS critical_required,
COALESCE(SUM(CASE WHEN priority_tier = ? THEN CASE WHEN COALESCE(staffed_depth, 0) < minimum_team_depth THEN COALESCE(staffed_depth, 0) ELSE minimum_team_depth END ELSE 0 END), 0) AS critical_staffed,
COALESCE(SUM(CASE WHEN desired_team_depth > 0 AND COALESCE(staffed_depth, 0) = 0 THEN 1 ELSE 0 END), 0) AS unstaffed
SQL,
                [PriorityTier::Critical->value, PriorityTier::Critical->value]
            )
            ->firstOrFail();
        $required = (int) $summary->critical_required;
        $staffed = (int) $summary->critical_staffed;
        $reserved = MilcomAssignment::query()
            ->whereHas('objective.operation', fn ($query) => $query
                ->where('type', OperationType::Plan->value)
                ->whereNotIn('status', [OperationStatus::Completed->value, OperationStatus::Archived->value]))
            ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
            ->count();
        $latestRunIds = MilcomObjective::query()
            ->whereIn('operation_id', $operationIds)
            ->whereNotNull('latest_recommendation_run_id')
            ->distinct()
            ->pluck('latest_recommendation_run_id');
        $totalCapacity = MilcomReadinessSnapshot::query()
            ->whereIn('recommendation_run_id', $latestRunIds)
            ->where('role', 'friendly')
            ->whereNotNull('offensive_capacity')
            ->selectRaw('nation_id, MAX(offensive_capacity) AS maximum_capacity')
            ->groupBy('nation_id')
            ->pluck('maximum_capacity')
            ->sum(fn ($capacity): int => (int) $capacity);

        return [
            'review' => MilcomOperation::query()
                ->plans()
                ->where('status', OperationStatus::Review->value)
                ->count(),
            'critical_coverage_percent' => $required > 0 ? round(($staffed / $required) * 100, 1) : 100,
            'unstaffed' => (int) $summary->unstaffed,
            'member_utilization_percent' => $totalCapacity > 0
                ? round(min(1, $reserved / $totalCapacity) * 100, 1)
                : 0,
        ];
    }

    private function wavesForOperation(MilcomOperation $operation): Collection
    {
        $seriesRootId = (int) data_get($operation->metadata, 'series_root_id', $operation->id);

        return MilcomOperation::query()
            ->plans()
            ->where(function ($query) use ($seriesRootId): void {
                $query->whereKey($seriesRootId)
                    ->orWhere('metadata->series_root_id', $seriesRootId);
            })
            ->get(['id', 'name', 'status', 'current_stage', 'metadata', 'updated_at'])
            ->sortBy(fn (MilcomOperation $wave): int => (int) data_get($wave->metadata, 'wave', 1))
            ->values();
    }

    private function escapeCsvCell(mixed $value): string|int|float|null
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @return array<string, array{status: string, detail: string}>
     */
    private function integrationHealth(): array
    {
        $forumId = SettingService::getDiscordWarRoomForumId();
        $latestDiscordFailure = DiscordQueue::query()
            ->where('status', 'failed')
            ->latest('updated_at')
            ->first();
        $lastWarUpdate = War::query()->max('updated_at');
        $lastWarUpdatedAt = $lastWarUpdate !== null ? Carbon::parse($lastWarUpdate) : null;
        $oldestCounterRun = MilcomRecommendationRun::query()
            ->whereHas('operation', fn ($query) => $query->where('type', OperationType::Counter->value))
            ->whereIn('status', [
                RecommendationRunStatus::Queued->value,
                RecommendationRunStatus::Running->value,
            ])
            ->oldest('created_at')
            ->first();
        $queueDelay = $oldestCounterRun?->created_at?->diffInSeconds(now()) ?? 0;

        return [
            'discord' => [
                'status' => $latestDiscordFailure?->updated_at?->isAfter(now()->subMinutes(10))
                    ? 'degraded'
                    : 'healthy',
                'detail' => $latestDiscordFailure !== null
                    ? 'Last failure '.$latestDiscordFailure->updated_at->diffForHumans()
                    : 'No failed delivery is recorded.',
            ],
            'forum' => [
                'status' => $forumId !== '' ? 'ready' : 'unavailable',
                'detail' => $forumId !== '' ? 'Forum '.$forumId : 'No forum configured.',
            ],
            'subscriptions' => [
                'status' => $lastWarUpdatedAt !== null && now()->diffInMinutes($lastWarUpdatedAt) <= 30
                    ? 'connected'
                    : 'stale',
                'detail' => $lastWarUpdatedAt !== null
                    ? 'Last war update '.$lastWarUpdatedAt->diffForHumans()
                    : 'No war update recorded.',
            ],
            'counter_queue' => [
                'status' => $queueDelay <= 2 ? 'ready' : 'degraded',
                'detail' => $oldestCounterRun !== null
                    ? "Oldest counter has waited {$queueDelay}s."
                    : 'No counter recommendation is waiting.',
            ],
        ];
    }
}
