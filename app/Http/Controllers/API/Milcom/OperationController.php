<?php

namespace App\Http\Controllers\API\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Domain\Milcom\Exceptions\MilcomPreflightException;
use App\Domain\Milcom\Exceptions\StaleGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milcom\ApproveEligibleObjectivesRequest;
use App\Http\Requests\Milcom\ApproveReviewableObjectivesRequest;
use App\Http\Requests\Milcom\BatchObjectivesRequest;
use App\Http\Requests\Milcom\CommitScopeRequest;
use App\Http\Requests\Milcom\CreatePlanRequest;
use App\Http\Requests\Milcom\DeliverAssignmentsRequest;
use App\Http\Requests\Milcom\StartRecommendationRequest;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Services\Milcom\ApprovalService;
use App\Services\Milcom\AssignmentDeliveryService;
use App\Services\Milcom\OperationService;
use App\Services\Milcom\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OperationController extends Controller
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly RecommendationEngine $recommendations,
        private readonly ApprovalService $approvals,
        private readonly AssignmentDeliveryService $assignmentDeliveries,
    ) {}

    public function store(CreatePlanRequest $request): JsonResponse
    {
        $operation = $this->operations->createPlan(
            (int) $request->user()->id,
            $request->validated(),
        );

        return response()->json([
            'data' => ['operation' => $operation],
            'meta' => ['generation_version' => $operation->generation_version],
            'links' => ['self' => route('admin.milcom.plans.show', $operation)],
            'message' => 'Mass war plan created.',
        ], 201);
    }

    public function commitScope(
        CommitScopeRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $operation = $this->operations->commitScope(
                operation: $operation,
                generationVersion: (int) $validated['generation_version'],
                actorUserId: (int) $request->user()->id,
                friendlyAllianceIds: $validated['friendly_alliance_ids'],
                enemyAllianceIds: $validated['enemy_alliance_ids'],
                includedFriendlyNationIds: $validated['included_friendly_nation_ids'] ?? [],
                excludedFriendlyNationIds: $validated['excluded_friendly_nation_ids'] ?? [],
                includedTargetNationIds: $validated['included_target_nation_ids'] ?? [],
                excludedTargetNationIds: $validated['excluded_target_nation_ids'] ?? [],
                priorityOverrides: $validated['priority_overrides'] ?? [],
            );

            return response()->json([
                'data' => ['operation' => $operation],
                'meta' => ['generation_version' => $operation->generation_version],
                'links' => ['self' => route('admin.milcom.plans.show', $operation)],
                'message' => 'Alliances and targets saved. The teams are ready to build.',
            ]);
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function recommend(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        $expected = (int) $request->validated('generation_version');
        $operation->refresh();

        if ((int) $operation->generation_version !== $expected) {
            return $this->stale(new StaleGenerationException(
                $expected,
                (int) $operation->generation_version
            ));
        }

        $run = $this->recommendations->queue(
            $operation,
            null,
            'officer',
            (int) $request->user()->id,
        );

        return response()->json([
            'data' => ['recommendation_run' => ['id' => $run->id, 'status' => $run->status->value]],
            'meta' => ['generation_version' => $operation->generation_version],
            'links' => ['progress' => route('api.milcom.recommendation-runs.show', $run)],
            'message' => 'Team building queued.',
        ], 202);
    }

    public function batchApprove(
        BatchObjectivesRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        return $this->batch($request, $operation, false);
    }

    public function batchDispatch(
        BatchObjectivesRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        return $this->batch($request, $operation, true);
    }

    public function approveEligible(
        ApproveEligibleObjectivesRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ($operation->type !== OperationType::Plan) {
            throw ValidationException::withMessages([
                'operation' => 'Use Approve all eligible on mass war plans.',
            ]);
        }

        $generationVersion = (int) $request->validated('generation_version');
        $operation->refresh();

        if ((int) $operation->generation_version !== $generationVersion) {
            return $this->stale(new StaleGenerationException(
                $generationVersion,
                (int) $operation->generation_version,
            ));
        }

        $objectiveIds = MilcomObjective::query()
            ->where('operation_id', $operation->id)
            ->whereIn('status', [
                ObjectiveStatus::Pending->value,
                ObjectiveStatus::Review->value,
                ObjectiveStatus::Blocked->value,
            ])
            ->where('priority_tier', '!=', PriorityTier::Hold->value)
            ->whereHas('assignments', fn ($query) => $query->where('status', AssignmentStatus::Proposed->value))
            ->whereHas('latestRecommendationRun', fn ($query) => $query
                ->where('generation_version', $generationVersion)
                ->where('status', RecommendationRunStatus::Succeeded->value))
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->pluck('id')
            ->map('intval')
            ->all();

        return $this->processObjectives(
            operation: $operation,
            objectiveIds: $objectiveIds,
            generationVersion: $generationVersion,
            actorUserId: (int) $request->user()->id,
            overrideReason: null,
            dispatch: false,
            allEligible: true,
        );
    }

    public function approveReviewable(
        ApproveReviewableObjectivesRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ($operation->type !== OperationType::Plan) {
            throw ValidationException::withMessages([
                'operation' => 'Use this action on mass war plans.',
            ]);
        }

        $generationVersion = (int) $request->validated('generation_version');
        $operation->refresh();

        if ((int) $operation->generation_version !== $generationVersion) {
            return $this->stale(new StaleGenerationException(
                $generationVersion,
                (int) $operation->generation_version,
            ));
        }

        return $this->processObjectives(
            operation: $operation,
            objectiveIds: $this->reviewableObjectiveIds($operation, $generationVersion),
            generationVersion: $generationVersion,
            actorUserId: (int) $request->user()->id,
            overrideReason: $request->validated('override_reason'),
            dispatch: false,
            allEligible: true,
        );
    }

    public function dispatchReady(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        $operation->refresh();

        if ($operation->type !== OperationType::Plan || $operation->status !== OperationStatus::Active) {
            throw ValidationException::withMessages([
                'operation' => 'Finalize the wave before creating its Discord rooms.',
            ]);
        }

        $generationVersion = (int) $request->validated('generation_version');

        if ((int) $operation->generation_version !== $generationVersion) {
            return $this->stale(new StaleGenerationException(
                $generationVersion,
                (int) $operation->generation_version,
            ));
        }

        $objectiveIds = $operation->objectives()
            ->where('status', ObjectiveStatus::Approved->value)
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->pluck('id')
            ->map('intval')
            ->all();

        if ($objectiveIds === []) {
            return response()->json([
                'data' => [
                    'approved_objective_ids' => [],
                    'dispatched_objective_ids' => [],
                    'failed' => [],
                ],
                'meta' => [
                    'generation_version' => $operation->generation_version,
                    'attempted_count' => 0,
                    'approved_count' => 0,
                    'dispatched_count' => 0,
                    'skipped_count' => 0,
                    'remaining_count' => 0,
                ],
                'links' => [],
                'message' => 'There are no approved targets waiting for a Discord room.',
            ]);
        }

        return $this->processObjectives(
            operation: $operation,
            objectiveIds: $objectiveIds,
            generationVersion: $generationVersion,
            actorUserId: (int) $request->user()->id,
            overrideReason: null,
            dispatch: true,
        );
    }

    public function deliverAssignments(
        DeliverAssignmentsRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        try {
            $result = $this->assignmentDeliveries->queueInGame(
                operation: $operation,
                generationVersion: (int) $request->validated('generation_version'),
                actorUserId: (int) $request->user()->id,
            );

            return response()->json([
                'data' => ['deliveries' => $result],
                'meta' => ['generation_version' => $operation->fresh()->generation_version],
                'links' => [],
                'message' => $this->assignmentDeliveryMessage($result),
            ], 202);
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function complete(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ((int) $operation->generation_version !== (int) $request->validated('generation_version')) {
            return $this->stale(new StaleGenerationException(
                (int) $request->validated('generation_version'),
                (int) $operation->generation_version
            ));
        }

        $operation = $this->operations->complete($operation, (int) $request->user()->id);

        return response()->json([
            'data' => ['operation' => $operation],
            'meta' => ['generation_version' => $operation->generation_version],
            'links' => ['self' => route('admin.milcom.archive.show', $operation)],
            'message' => 'Operation ended. Its final history is ready.',
        ]);
    }

    public function activate(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ((int) $operation->generation_version !== (int) $request->validated('generation_version')) {
            return $this->stale(new StaleGenerationException(
                (int) $request->validated('generation_version'),
                (int) $operation->generation_version
            ));
        }

        $operation = $this->operations->activate($operation, (int) $request->user()->id);
        $autoHeldTargetCount = (int) data_get($operation->metadata, 'auto_held_target_count', 0);
        $message = $autoHeldTargetCount > 0
            ? $autoHeldTargetCount.' '.str('target')->plural($autoHeldTargetCount).' without '
                .($autoHeldTargetCount === 1 ? 'a team was' : 'teams were')
                .' moved to Hold. The live dashboard is ready.'
            : 'Wave finalized. The live dashboard is ready.';

        return response()->json([
            'data' => ['operation' => $operation],
            'meta' => ['generation_version' => $operation->generation_version],
            'links' => ['self' => route('admin.milcom.plans.show', $operation)],
            'message' => $message,
        ]);
    }

    public function archive(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ((int) $operation->generation_version !== (int) $request->validated('generation_version')) {
            return $this->stale(new StaleGenerationException(
                (int) $request->validated('generation_version'),
                (int) $operation->generation_version
            ));
        }

        $operation = $this->operations->archive($operation, (int) $request->user()->id);

        return response()->json([
            'data' => ['operation' => $operation],
            'meta' => ['generation_version' => $operation->generation_version],
            'links' => ['self' => route('admin.milcom.archive')],
            'message' => 'Plan archived.',
        ]);
    }

    public function clone(
        StartRecommendationRequest $request,
        MilcomOperation $operation,
    ): JsonResponse {
        if ((int) $operation->generation_version !== (int) $request->validated('generation_version')) {
            return $this->stale(new StaleGenerationException(
                (int) $request->validated('generation_version'),
                (int) $operation->generation_version
            ));
        }

        $copy = $this->operations->clone($operation, (int) $request->user()->id);

        return response()->json([
            'data' => ['operation' => $copy],
            'meta' => ['generation_version' => $copy->generation_version],
            'links' => ['self' => route('admin.milcom.plans.show', $copy)],
            'message' => 'New wave created.',
        ], 201);
    }

    private function batch(
        BatchObjectivesRequest $request,
        MilcomOperation $operation,
        bool $dispatch,
    ): JsonResponse {
        $validated = $request->validated();

        return $this->processObjectives(
            operation: $operation,
            objectiveIds: array_map('intval', $validated['objective_ids']),
            generationVersion: (int) $validated['generation_version'],
            actorUserId: (int) $request->user()->id,
            overrideReason: $validated['override_reason'] ?? null,
            dispatch: $dispatch,
        );
    }

    /**
     * @param  list<int>  $objectiveIds
     */
    private function processObjectives(
        MilcomOperation $operation,
        array $objectiveIds,
        int $generationVersion,
        int $actorUserId,
        ?string $overrideReason,
        bool $dispatch,
        bool $allEligible = false,
    ): JsonResponse {
        $approved = [];
        $dispatched = [];
        $failed = [];

        $objectives = MilcomObjective::query()
            ->where('operation_id', $operation->id)
            ->whereIn('id', $objectiveIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($objectiveIds as $objectiveId) {
            $objective = $objectives[(int) $objectiveId] ?? null;

            if ($objective === null) {
                $failed[] = [
                    'objective_id' => (int) $objectiveId,
                    'blockers' => [['code' => 'wrong_operation', 'message' => 'This target does not belong to this plan.']],
                ];

                continue;
            }

            try {
                $result = $dispatch
                    ? $this->approvals->dispatchApproved(
                        $objective,
                        $generationVersion,
                        $actorUserId,
                        $overrideReason,
                    )
                    : $this->approvals->approve(
                        $objective,
                        $generationVersion,
                        $actorUserId,
                        $overrideReason,
                    );

                if ($result['dispatch'] !== null) {
                    $dispatched[] = (int) $objective->id;
                } else {
                    $approved[] = (int) $objective->id;
                }
            } catch (MilcomPreflightException $exception) {
                $failed[] = [
                    'objective_id' => (int) $objective->id,
                    'blockers' => $exception->blockers,
                    'warnings' => $exception->warnings,
                ];
            } catch (StaleGenerationException $exception) {
                $failed[] = [
                    'objective_id' => (int) $objective->id,
                    'blockers' => [[
                        'code' => 'stale_generation',
                        'message' => $exception->getMessage(),
                        'current_generation' => $exception->currentGeneration,
                    ]],
                ];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = [
                    'objective_id' => (int) $objective->id,
                    'blockers' => [['code' => 'command_failed', 'message' => 'This target could not be processed.']],
                ];
            }
        }

        $remainingCount = $allEligible
            ? MilcomObjective::query()
                ->where('operation_id', $operation->id)
                ->whereIn('status', [
                    ObjectiveStatus::Pending->value,
                    ObjectiveStatus::Review->value,
                    ObjectiveStatus::Blocked->value,
                ])
                ->where('priority_tier', '!=', PriorityTier::Hold->value)
                ->count()
            : count($failed);
        $approvedCount = count($approved);
        $dispatchedCount = count($dispatched);
        $failedCount = count($failed);
        $skippedCount = $allEligible ? $remainingCount : $failedCount;
        $failedRows = $allEligible ? array_slice($failed, 0, 100) : $failed;

        return response()->json([
            'data' => [
                'approved_objective_ids' => $approved,
                'dispatched_objective_ids' => $dispatched,
                'failed' => $failedRows,
            ],
            'meta' => [
                'generation_version' => $operation->fresh()->generation_version,
                'attempted_count' => count($objectiveIds),
                'approved_count' => $approvedCount,
                'dispatched_count' => $dispatchedCount,
                'skipped_count' => $skippedCount,
                'remaining_count' => $remainingCount,
                'failure_details_truncated' => $allEligible && $skippedCount > count($failedRows),
            ],
            'links' => [],
            'message' => $this->batchMessage(
                allEligible: $allEligible,
                dispatch: $dispatch,
                approvedCount: $approvedCount,
                dispatchedCount: $dispatchedCount,
                failedCount: $failedCount,
                remainingCount: $remainingCount,
            ),
        ]);
    }

    private function batchMessage(
        bool $allEligible,
        bool $dispatch,
        int $approvedCount,
        int $dispatchedCount,
        int $failedCount,
        int $remainingCount,
    ): string {
        if ($allEligible) {
            if ($approvedCount === 0) {
                return $remainingCount === 0
                    ? 'There are no targets ready for approval.'
                    : $this->countLabel($remainingCount).' still need review.';
            }

            return $remainingCount === 0
                ? 'Approved '.$this->countLabel($approvedCount).'. They are ready to send to Discord.'
                : 'Approved '.$this->countLabel($approvedCount).'. '.$this->countLabel($remainingCount).' still need review.';
        }

        $processedCount = $dispatch ? $dispatchedCount : $approvedCount;
        $message = $dispatch
            ? ($processedCount === 1
                ? 'Queued a room for 1 target.'
                : 'Queued rooms for '.$this->countLabel($processedCount).'.')
            : 'Approved '.$this->countLabel($processedCount).'.';

        return $failedCount === 0
            ? $message
            : $message.' '.$this->countLabel($failedCount).' need review.';
    }

    private function countLabel(int $count): string
    {
        return $count.' '.Str::plural('target', $count);
    }

    /** @param array{queued: int, already_queued: int, already_sent: int} $result */
    private function assignmentDeliveryMessage(array $result): string
    {
        if ($result['queued'] > 0) {
            $message = 'Queued '.$result['queued'].' '.Str::plural('in-game assignment', $result['queued']).'.';

            if ($result['already_queued'] + $result['already_sent'] > 0) {
                $message .= ' Existing deliveries were left alone.';
            }

            return $message;
        }

        return $result['already_queued'] > 0
            ? 'Those in-game assignments are already queued.'
            : 'Those in-game assignments have already been sent.';
    }

    /** @return list<int> */
    private function reviewableObjectiveIds(MilcomOperation $operation, int $generationVersion): array
    {
        return MilcomObjective::query()
            ->where('operation_id', $operation->id)
            ->whereIn('status', [
                ObjectiveStatus::Pending->value,
                ObjectiveStatus::Review->value,
                ObjectiveStatus::Blocked->value,
            ])
            ->where('priority_tier', '!=', PriorityTier::Hold->value)
            ->whereHas('assignments', fn ($query) => $query->where('status', AssignmentStatus::Proposed->value))
            ->whereHas('latestRecommendationRun', fn ($query) => $query
                ->where('generation_version', $generationVersion)
                ->where('status', RecommendationRunStatus::Succeeded->value))
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    private function stale(StaleGenerationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'blockers' => [[
                'code' => 'stale_generation',
                'message' => $exception->getMessage(),
                'expected_generation' => $exception->expectedGeneration,
                'current_generation' => $exception->currentGeneration,
            ]],
            'meta' => ['generation_version' => $exception->currentGeneration],
            'links' => [],
        ], 409);
    }
}
