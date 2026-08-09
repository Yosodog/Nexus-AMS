<?php

namespace App\Http\Controllers\API\Milcom;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Domain\Milcom\Exceptions\MilcomPreflightException;
use App\Domain\Milcom\Exceptions\StaleGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milcom\ApplyAlternativeRequest;
use App\Http\Requests\Milcom\ApproveObjectiveRequest;
use App\Http\Requests\Milcom\ReleaseAssignmentRequest;
use App\Http\Requests\Milcom\SetManualAssignmentRequest;
use App\Http\Requests\Milcom\UpdateObjectiveRequest;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Services\Milcom\ApprovalService;
use App\Services\Milcom\DiscordDispatchService;
use App\Services\Milcom\MilcomEventRecorder;
use App\Services\Milcom\OperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObjectiveController extends Controller
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly ApprovalService $approvals,
        private readonly DiscordDispatchService $discord,
        private readonly MilcomEventRecorder $events,
        private readonly FederationOperationGuard $federationGuard,
    ) {}

    public function update(
        UpdateObjectiveRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $generationVersion = (int) $validated['generation_version'];
            unset($validated['generation_version']);
            $objective = $this->operations->updateObjective(
                $objective,
                $generationVersion,
                (int) $request->user()->id,
                $validated,
            );

            return $this->success(
                ['objective' => $objective],
                'Target updated.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function approve(
        ApproveObjectiveRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        return $this->approveOrDispatch($request, $objective, false);
    }

    public function dispatchObjective(
        ApproveObjectiveRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        return $this->approveOrDispatch($request, $objective, true);
    }

    public function applyAlternative(
        ApplyAlternativeRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $result = DB::transaction(function () use ($request, $objective, $validated): MilcomObjective {
                $locked = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
                $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($locked->operation_id);

                if ((int) $operation->generation_version !== (int) $validated['generation_version']) {
                    throw new StaleGenerationException(
                        (int) $validated['generation_version'],
                        (int) $operation->generation_version,
                    );
                }

                if (! in_array($locked->status, [
                    ObjectiveStatus::Pending,
                    ObjectiveStatus::Review,
                    ObjectiveStatus::Blocked,
                ], true)) {
                    throw ValidationException::withMessages([
                        'objective' => 'You cannot switch teams after approval or after the room is sent to Discord.',
                    ]);
                }

                $hasPreservedAssignments = $locked->assignments()
                    ->where(function ($query): void {
                        $query->where('is_locked', true)
                            ->orWhere('status', '!=', AssignmentStatus::Proposed->value);
                    })
                    ->exists();

                if ($hasPreservedAssignments) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Remove the locked or reserved nations before choosing another team.',
                    ]);
                }

                $recommendation = $locked->recommendations()
                    ->where('recommendation_run_id', $locked->latest_recommendation_run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $alternative = ((array) $recommendation->alternatives)[(int) $validated['alternative_index']] ?? null;

                if (! is_array($alternative) || empty($alternative['nation_ids'])) {
                    throw ValidationException::withMessages([
                        'alternative_index' => 'That team is no longer available.',
                    ]);
                }

                $nationIds = array_values(array_unique(array_map('intval', $alternative['nation_ids'])));
                $this->federationGuard->assertMutable($operation, 'alternative_selection');
                $locked->assignments()
                    ->where('status', AssignmentStatus::Proposed->value)
                    ->delete();
                $rows = [];

                foreach ($nationIds as $rank => $nationId) {
                    $rows[] = [
                        'objective_id' => $locked->id,
                        'friendly_nation_id' => $nationId,
                        'score' => (float) ($alternative['score'] ?? $recommendation->team_score ?? 0),
                        'confidence' => (float) ($recommendation->confidence ?? 0),
                        'rank' => $rank + 1,
                        'status' => AssignmentStatus::Proposed->value,
                        'is_locked' => (bool) ($validated['lock'] ?? false),
                        'override_reason' => $validated['override_reason'] ?? null,
                        'recommendation_run_id' => $locked->latest_recommendation_run_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                MilcomAssignment::query()->upsert(
                    $rows,
                    ['objective_id', 'friendly_nation_id'],
                    [
                        'score',
                        'confidence',
                        'rank',
                        'status',
                        'is_locked',
                        'override_reason',
                        'recommendation_run_id',
                        'updated_at',
                    ],
                );
                $generation = (int) $operation->generation_version + 1;
                $latestRunIds = MilcomObjective::query()
                    ->where('operation_id', $operation->id)
                    ->whereNotNull('latest_recommendation_run_id')
                    ->pluck('latest_recommendation_run_id');
                MilcomRecommendationRun::query()
                    ->whereIn('id', $latestRunIds)
                    ->where('status', RecommendationRunStatus::Succeeded->value)
                    ->update([
                        'generation_version' => $generation,
                        'updated_at' => now(),
                    ]);
                MilcomObjective::query()
                    ->where('operation_id', $operation->id)
                    ->update([
                        'generation_version' => $generation,
                        'updated_at' => now(),
                    ]);
                $locked->forceFill([
                    'status' => ObjectiveStatus::Review,
                    'generation_version' => $generation,
                ])->save();
                $operation->forceFill(['generation_version' => $generation])->save();

                $this->events->record(
                    eventType: 'assignment.alternative_selected',
                    source: 'officer',
                    operationId: $operation->id,
                    objectiveId: $locked->id,
                    actorUserId: (int) $request->user()->id,
                    payload: [
                        'alternative_index' => (int) $validated['alternative_index'],
                        'nation_ids' => $nationIds,
                        'locked' => (bool) ($validated['lock'] ?? false),
                        'generation_version' => $generation,
                    ],
                );

                return $locked;
            }, attempts: 5);

            return $this->success(
                ['objective' => $result],
                'Alternative team selected.',
                (int) $result->operation()->value('generation_version')
            );
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function setManualAssignment(
        SetManualAssignmentRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $assignment = $this->approvals->setManualAssignment(
                $objective,
                (int) $validated['friendly_nation_id'],
                (int) $validated['generation_version'],
                (int) $request->user()->id,
                $validated['override_reason'],
                (bool) ($validated['lock'] ?? false),
            );

            return $this->success(
                ['assignment' => $assignment],
                'Nation added to the team.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (MilcomPreflightException $exception) {
            return $this->preflight($exception);
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function releaseAssignment(
        ReleaseAssignmentRequest $request,
        MilcomObjective $objective,
        MilcomAssignment $assignment,
    ): JsonResponse {
        abort_unless((int) $assignment->objective_id === (int) $objective->id, 404);

        try {
            $assignment = $this->approvals->releaseAssignment(
                $assignment,
                (int) $request->validated('generation_version'),
                (int) $request->user()->id,
                $request->validated('reason'),
            );

            return $this->success(
                ['assignment' => $assignment],
                'Nation removed from the team.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function cancel(
        ReleaseAssignmentRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        try {
            $objective = $this->operations->cancelObjective(
                $objective,
                (int) $request->validated('generation_version'),
                (int) $request->user()->id,
                $request->validated('reason'),
            );

            return $this->success(
                ['objective' => $objective],
                'Target cancelled.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    public function retry(
        ApproveObjectiveRequest $request,
        MilcomObjective $objective,
    ): JsonResponse {
        try {
            $dispatch = DB::transaction(function () use ($request, $objective) {
                $locked = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
                $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($locked->operation_id);
                $expected = (int) $request->validated('generation_version');

                if ((int) $operation->generation_version !== $expected) {
                    throw new StaleGenerationException($expected, (int) $operation->generation_version);
                }

                $this->federationGuard->assertMutable($operation, 'discord_retry');

                return $this->discord->retryLocked($locked, (int) $request->user()->id);
            }, attempts: 5);

            return $this->success(
                ['dispatch' => $dispatch],
                'Discord room retry started.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    private function approveOrDispatch(
        ApproveObjectiveRequest $request,
        MilcomObjective $objective,
        bool $dispatch,
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $objective->refresh();
            $result = $dispatch && $objective->status === ObjectiveStatus::Approved
                ? $this->approvals->dispatchApproved(
                    $objective,
                    (int) $validated['generation_version'],
                    (int) $request->user()->id,
                    $validated['override_reason'] ?? null,
                )
                : $this->approvals->approve(
                    $objective,
                    (int) $validated['generation_version'],
                    (int) $request->user()->id,
                    $validated['override_reason'] ?? null,
                    (bool) ($validated['force_partial'] ?? false),
                    $dispatch,
                );

            return $this->success(
                [
                    'objective' => $result['objective'],
                    'dispatch' => $result['dispatch'],
                    'warnings' => $result['warnings'],
                ],
                $dispatch ? 'Target approved. The Discord room is being created.' : 'Target approved.',
                (int) $objective->operation()->value('generation_version')
            );
        } catch (MilcomPreflightException $exception) {
            return $this->preflight($exception);
        } catch (StaleGenerationException $exception) {
            return $this->stale($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data, string $message, int $generationVersion): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['generation_version' => $generationVersion],
            'links' => [],
            'message' => $message,
        ]);
    }

    private function preflight(MilcomPreflightException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'blockers' => $exception->blockers,
            'warnings' => $exception->warnings,
            'meta' => [],
            'links' => [],
        ], 409);
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
