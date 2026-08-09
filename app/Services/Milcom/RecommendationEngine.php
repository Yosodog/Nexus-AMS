<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Allocation\AllocationObjective;
use App\Domain\Milcom\Allocation\CandidateEdge;
use App\Domain\Milcom\Allocation\CandidatePool;
use App\Domain\Milcom\Allocation\ScarcityFirstAllocator;
use App\Domain\Milcom\CounterTeamSelector;
use App\Domain\Milcom\EligibilityEvaluator;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Domain\Milcom\FixedDoctrineScorer;
use App\Domain\Milcom\MilcomGameRules;
use App\Domain\Milcom\PairAssessment;
use App\Domain\Milcom\ReadinessProfile;
use App\Jobs\GenerateMilcomRecommendationsJob;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\War;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplPriorityQueue;
use Throwable;

class RecommendationEngine
{
    private const WRITE_CHUNK_SIZE = 200;

    public function __construct(
        private readonly ReadinessRefreshService $refresh,
        private readonly ReadinessSnapshotService $snapshots,
        private readonly EligibilityEvaluator $eligibility,
        private readonly FixedDoctrineScorer $scorer,
        private readonly CounterTeamSelector $counterTeams,
        private readonly ScarcityFirstAllocator $allocator,
        private readonly MilcomGameRules $rules,
        private readonly MilcomEventRecorder $events,
    ) {}

    public function queue(
        MilcomOperation $operation,
        ?MilcomObjective $objective,
        string $trigger,
        ?int $actorUserId = null,
    ): MilcomRecommendationRun {
        $run = DB::transaction(function () use ($operation, $objective, $trigger, $actorUserId): MilcomRecommendationRun {
            $lockedOperation = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            MilcomRecommendationRun::query()
                ->where('operation_id', $lockedOperation->id)
                ->whereIn('status', [
                    RecommendationRunStatus::Queued->value,
                    RecommendationRunStatus::Running->value,
                ])
                ->update([
                    'status' => RecommendationRunStatus::Superseded->value,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            $objectiveIds = $objective !== null
                ? [(int) $objective->id]
                : $lockedOperation->objectives()->open()->orderBy('id')->pluck('id')->map('intval')->all();

            $inputHash = hash('sha256', json_encode([
                'operation_id' => (int) $lockedOperation->id,
                'generation_version' => (int) $lockedOperation->generation_version,
                'objective_ids' => $objectiveIds,
                'doctrine' => FixedDoctrineScorer::VERSION,
            ], JSON_THROW_ON_ERROR));

            $run = MilcomRecommendationRun::query()->create([
                'operation_id' => $lockedOperation->id,
                'objective_id' => $objective?->id,
                'status' => RecommendationRunStatus::Queued,
                'algorithm_version' => FixedDoctrineScorer::VERSION,
                'input_hash' => $inputHash,
                'trigger' => $trigger,
                'progress_percent' => 0,
                'generation_version' => $lockedOperation->generation_version,
                'objectives_total' => count($objectiveIds),
            ]);

            $lockedOperation->forceFill([
                'status' => $lockedOperation->dispatched_at !== null
                    ? OperationStatus::Active
                    : OperationStatus::Generating,
                'current_stage' => 'staffing',
            ])->save();

            $this->events->record(
                eventType: 'recommendation.queued',
                source: $actorUserId !== null ? 'officer' : 'system',
                operationId: $lockedOperation->id,
                objectiveId: $objective?->id,
                actorUserId: $actorUserId,
                payload: ['run_id' => $run->id, 'trigger' => $trigger],
            );

            return $run;
        }, attempts: 5);

        GenerateMilcomRecommendationsJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    public function execute(MilcomRecommendationRun $run): void
    {
        $started = hrtime(true);

        try {
            $run = DB::transaction(function () use ($run): MilcomRecommendationRun {
                $locked = MilcomRecommendationRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($locked->status === RecommendationRunStatus::Superseded) {
                    return $locked;
                }

                $locked->forceFill([
                    'status' => RecommendationRunStatus::Running,
                    'started_at' => now(),
                    'progress_percent' => 2,
                ])->save();

                return $locked;
            }, attempts: 5);

            if ($run->status === RecommendationRunStatus::Superseded) {
                return;
            }

            $operation = $run->operation()->with(['alliances', 'nations'])->firstOrFail();
            $queueDelayMs = $run->created_at?->diffInMilliseconds(now()) ?? 0;

            if ($operation->type === OperationType::Counter && $queueDelayMs > 2_000) {
                Log::warning('Milcom counter recommendation queue delay exceeded budget.', [
                    'operation_id' => $operation->id,
                    'objective_id' => $run->objective_id,
                    'recommendation_run_id' => $run->id,
                    'queue_delay_ms' => $queueDelayMs,
                    'budget_ms' => 2_000,
                ]);
            }

            if ((int) $operation->generation_version !== (int) $run->generation_version) {
                $this->supersede($run);

                return;
            }

            $objectives = $this->objectivesForRun($run);
            $friendlyIds = $this->friendlyNationIds($operation);
            $targetIds = $objectives->pluck('target_nation_id')->map('intval')->all();

            if ($friendlyIds === [] || $targetIds === []) {
                throw new \RuntimeException('Add friendly nations and targets before building teams.');
            }

            $refreshResult = $this->refresh->refresh(array_values(array_unique([...$friendlyIds, ...$targetIds])));
            $missingTargetIds = $refreshResult->missingFrom($targetIds);

            if ($missingTargetIds !== [] && $operation->type === OperationType::Counter) {
                throw new \RuntimeException(
                    'Politics & War did not return current data for these targets: '
                    .implode(', ', array_slice($missingTargetIds, 0, 20))
                );
            }

            if ($missingTargetIds !== []) {
                $context = [
                    'operation_id' => $operation->id,
                    'recommendation_run_id' => $run->id,
                    'omitted_count' => count($missingTargetIds),
                    'nation_ids' => array_slice($missingTargetIds, 0, 50),
                ];
                Log::warning('Milcom plan targets missing from the live readiness refresh were blocked.', $context);
                $this->events->record(
                    eventType: 'recommendation.targets_blocked',
                    operationId: $operation->id,
                    payload: $context,
                );
            }

            $refreshedFriendlyIds = $refreshResult->refreshedFrom($friendlyIds);
            $omittedFriendlyIds = array_values(array_diff($friendlyIds, $refreshedFriendlyIds));

            if ($omittedFriendlyIds !== []) {
                $context = [
                    'operation_id' => $operation->id,
                    'objective_id' => $run->objective_id,
                    'recommendation_run_id' => $run->id,
                    'omitted_count' => count($omittedFriendlyIds),
                    'nation_ids' => array_slice($omittedFriendlyIds, 0, 50),
                ];
                Log::warning('Milcom omitted friendly candidates missing from the live readiness refresh.', $context);
                $this->events->record(
                    eventType: 'recommendation.candidates_omitted',
                    operationId: $operation->id,
                    objectiveId: $run->objective_id,
                    payload: $context,
                );
            }

            if ($refreshedFriendlyIds === []) {
                throw new \RuntimeException('Politics & War did not return current data for any friendly nations.');
            }

            $friendlyIds = $refreshedFriendlyIds;
            $refreshedTargetIds = $refreshResult->refreshedFrom($targetIds);
            $profiles = $this->snapshots->capture(
                $run,
                $friendlyIds,
                $refreshedTargetIds,
                $refreshResult->fetchedAt,
            );
            $run->forceFill(['progress_percent' => 15])->save();
            $this->logMemoryCheckpoint($run, 'snapshots_captured', [
                'profiles' => count($profiles),
            ]);

            $allowedAllianceIds = $operation->alliances
                ->where('role', 'friendly')
                ->where('included', true)
                ->pluck('alliance_id')
                ->map('intval')
                ->values()
                ->all();
            $allowedNationIds = $operation->nations
                ->where('role', 'friendly')
                ->where('included', true)
                ->pluck('nation_id')
                ->map('intval')
                ->values()
                ->all();
            $activePairs = $this->activeWarPairs($friendlyIds, $refreshedTargetIds);
            $analysis = $this->analyze(
                $run,
                $operation,
                $objectives,
                $friendlyIds,
                $profiles,
                $allowedAllianceIds,
                $allowedNationIds,
                $activePairs,
            );
            $this->logMemoryCheckpoint($run, 'analysis_complete', [
                'objectives' => $objectives->count(),
            ]);

            if (MilcomRecommendationRun::query()->whereKey($run->id)->value('status')
                === RecommendationRunStatus::Superseded->value) {
                return;
            }

            if ($operation->type === OperationType::Counter) {
                $this->persistCounterRecommendation($run, $objectives->firstOrFail(), $analysis);
            } else {
                $this->persistPlanRecommendations(
                    $run,
                    $operation,
                    $objectives,
                    $profiles,
                    $analysis,
                    $allowedAllianceIds,
                    $allowedNationIds,
                    $activePairs,
                );
            }

            $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);

            DB::transaction(function () use ($run, $elapsedMs, $objectives): void {
                $lockedOperation = MilcomOperation::query()
                    ->lockForUpdate()
                    ->findOrFail($run->operation_id);
                $freshRun = MilcomRecommendationRun::query()
                    ->lockForUpdate()
                    ->findOrFail($run->id);

                if ($freshRun->status === RecommendationRunStatus::Superseded
                    || (int) $freshRun->generation_version !== (int) $lockedOperation->generation_version
                    || $lockedOperation->status->isTerminal()) {
                    if ($freshRun->status !== RecommendationRunStatus::Superseded) {
                        $freshRun->forceFill([
                            'status' => RecommendationRunStatus::Superseded,
                            'finished_at' => now(),
                        ])->save();
                    }

                    return;
                }

                if ($freshRun->status !== RecommendationRunStatus::Running) {
                    return;
                }

                $freshRun->forceFill([
                    'status' => RecommendationRunStatus::Succeeded,
                    'progress_percent' => 100,
                    'objectives_processed' => $objectives->count(),
                    'elapsed_ms' => $elapsedMs,
                    'failure_details' => null,
                    'finished_at' => now(),
                ])->save();

                $lockedOperation->forceFill([
                    'status' => $lockedOperation->dispatched_at !== null
                        ? OperationStatus::Active
                        : OperationStatus::Review,
                    'current_stage' => 'staffing',
                    'generated_at' => now(),
                    'failed_at' => null,
                    'failure_details' => null,
                ])->save();

                $this->events->record(
                    eventType: 'recommendation.succeeded',
                    operationId: $lockedOperation->id,
                    objectiveId: $freshRun->objective_id,
                    payload: ['run_id' => $freshRun->id, 'elapsed_ms' => $elapsedMs],
                );
            }, attempts: 5);

            if (MilcomRecommendationRun::query()->whereKey($run->id)->value('status')
                === RecommendationRunStatus::Succeeded->value) {
                $budgetMs = $operation->type === OperationType::Counter ? 5_000 : 60_000;
                $context = [
                    'operation_id' => $operation->id,
                    'operation_type' => $operation->type->value,
                    'objective_id' => $run->objective_id,
                    'recommendation_run_id' => $run->id,
                    'objectives' => $objectives->count(),
                    'queue_delay_ms' => $queueDelayMs,
                    'elapsed_ms' => $elapsedMs,
                    'budget_ms' => $budgetMs,
                ];
                Log::info('Milcom recommendation completed.', $context);

                if ($elapsedMs > $budgetMs) {
                    Log::warning('Milcom recommendation exceeded latency budget.', $context);
                }
            }
        } catch (Throwable $exception) {
            if (MilcomRecommendationRun::query()->whereKey($run->id)->value('status')
                === RecommendationRunStatus::Superseded->value) {
                return;
            }

            MilcomRecommendationRun::query()->whereKey($run->id)->update([
                'status' => RecommendationRunStatus::Failed->value,
                'failure_details' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
                'elapsed_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            MilcomOperation::query()->whereKey($run->operation_id)->update([
                'status' => OperationStatus::Failed->value,
                'failed_at' => now(),
                'failure_details' => ['message' => $exception->getMessage()],
                'updated_at' => now(),
            ]);

            $this->events->record(
                eventType: 'recommendation.failed',
                operationId: $run->operation_id,
                objectiveId: $run->objective_id,
                payload: ['run_id' => $run->id, 'message' => $exception->getMessage()],
            );
            Log::error('Milcom recommendation failed.', [
                'operation_id' => $run->operation_id,
                'objective_id' => $run->objective_id,
                'recommendation_run_id' => $run->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return EloquentCollection<int, MilcomObjective>
     */
    private function objectivesForRun(MilcomRecommendationRun $run): EloquentCollection
    {
        return MilcomObjective::query()
            ->where('operation_id', $run->operation_id)
            ->when($run->objective_id !== null, fn ($query) => $query->whereKey($run->objective_id))
            ->open()
            ->with(['assignments'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function friendlyNationIds(MilcomOperation $operation): array
    {
        $allianceIds = $operation->alliances
            ->where('role', 'friendly')
            ->where('included', true)
            ->pluck('alliance_id')
            ->map('intval')
            ->values()
            ->all();

        $explicitIncluded = $operation->nations
            ->where('role', 'friendly')
            ->where('included', true)
            ->pluck('nation_id')
            ->map('intval')
            ->values()
            ->all();

        $explicitExcluded = $operation->nations
            ->where('role', 'friendly')
            ->where('included', false)
            ->pluck('nation_id')
            ->map('intval')
            ->values()
            ->all();

        if ($allianceIds === [] && $explicitIncluded === []) {
            return [];
        }

        return Nation::query()
            ->where(function ($query) use ($allianceIds, $explicitIncluded): void {
                if ($allianceIds !== []) {
                    $query->whereIn('alliance_id', $allianceIds);
                }

                if ($explicitIncluded !== []) {
                    $method = $allianceIds !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('id', $explicitIncluded);
                }
            })
            ->whereNotIn('id', $explicitExcluded)
            ->whereNotIn('alliance_position', ['APPLICANT', 'NOALLIANCE'])
            ->orderBy('id')
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    /**
     * @param  list<int>  $friendlyIds
     * @param  list<int>  $targetIds
     * @return array<string, bool>
     */
    private function activeWarPairs(array $friendlyIds, array $targetIds): array
    {
        $friendlyLookup = array_fill_keys($friendlyIds, true);
        $targetLookup = array_fill_keys($targetIds, true);

        return War::query()
            ->active()
            ->betweenNationSets($friendlyIds, $targetIds)
            ->get(['att_id', 'def_id'])
            ->mapWithKeys(static function (War $war) use ($friendlyLookup, $targetLookup): array {
                if (isset($friendlyLookup[(int) $war->att_id], $targetLookup[(int) $war->def_id])) {
                    return ["{$war->att_id}:{$war->def_id}" => true];
                }

                if (isset($friendlyLookup[(int) $war->def_id], $targetLookup[(int) $war->att_id])) {
                    return ["{$war->def_id}:{$war->att_id}" => true];
                }

                return [];
            })
            ->all();
    }

    /**
     * @param  EloquentCollection<int, MilcomObjective>  $objectives
     * @param  list<int>  $friendlyIds
     * @param  array<int, ReadinessProfile>  $profiles
     * @param  list<int>  $allowedAllianceIds
     * @param  list<int>  $allowedNationIds
     * @param  array<string, bool>  $activePairs
     * @return array<int, array<string, mixed>>
     */
    private function analyze(
        MilcomRecommendationRun $run,
        MilcomOperation $operation,
        EloquentCollection $objectives,
        array $friendlyIds,
        array $profiles,
        array $allowedAllianceIds,
        array $allowedNationIds,
        array $activePairs,
    ): array {
        $analysis = [];
        $processed = 0;
        $assessedAt = new DateTimeImmutable;
        $allowedAllianceLookup = array_fill_keys($allowedAllianceIds, true);
        $allowedNationLookup = array_fill_keys($allowedNationIds, true);
        $friendlyBlockerMasks = [];

        if ($operation->type === OperationType::Plan) {
            foreach ($friendlyIds as $friendlyId) {
                $friendlyBlockerMasks[$friendlyId] = $this->eligibility->friendlyAllocationBlockerMask(
                    $profiles[$friendlyId],
                    $allowedAllianceLookup,
                    $allowedNationLookup,
                );
            }
        }

        foreach ($objectives as $objective) {
            $target = $profiles[(int) $objective->target_nation_id] ?? null;
            $blockers = [];
            $warnings = [];
            $candidateLimit = $operation->type === OperationType::Counter
                ? (int) config('milcom.doctrine.counter_combination_pool', 20)
                : (int) config('milcom.doctrine.candidate_limit_per_objective', 40);
            $topCandidates = new SplPriorityQueue;
            $topCandidates->setExtractFlags(SplPriorityQueue::EXTR_DATA);

            if ($target === null) {
                $analysis[$objective->id] = $operation->type === OperationType::Counter ? [
                    'assessments' => [],
                    'blockers' => ['missing_military_data' => 1],
                    'warnings' => [],
                ] : [
                    'candidate_pool' => CandidatePool::empty((int) $objective->id),
                    'blockers' => ['missing_military_data' => 1],
                ];
                $processed++;

                continue;
            }

            foreach ($friendlyIds as $friendlyId) {
                $friendly = $profiles[$friendlyId];

                if ($operation->type === OperationType::Plan) {
                    $blockerMask = $this->eligibility->allocationBlockerMask(
                        $friendly,
                        $target,
                        isset($activePairs["{$friendlyId}:{$target->nationId}"]),
                        $friendlyBlockerMasks[$friendlyId],
                    );

                    if ($blockerMask !== 0) {
                        foreach ($this->eligibility->blockerCodes($blockerMask) as $code) {
                            $blockers[$code] = (int) ($blockers[$code] ?? 0) + 1;
                        }

                        continue;
                    }

                    $candidate = $this->scorer->allocationEdge(
                        (int) $objective->id,
                        $friendly,
                        $target,
                        $assessedAt,
                    );
                    $topCandidates->insert($candidate, [
                        -$candidate->score,
                        -$candidate->confidence,
                        $candidate->nationId,
                    ]);

                    if ($topCandidates->count() > $candidateLimit) {
                        $topCandidates->extract();
                    }

                    continue;
                }

                $eligibility = $this->eligibility->evaluate(
                    $friendly,
                    $target,
                    $allowedAllianceIds,
                    $operation->type,
                    isset($activePairs["{$friendlyId}:{$target->nationId}"]),
                    at: $assessedAt,
                    allowedFriendlyNationIds: $allowedNationIds,
                );

                if (! $eligibility->eligible()) {
                    foreach ($eligibility->blockers as $blocker) {
                        $code = (string) $blocker['code'];
                        $blockers[$code] = (int) ($blockers[$code] ?? 0) + 1;
                    }

                    continue;
                }

                $assessment = $this->scorer->assess($friendly, $target, $assessedAt);
                $topCandidates->insert($assessment, [
                    -$assessment->score,
                    -$assessment->confidence,
                    $assessment->friendlyNationId,
                ]);

                if ($topCandidates->count() > $candidateLimit) {
                    $topCandidates->extract();
                }

                if ($operation->type === OperationType::Counter) {
                    $warnings[$friendlyId] = [...$eligibility->warnings, ...$assessment->warnings];
                }
            }

            $candidates = [];

            while (! $topCandidates->isEmpty()) {
                $candidates[] = $topCandidates->extract();
            }

            $candidates = array_reverse($candidates);

            if ($operation->type === OperationType::Counter) {
                $retainedNationIds = array_fill_keys(
                    array_map(
                        static fn (PairAssessment $assessment): int => $assessment->friendlyNationId,
                        $candidates,
                    ),
                    true,
                );
                $analysis[$objective->id] = [
                    'assessments' => $candidates,
                    'blockers' => $blockers,
                    'warnings' => array_intersect_key($warnings, $retainedNationIds),
                ];
            } else {
                $analysis[$objective->id] = [
                    'candidate_pool' => CandidatePool::fromEdges(
                        (int) $objective->id,
                        $candidates,
                    ),
                    'blockers' => $blockers,
                ];
            }
            $processed++;

            if ($processed % 25 === 0) {
                $percent = 15 + (int) floor(($processed / max(1, $objectives->count())) * 55);
                MilcomRecommendationRun::query()->whereKey($run->id)->update([
                    'progress_percent' => min(70, $percent),
                    'objectives_processed' => $processed,
                    'updated_at' => now(),
                ]);
            }
        }

        return $analysis;
    }

    /**
     * @param  array<int, array{assessments: list<PairAssessment>, blockers: array<string, int>, warnings: array<int, list<array<string, mixed>>>}>  $analysis
     */
    private function persistCounterRecommendation(
        MilcomRecommendationRun $run,
        MilcomObjective $objective,
        array $analysis,
    ): void {
        $objectiveAnalysis = $analysis[$objective->id];
        $selection = $this->counterTeams->select($objectiveAnalysis['assessments']);
        $recommendedIds = $selection['recommended']['nation_ids'] ?? [];
        $assessmentByNation = collect($objectiveAnalysis['assessments'])
            ->keyBy('friendlyNationId');

        DB::transaction(function () use (
            $run,
            $objective,
            $selection,
            $recommendedIds,
            $assessmentByNation,
            $objectiveAnalysis
        ): void {
            if (! $this->runIsWritableLocked($run)) {
                return;
            }

            $lockedObjective = MilcomObjective::query()
                ->lockForUpdate()
                ->findOrFail($objective->id);
            MilcomAssignment::query()
                ->where('objective_id', $lockedObjective->id)
                ->where('status', AssignmentStatus::Proposed->value)
                ->where('is_locked', false)
                ->delete();

            $rows = [];

            foreach ($recommendedIds as $index => $nationId) {
                /** @var PairAssessment $assessment */
                $assessment = $assessmentByNation[$nationId];
                $rows[] = $this->assignmentRow($run, $lockedObjective, $assessment, $index + 1);
            }

            $this->upsertAssignments($rows);

            MilcomObjectiveRecommendation::query()->updateOrCreate(
                [
                    'recommendation_run_id' => $run->id,
                    'objective_id' => $lockedObjective->id,
                ],
                [
                    'team_score' => $selection['recommended']['score'] ?? null,
                    'confidence' => $recommendedIds !== []
                        ? round(collect($recommendedIds)->avg(
                            fn (int $id): float => $assessmentByNation[$id]->confidence
                        ), 2)
                        : null,
                    'proposed_team' => $selection['recommended'] ?? ['nation_ids' => [], 'partial' => true],
                    'alternatives' => $selection['alternatives'],
                    'blocker_summary' => $objectiveAnalysis['blockers'],
                    'factor_explanations' => [
                        'members' => collect($recommendedIds)
                            ->mapWithKeys(fn (int $id): array => [$id => $assessmentByNation[$id]->jsonSerialize()])
                            ->all(),
                        'warnings' => $this->warningsForNations(
                            $recommendedIds,
                            $objectiveAnalysis['warnings'],
                        ),
                    ],
                ],
            );

            $staffed = count($recommendedIds);
            $lockedObjective->forceFill([
                'latest_recommendation_run_id' => $run->id,
                'status' => $this->statusAfterRecommendation($lockedObjective, $staffed),
                'blocker_summary' => $objectiveAnalysis['blockers'],
            ])->save();
        }, attempts: 5);
    }

    /**
     * @param  EloquentCollection<int, MilcomObjective>  $objectives
     * @param  array<int, ReadinessProfile>  $profiles
     * @param  array<int, array{candidate_pool: CandidatePool, blockers: array<string, int>}>  $analysis
     * @param  list<int>  $allowedAllianceIds
     * @param  list<int>  $allowedNationIds
     * @param  array<string, bool>  $activePairs
     */
    private function persistPlanRecommendations(
        MilcomRecommendationRun $run,
        MilcomOperation $operation,
        EloquentCollection $objectives,
        array $profiles,
        array $analysis,
        array $allowedAllianceIds,
        array $allowedNationIds,
        array $activePairs,
    ): void {
        $allocationObjectives = [];
        $edgesByObjective = [];
        $capacityByNation = [];
        $invalidLockedAssignmentIds = [];
        $targetLookup = array_fill_keys(
            $objectives->pluck('target_nation_id')->map('intval')->all(),
            true
        );

        foreach ($profiles as $profile) {
            if (! isset($targetLookup[$profile->nationId])) {
                $capacityByNation[$profile->nationId] = $this->rules->availableOffensiveSlots($profile);
            }
        }

        foreach ($objectives as $objective) {
            $lockedAssignments = [];

            foreach ($objective->assignments as $assignment) {
                $preserved = in_array($assignment->status, [
                    AssignmentStatus::Approved,
                    AssignmentStatus::Dispatched,
                    AssignmentStatus::Engaged,
                ], true);
                $eligibleManualLock = $assignment->status === AssignmentStatus::Proposed
                    && $assignment->is_locked
                    && $this->assignmentPassesEligibility(
                        $operation,
                        $objective,
                        $assignment,
                        $profiles,
                        $allowedAllianceIds,
                        $allowedNationIds,
                        $activePairs,
                    );

                if ($preserved || $eligibleManualLock) {
                    $lockedAssignments[] = $assignment;
                } elseif ($assignment->status === AssignmentStatus::Proposed && $assignment->is_locked) {
                    $invalidLockedAssignmentIds[] = (int) $assignment->id;
                }
            }

            $lockedIds = array_map(
                static fn (MilcomAssignment $assignment): int => (int) $assignment->friendly_nation_id,
                $lockedAssignments,
            );

            foreach ($lockedAssignments as $assignment) {
                if (in_array($assignment->status, [
                    AssignmentStatus::Approved,
                    AssignmentStatus::Dispatched,
                    AssignmentStatus::Engaged,
                ], true)) {
                    $nationId = (int) $assignment->friendly_nation_id;
                    $capacityByNation[$nationId] = (int) ($capacityByNation[$nationId] ?? 0) + 1;
                }
            }

            $allocationObjectives[] = new AllocationObjective(
                id: (int) $objective->id,
                tier: $objective->priority_tier,
                minimumDepth: (int) $objective->minimum_team_depth,
                desiredDepth: (int) $objective->desired_team_depth,
                lockedNationIds: $lockedIds,
            );
            $edgesByObjective[(int) $objective->id] = $analysis[$objective->id]['candidate_pool'];
        }

        $result = $this->allocator->allocatePrepared(
            $allocationObjectives,
            $edgesByObjective,
            $capacityByNation,
        );
        $this->logMemoryCheckpoint($run, 'allocation_complete', [
            'objectives' => count($result->assignments),
        ]);

        DB::transaction(function () use (
            $run,
            $operation,
            $objectives,
            $profiles,
            $analysis,
            $result,
            $invalidLockedAssignmentIds,
            $allowedAllianceIds,
            $allowedNationIds,
            $activePairs,
        ): void {
            if (! $this->runIsWritableLocked($run)) {
                return;
            }

            $objectiveIds = $objectives->modelKeys();
            MilcomAssignment::query()
                ->whereIn('objective_id', $objectiveIds)
                ->where('status', AssignmentStatus::Proposed->value)
                ->where('is_locked', false)
                ->delete();
            $releasedLockedCount = MilcomAssignment::query()
                ->whereIn('id', $invalidLockedAssignmentIds)
                ->where('status', AssignmentStatus::Proposed->value)
                ->update([
                    'status' => AssignmentStatus::Released->value,
                    'is_locked' => false,
                    'override_reason' => 'Automatically released because the nation no longer passes the hard eligibility checks.',
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($releasedLockedCount > 0) {
                $this->events->record(
                    eventType: 'recommendation.invalid_locks_released',
                    operationId: $run->operation_id,
                    payload: [
                        'run_id' => $run->id,
                        'assignment_ids' => $invalidLockedAssignmentIds,
                    ],
                );
            }

            $assignmentRows = [];

            foreach ($result->assignments as $objectiveId => $assigned) {
                foreach ($assigned as $rank => $assignment) {
                    if ($assignment['locked']) {
                        continue;
                    }

                    $assignmentRows[] = [
                        'objective_id' => $objectiveId,
                        'friendly_nation_id' => $assignment['nation_id'],
                        'score' => $assignment['score'],
                        'confidence' => $assignment['confidence'],
                        'rank' => $rank + 1,
                        'status' => AssignmentStatus::Proposed->value,
                        'is_locked' => false,
                        'recommendation_run_id' => $run->id,
                        'factor_explanations' => null,
                        'released_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($assignmentRows) >= self::WRITE_CHUNK_SIZE) {
                        $this->upsertAssignments($assignmentRows);
                        $assignmentRows = [];
                    }
                }
            }

            $this->upsertAssignments($assignmentRows);

            foreach ($objectives->chunk(self::WRITE_CHUNK_SIZE) as $objectiveChunk) {
                $lockedObjectives = MilcomObjective::query()
                    ->whereIn('id', $objectiveChunk->modelKeys())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $recommendationRows = [];

                foreach ($objectiveChunk as $objective) {
                    $lockedObjective = $lockedObjectives->get($objective->id);

                    if ($lockedObjective === null) {
                        continue;
                    }

                    $assigned = $result->assignments[$objective->id] ?? [];
                    $selectedIds = array_map('intval', array_column($assigned, 'nation_id'));
                    $details = $this->planRecommendationDetails(
                        $operation,
                        $lockedObjective,
                        $selectedIds,
                        $assigned,
                        $analysis[$objective->id]['candidate_pool'],
                        $profiles,
                        $allowedAllianceIds,
                        $allowedNationIds,
                        $activePairs,
                    );
                    $timestamp = now();

                    $recommendationRows[] = [
                        'recommendation_run_id' => $run->id,
                        'objective_id' => $objective->id,
                        'team_score' => $details['team_score'],
                        'confidence' => $details['confidence'],
                        'proposed_team' => json_encode([
                            'nation_ids' => $selectedIds,
                            'minimum_met' => count($assigned) >= $lockedObjective->minimum_team_depth,
                            'desired_met' => count($assigned) >= $lockedObjective->desired_team_depth,
                        ], JSON_THROW_ON_ERROR),
                        'alternatives' => json_encode($details['alternatives'], JSON_THROW_ON_ERROR),
                        'blocker_summary' => json_encode($analysis[$objective->id]['blockers'], JSON_THROW_ON_ERROR),
                        'factor_explanations' => json_encode([
                            'members' => $details['members'],
                            'warnings' => $details['warnings'],
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    $lockedObjective->forceFill([
                        'latest_recommendation_run_id' => $run->id,
                        'status' => $this->statusAfterRecommendation($lockedObjective, count($assigned)),
                        'blocker_summary' => $analysis[$objective->id]['blockers'],
                    ])->save();
                }

                $this->upsertObjectiveRecommendations($recommendationRows);
            }
        }, attempts: 5);
    }

    /**
     * @param  array<int, ReadinessProfile>  $profiles
     * @param  list<int>  $allowedAllianceIds
     * @param  list<int>  $allowedNationIds
     * @param  array<string, bool>  $activePairs
     */
    private function assignmentPassesEligibility(
        MilcomOperation $operation,
        MilcomObjective $objective,
        MilcomAssignment $assignment,
        array $profiles,
        array $allowedAllianceIds,
        array $allowedNationIds,
        array $activePairs,
    ): bool {
        $friendlyId = (int) $assignment->friendly_nation_id;
        $friendly = $profiles[$friendlyId] ?? null;
        $target = $profiles[(int) $objective->target_nation_id] ?? null;

        if ($friendly === null || $target === null) {
            return false;
        }

        return $this->eligibility->evaluate(
            $friendly,
            $target,
            $allowedAllianceIds,
            $operation->type,
            isset($activePairs["{$friendlyId}:{$target->nationId}"]),
            allowedFriendlyNationIds: $allowedNationIds,
        )->eligible();
    }

    /**
     * @param  list<int>  $selectedIds
     * @param  list<array{nation_id: int, score: float, confidence: float, locked: bool}>  $assigned
     * @param  CandidatePool|list<CandidateEdge>  $candidateEdges
     * @param  array<int, ReadinessProfile>  $profiles
     * @param  list<int>  $allowedAllianceIds
     * @param  list<int>  $allowedNationIds
     * @param  array<string, bool>  $activePairs
     * @return array{team_score: ?float, confidence: ?float, alternatives: list<array{nation_ids: list<int>, score: float}>, members: array<int, array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    private function planRecommendationDetails(
        MilcomOperation $operation,
        MilcomObjective $objective,
        array $selectedIds,
        array $assigned,
        CandidatePool|array $candidateEdges,
        array $profiles,
        array $allowedAllianceIds,
        array $allowedNationIds,
        array $activePairs,
    ): array {
        $selectedLookup = array_fill_keys($selectedIds, true);
        $alternatives = [];

        foreach ($candidateEdges as $edge) {
            if (isset($selectedLookup[$edge->nationId])) {
                continue;
            }

            $alternatives[] = [
                'nation_ids' => [$edge->nationId],
                'score' => $edge->score,
            ];

            if (count($alternatives) === 3) {
                break;
            }
        }

        $target = $profiles[(int) $objective->target_nation_id] ?? null;
        $members = [];
        $warningsByNation = [];
        $scores = [];
        $confidences = [];

        if ($target !== null) {
            foreach ($selectedIds as $friendlyId) {
                $friendly = $profiles[$friendlyId] ?? null;

                if ($friendly === null) {
                    continue;
                }

                $eligibility = $this->eligibility->evaluate(
                    $friendly,
                    $target,
                    $allowedAllianceIds,
                    $operation->type,
                    isset($activePairs["{$friendlyId}:{$target->nationId}"]),
                    allowedFriendlyNationIds: $allowedNationIds,
                );
                $assessment = $this->scorer->assess($friendly, $target);
                $members[$friendlyId] = $assessment->jsonSerialize();
                $warningsByNation[$friendlyId] = [
                    ...$eligibility->warnings,
                    ...$assessment->warnings,
                ];
                $scores[] = $assessment->score;
                $confidences[] = $assessment->confidence;
            }
        }

        if ($scores === [] && $assigned !== []) {
            $scores = array_map(static fn (array $row): float => (float) $row['score'], $assigned);
            $confidences = array_map(static fn (array $row): float => (float) $row['confidence'], $assigned);
        }

        return [
            'team_score' => $scores !== [] ? round(array_sum($scores) / count($scores), 2) : null,
            'confidence' => $confidences !== [] ? round(array_sum($confidences) / count($confidences), 2) : null,
            'alternatives' => $alternatives,
            'members' => $members,
            'warnings' => $this->warningsForNations($selectedIds, $warningsByNation),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsertObjectiveRecommendations(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        MilcomObjectiveRecommendation::query()->upsert(
            $rows,
            ['recommendation_run_id', 'objective_id'],
            [
                'team_score',
                'confidence',
                'proposed_team',
                'alternatives',
                'blocker_summary',
                'factor_explanations',
                'updated_at',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(
        MilcomRecommendationRun $run,
        MilcomObjective $objective,
        PairAssessment $assessment,
        int $rank,
    ): array {
        return [
            'objective_id' => $objective->id,
            'friendly_nation_id' => $assessment->friendlyNationId,
            'score' => $assessment->score,
            'confidence' => $assessment->confidence,
            'rank' => $rank,
            'status' => AssignmentStatus::Proposed->value,
            'is_locked' => false,
            'recommendation_run_id' => $run->id,
            'factor_explanations' => json_encode($assessment->jsonSerialize(), JSON_THROW_ON_ERROR),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  list<int>  $nationIds
     * @param  array<int, list<array<string, mixed>>>  $warningsByNation
     * @return list<array<string, mixed>>
     */
    private function warningsForNations(array $nationIds, array $warningsByNation): array
    {
        return collect($nationIds)
            ->flatMap(fn (int $nationId): array => collect($warningsByNation[$nationId] ?? [])
                ->map(function (array $warning) use ($nationId): array {
                    $warning['context'] = [
                        ...($warning['context'] ?? []),
                        'nation_id' => $nationId,
                    ];

                    return $warning;
                })
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertAssignments(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::WRITE_CHUNK_SIZE) as $chunk) {
            MilcomAssignment::query()->upsert(
                $chunk,
                ['objective_id', 'friendly_nation_id'],
                [
                    'score',
                    'confidence',
                    'rank',
                    'status',
                    'is_locked',
                    'recommendation_run_id',
                    'factor_explanations',
                    'released_at',
                    'updated_at',
                ],
            );
        }
    }

    private function supersede(MilcomRecommendationRun $run): void
    {
        $run->forceFill([
            'status' => RecommendationRunStatus::Superseded,
            'finished_at' => now(),
        ])->save();
    }

    private function runIsWritableLocked(MilcomRecommendationRun $run): bool
    {
        $operation = MilcomOperation::query()
            ->lockForUpdate()
            ->findOrFail($run->operation_id);
        $freshRun = MilcomRecommendationRun::query()
            ->lockForUpdate()
            ->findOrFail($run->id);

        if ($freshRun->status === RecommendationRunStatus::Running
            && (int) $freshRun->generation_version === (int) $operation->generation_version
            && ! $operation->status->isTerminal()) {
            return true;
        }

        if (in_array($freshRun->status, [
            RecommendationRunStatus::Queued,
            RecommendationRunStatus::Running,
        ], true)) {
            $freshRun->forceFill([
                'status' => RecommendationRunStatus::Superseded,
                'finished_at' => now(),
            ])->save();
        }

        return false;
    }

    private function statusAfterRecommendation(MilcomObjective $objective, int $staffed): ObjectiveStatus
    {
        if (in_array($objective->status, [
            ObjectiveStatus::Approved,
            ObjectiveStatus::Dispatching,
            ObjectiveStatus::Dispatched,
            ObjectiveStatus::Engaged,
            ObjectiveStatus::Completed,
            ObjectiveStatus::Cancelled,
            ObjectiveStatus::Expired,
        ], true)) {
            return $objective->status;
        }

        return $staffed >= (int) $objective->minimum_team_depth
            ? ObjectiveStatus::Review
            : ObjectiveStatus::Blocked;
    }

    /** @param array<string, int|string> $context */
    private function logMemoryCheckpoint(
        MilcomRecommendationRun $run,
        string $stage,
        array $context = [],
    ): void {
        Log::info('Milcom recommendation memory checkpoint.', [
            'operation_id' => $run->operation_id,
            'recommendation_run_id' => $run->id,
            'stage' => $stage,
            'memory_mb' => round(memory_get_usage(true) / 1_048_576, 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            ...$context,
        ]);
    }
}
