<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\EligibilityEvaluator;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Domain\Milcom\Exceptions\MilcomPreflightException;
use App\Domain\Milcom\Exceptions\StaleGenerationException;
use App\Domain\Milcom\FixedDoctrineScorer;
use App\Models\MilcomAssignment;
use App\Models\MilcomDispatch;
use App\Models\MilcomNationCapacityLock;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\War;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(
        private readonly ReadinessSnapshotService $snapshots,
        private readonly EligibilityEvaluator $eligibility,
        private readonly FixedDoctrineScorer $scorer,
        private readonly DiscordDispatchService $discord,
        private readonly MilcomEventRecorder $events,
    ) {}

    /**
     * @return array{objective: MilcomObjective, dispatch: MilcomDispatch|null, warnings: list<array<string, mixed>>}
     */
    public function approve(
        MilcomObjective $objective,
        int $generationVersion,
        int $actorUserId,
        ?string $overrideReason = null,
        bool $forcePartialCounter = false,
        bool $dispatch = false,
    ): array {
        return $this->perform(
            $objective,
            $generationVersion,
            $actorUserId,
            $overrideReason,
            $forcePartialCounter,
            $dispatch,
            false,
        );
    }

    /**
     * @return array{objective: MilcomObjective, dispatch: MilcomDispatch|null, warnings: list<array<string, mixed>>}
     */
    public function dispatchApproved(
        MilcomObjective $objective,
        int $generationVersion,
        int $actorUserId,
        ?string $overrideReason = null,
    ): array {
        return $this->perform(
            $objective,
            $generationVersion,
            $actorUserId,
            $overrideReason,
            false,
            true,
            true,
        );
    }

    public function setManualAssignment(
        MilcomObjective $objective,
        int $friendlyNationId,
        int $generationVersion,
        int $actorUserId,
        string $overrideReason,
        bool $lock,
    ): MilcomAssignment {
        return DB::transaction(function () use (
            $objective,
            $friendlyNationId,
            $generationVersion,
            $actorUserId,
            $overrideReason,
            $lock,
        ): MilcomAssignment {
            $lockedObjective = MilcomObjective::query()
                ->with(['operation.alliances', 'operation.nations'])
                ->lockForUpdate()
                ->findOrFail($objective->id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($lockedObjective->operation_id);

            if ((int) $operation->generation_version !== $generationVersion) {
                throw new StaleGenerationException($generationVersion, (int) $operation->generation_version);
            }

            if (! in_array($lockedObjective->status, [
                ObjectiveStatus::Pending,
                ObjectiveStatus::Review,
                ObjectiveStatus::Blocked,
            ], true)) {
                throw ValidationException::withMessages([
                    'objective' => 'You cannot change the team after it is approved.',
                ]);
            }

            $existing = MilcomAssignment::query()
                ->where('objective_id', $lockedObjective->id)
                ->where('friendly_nation_id', $friendlyNationId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && in_array($existing->status, [
                AssignmentStatus::Approved,
                AssignmentStatus::Dispatched,
                AssignmentStatus::Engaged,
                AssignmentStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'friendly_nation_id' => 'You cannot replace a nation whose slot is reserved or whose war is complete.',
                ]);
            }

            $run = MilcomRecommendationRun::query()
                ->whereKey($lockedObjective->latest_recommendation_run_id)
                ->where('status', RecommendationRunStatus::Succeeded->value)
                ->first();

            if ($run === null) {
                throw new MilcomPreflightException([[
                    'code' => 'recommendation_not_current',
                    'message' => 'Build the teams before adding a nation by hand.',
                ]]);
            }

            $profiles = $this->snapshots->profilesForRun($run, [
                $friendlyNationId,
                (int) $lockedObjective->target_nation_id,
            ]);

            if (! isset($profiles[$friendlyNationId], $profiles[(int) $lockedObjective->target_nation_id])) {
                throw new MilcomPreflightException([[
                    'code' => 'snapshot_missing',
                    'message' => 'This matchup is missing from the current data.',
                ]]);
            }

            $activeWars = War::query()->active()->where('att_id', $friendlyNationId)->count();
            $reservations = MilcomAssignment::query()
                ->where('friendly_nation_id', $friendlyNationId)
                ->where('objective_id', '!=', $lockedObjective->id)
                ->whereIn('status', [
                    AssignmentStatus::Approved->value,
                    AssignmentStatus::Dispatched->value,
                    AssignmentStatus::Engaged->value,
                ])
                ->count();
            $profiles = $this->snapshots->currentProfiles(
                $profiles,
                [$friendlyNationId => $activeWars],
                [$friendlyNationId => $reservations],
            );
            $currentProfile = $profiles[$friendlyNationId];
            $target = $profiles[(int) $lockedObjective->target_nation_id];
            $allowedAllianceIds = $lockedObjective->operation->alliances
                ->where('role', 'friendly')
                ->where('included', true)
                ->pluck('alliance_id')
                ->map('intval')
                ->all();
            $allowedNationIds = $lockedObjective->operation->nations
                ->where('role', 'friendly')
                ->where('included', true)
                ->pluck('nation_id')
                ->map('intval')
                ->all();

            $eligibility = $this->eligibility->evaluate(
                $currentProfile,
                $target,
                array_values(array_unique($allowedAllianceIds)),
                $operation->type,
                $this->hasActiveWarPair($friendlyNationId, $target->nationId),
                allowedFriendlyNationIds: $allowedNationIds,
            );

            if (! $eligibility->eligible()) {
                throw new MilcomPreflightException($eligibility->blockers, $eligibility->warnings);
            }

            $pair = $this->scorer->assess($currentProfile, $target);
            $assignment = MilcomAssignment::query()->updateOrCreate(
                [
                    'objective_id' => $lockedObjective->id,
                    'friendly_nation_id' => $friendlyNationId,
                ],
                [
                    'score' => $pair->score,
                    'confidence' => $pair->confidence,
                    'rank' => 999,
                    'status' => AssignmentStatus::Proposed,
                    'is_locked' => $lock,
                    'override_reason' => $overrideReason,
                    'recommendation_run_id' => $run->id,
                    'factor_explanations' => $pair->jsonSerialize(),
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
            $operation->forceFill(['generation_version' => $generation])->save();

            $this->events->record(
                eventType: 'assignment.manually_set',
                source: 'officer',
                operationId: $operation->id,
                objectiveId: $lockedObjective->id,
                assignmentId: $assignment->id,
                actorUserId: $actorUserId,
                payload: [
                    'nation_id' => $friendlyNationId,
                    'locked' => $lock,
                    'override_reason' => $overrideReason,
                    'warning_codes' => array_column($eligibility->warnings, 'code'),
                    'generation_version' => $generation,
                ],
            );

            return $assignment;
        }, attempts: 5);
    }

    public function releaseAssignment(
        MilcomAssignment $assignment,
        int $generationVersion,
        int $actorUserId,
        string $reason,
    ): MilcomAssignment {
        return DB::transaction(function () use (
            $assignment,
            $generationVersion,
            $actorUserId,
            $reason,
        ): MilcomAssignment {
            $locked = MilcomAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $objective = MilcomObjective::query()->lockForUpdate()->findOrFail($locked->objective_id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($objective->operation_id);

            if ((int) $operation->generation_version !== $generationVersion) {
                throw new StaleGenerationException($generationVersion, (int) $operation->generation_version);
            }

            if (in_array($locked->status, [
                AssignmentStatus::Dispatched,
                AssignmentStatus::Engaged,
                AssignmentStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'assignment' => 'You cannot remove this nation after its Discord room is created.',
                ]);
            }

            MilcomNationCapacityLock::query()->insertOrIgnore([
                'friendly_nation_id' => $locked->friendly_nation_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            MilcomNationCapacityLock::query()
                ->where('friendly_nation_id', $locked->friendly_nation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'status' => AssignmentStatus::Released,
                'override_reason' => $reason,
                'released_at' => now(),
            ])->save();

            $generation = (int) $operation->generation_version + 1;
            $remaining = $objective->assignments()
                ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
                ->count();
            $objective->forceFill([
                'status' => $remaining >= (int) $objective->minimum_team_depth
                    ? ObjectiveStatus::Review
                    : ObjectiveStatus::Blocked,
                'generation_version' => $generation,
            ])->save();
            MilcomObjective::query()
                ->where('operation_id', $operation->id)
                ->where('id', '!=', $objective->id)
                ->update([
                    'generation_version' => $generation,
                    'updated_at' => now(),
                ]);
            $operation->forceFill([
                'generation_version' => $generation,
                'status' => $operation->dispatched_at !== null
                    ? OperationStatus::Active
                    : OperationStatus::Review,
                'current_stage' => $operation->dispatched_at !== null ? 'live' : 'staffing',
            ])->save();

            $this->events->record(
                eventType: 'assignment.released',
                source: 'officer',
                operationId: $operation->id,
                objectiveId: $objective->id,
                assignmentId: $locked->id,
                actorUserId: $actorUserId,
                payload: ['reason' => $reason, 'generation_version' => $generation],
            );

            return $locked;
        }, attempts: 5);
    }

    /**
     * @return array{objective: MilcomObjective, dispatch: MilcomDispatch|null, warnings: list<array<string, mixed>>}
     */
    private function perform(
        MilcomObjective $objective,
        int $generationVersion,
        int $actorUserId,
        ?string $overrideReason,
        bool $forcePartialCounter,
        bool $dispatch,
        bool $requireApproved,
    ): array {
        return DB::transaction(function () use (
            $objective,
            $generationVersion,
            $actorUserId,
            $overrideReason,
            $forcePartialCounter,
            $dispatch,
            $requireApproved,
        ): array {
            $lockedObjective = MilcomObjective::query()
                ->with(['operation.alliances', 'operation.nations'])
                ->lockForUpdate()
                ->findOrFail($objective->id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($lockedObjective->operation_id);

            if ((int) $operation->generation_version !== $generationVersion) {
                throw new StaleGenerationException($generationVersion, (int) $operation->generation_version);
            }

            if ($lockedObjective->status === ObjectiveStatus::Dispatched && $dispatch) {
                return [
                    'objective' => $lockedObjective,
                    'dispatch' => $lockedObjective->dispatches()->latest('id')->first(),
                    'warnings' => [],
                ];
            }

            if ($dispatch
                && $operation->type === OperationType::Plan
                && $operation->status !== OperationStatus::Active) {
                throw new MilcomPreflightException([[
                    'code' => 'wave_not_finalized',
                    'message' => 'Finalize this wave before creating Discord rooms.',
                ]]);
            }

            if (! $dispatch && in_array($lockedObjective->status, [
                ObjectiveStatus::Approved,
                ObjectiveStatus::Dispatching,
                ObjectiveStatus::Dispatched,
                ObjectiveStatus::Engaged,
            ], true)) {
                return [
                    'objective' => $lockedObjective,
                    'dispatch' => null,
                    'warnings' => [],
                ];
            }

            $assignmentStatuses = $requireApproved
                ? [AssignmentStatus::Approved->value]
                : [AssignmentStatus::Proposed->value, AssignmentStatus::Approved->value];
            $assignments = MilcomAssignment::query()
                ->where('objective_id', $lockedObjective->id)
                ->whereIn('status', $assignmentStatuses)
                ->orderBy('friendly_nation_id')
                ->lockForUpdate()
                ->get();
            $effectiveOverrideReason = trim((string) $overrideReason);

            if ($effectiveOverrideReason === '' && $requireApproved) {
                $effectiveOverrideReason = trim((string) $assignments
                    ->first(fn (MilcomAssignment $assignment): bool => trim((string) $assignment->override_reason) !== '')
                    ?->override_reason);
            }

            if ($requireApproved && $assignments->contains(
                fn (MilcomAssignment $assignment): bool => $assignment->status !== AssignmentStatus::Approved
            )) {
                throw ValidationException::withMessages(['objective' => 'Approve the team before creating its Discord room.']);
            }

            if ($assignments->isEmpty()) {
                throw new MilcomPreflightException([[
                    'code' => 'no_assignments',
                    'message' => 'There is no team to approve.',
                ]]);
            }

            $isPartialCounter = $operation->type === OperationType::Counter
                && $assignments->count() < (int) $lockedObjective->minimum_team_depth;

            if ($assignments->count() < (int) $lockedObjective->minimum_team_depth
                && ! ($isPartialCounter && $forcePartialCounter)) {
                throw new MilcomPreflightException([[
                    'code' => 'minimum_team_depth',
                    'message' => 'This team does not meet the minimum size.',
                    'context' => [
                        'assigned' => $assignments->count(),
                        'minimum' => (int) $lockedObjective->minimum_team_depth,
                    ],
                ]]);
            }

            if ($isPartialCounter && $effectiveOverrideReason === '') {
                throw new MilcomPreflightException([[
                    'code' => 'partial_counter_requires_reason',
                    'message' => 'Add a reason to approve a partial counter.',
                ]]);
            }

            $run = MilcomRecommendationRun::query()
                ->whereKey($lockedObjective->latest_recommendation_run_id)
                ->where('generation_version', $generationVersion)
                ->where('status', RecommendationRunStatus::Succeeded->value)
                ->first();

            if ($run === null) {
                throw new MilcomPreflightException([[
                    'code' => 'recommendation_not_current',
                    'message' => 'Build the team again before approving this target.',
                ]]);
            }

            $nationIds = $assignments->pluck('friendly_nation_id')->map('intval')->all();
            MilcomNationCapacityLock::query()->insertOrIgnore(array_map(
                static fn (int $nationId): array => [
                    'friendly_nation_id' => $nationId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $nationIds
            ));

            $capacityLocks = MilcomNationCapacityLock::query()
                ->whereIn('friendly_nation_id', $nationIds)
                ->orderBy('friendly_nation_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('friendly_nation_id');

            if ($capacityLocks->count() !== count($nationIds)) {
                throw new MilcomPreflightException([[
                    'code' => 'capacity_lock_missing',
                    'message' => "Could not reserve this nation's offensive slots.",
                ]]);
            }

            $profiles = $this->snapshots->profilesForRun($run, [
                ...$nationIds,
                (int) $lockedObjective->target_nation_id,
            ]);
            $activeWarCounts = War::query()
                ->active()
                ->whereIn('att_id', $nationIds)
                ->selectRaw('att_id, COUNT(*) as aggregate')
                ->groupBy('att_id')
                ->pluck('aggregate', 'att_id');
            $targetNationId = (int) $lockedObjective->target_nation_id;
            $activePairs = War::query()
                ->active()
                ->betweenNationSets($nationIds, [$targetNationId])
                ->get(['att_id', 'def_id'])
                ->mapWithKeys(static fn (War $war): array => [
                    (int) $war->att_id === $targetNationId
                        ? (int) $war->def_id
                        : (int) $war->att_id => true,
                ])
                ->all();
            $reservationAssignments = MilcomAssignment::query()
                ->whereIn('friendly_nation_id', $nationIds)
                ->where('objective_id', '!=', $lockedObjective->id)
                ->whereIn('status', [
                    AssignmentStatus::Approved->value,
                    AssignmentStatus::Dispatched->value,
                    AssignmentStatus::Engaged->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'friendly_nation_id']);
            $reservations = $reservationAssignments->countBy(
                static fn (MilcomAssignment $assignment): int => (int) $assignment->friendly_nation_id
            );
            $profiles = $this->snapshots->currentProfiles(
                $profiles,
                $activeWarCounts->map(fn ($count): int => (int) $count)->all(),
                $reservations->map(fn ($count): int => (int) $count)->all(),
            );
            $target = $profiles[(int) $lockedObjective->target_nation_id] ?? null;

            if ($target === null) {
                throw new MilcomPreflightException([[
                    'code' => 'target_snapshot_missing',
                    'message' => 'Military or activity data is missing for the target.',
                ]]);
            }

            $conflicts = MilcomAssignment::query()
                ->whereIn('friendly_nation_id', $nationIds)
                ->where('objective_id', '!=', $lockedObjective->id)
                ->whereHas('objective', fn ($query) => $query
                    ->where('target_nation_id', $lockedObjective->target_nation_id)
                    ->open())
                ->whereIn('status', [
                    AssignmentStatus::Approved->value,
                    AssignmentStatus::Dispatched->value,
                    AssignmentStatus::Engaged->value,
                ])
                ->orderBy('milcom_assignments.id')
                ->lockForUpdate()
                ->pluck('friendly_nation_id')
                ->mapWithKeys(static fn (int $id): array => [$id => true])
                ->all();

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
            $blockers = [];
            $warnings = [];

            foreach ($assignments as $assignment) {
                $profile = $profiles[(int) $assignment->friendly_nation_id] ?? null;

                if ($profile === null) {
                    $blockers[] = [
                        'code' => 'friendly_snapshot_missing',
                        'message' => 'Military or activity data is missing for one of the assigned nations.',
                        'context' => ['nation_id' => (int) $assignment->friendly_nation_id],
                    ];

                    continue;
                }

                $result = $this->eligibility->evaluate(
                    $profile,
                    $target,
                    $allowedAllianceIds,
                    $operation->type,
                    isset($activePairs[$profile->nationId]),
                    isset($conflicts[$profile->nationId]),
                    allowedFriendlyNationIds: $allowedNationIds,
                );
                $pair = $this->scorer->assess($profile, $target);

                foreach ($result->blockers as $blocker) {
                    $blocker['context'] = [
                        ...($blocker['context'] ?? []),
                        'nation_id' => $profile->nationId,
                    ];
                    $blockers[] = $blocker;
                }

                foreach ([...$result->warnings, ...$pair->warnings] as $warning) {
                    $warning['context'] = [
                        ...($warning['context'] ?? []),
                        'nation_id' => $profile->nationId,
                    ];
                    $warnings[] = $warning;
                }

                $capacityLocks[$profile->nationId]->forceFill([
                    'version' => (int) $capacityLocks[$profile->nationId]->version + 1,
                    'last_known_capacity' => $result->slotMath['base'] + $result->slotMath['project_modifiers'],
                    'last_known_active_wars' => $result->slotMath['active_offensive_wars'],
                    'last_known_reservations' => $result->slotMath['reservations'],
                    'reconciled_at' => now(),
                ])->save();
            }

            if ($blockers !== []) {
                throw new MilcomPreflightException($blockers, $warnings);
            }

            if ($warnings !== [] && $effectiveOverrideReason === '') {
                throw new MilcomPreflightException([[
                    'code' => 'warning_override_required',
                    'message' => 'This target has warnings. Add a short reason if you want to approve it anyway.',
                ]], $warnings);
            }

            if (! $requireApproved) {
                MilcomAssignment::query()
                    ->whereIn('id', $assignments->pluck('id'))
                    ->update([
                        'status' => AssignmentStatus::Approved->value,
                        'override_reason' => $effectiveOverrideReason !== '' ? $effectiveOverrideReason : null,
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);

                $lockedObjective->forceFill([
                    'status' => ObjectiveStatus::Approved,
                    'approved_at' => now(),
                ])->save();

                $operation->forceFill([
                    'status' => OperationStatus::Review,
                    'current_stage' => 'dispatch',
                    'approved_at' => $operation->approved_at ?? now(),
                ])->save();

                $this->events->record(
                    eventType: 'objective.approved',
                    source: 'officer',
                    operationId: $operation->id,
                    objectiveId: $lockedObjective->id,
                    actorUserId: $actorUserId,
                    payload: [
                        'nation_ids' => $nationIds,
                        'override_reason' => $effectiveOverrideReason !== '' ? $effectiveOverrideReason : null,
                        'warning_codes' => array_values(array_unique(array_column($warnings, 'code'))),
                        'partial_counter' => $isPartialCounter,
                    ],
                );
            }

            $dispatchReceipt = $dispatch
                ? $this->discord->queueLocked($lockedObjective->fresh(['operation']), $actorUserId)
                : null;

            return [
                'objective' => $lockedObjective->fresh(),
                'dispatch' => $dispatchReceipt,
                'warnings' => $warnings,
            ];
        }, attempts: 5);
    }

    private function hasActiveWarPair(int $friendlyNationId, int $targetNationId): bool
    {
        return War::query()
            ->active()
            ->betweenNationSets([$friendlyNationId], [$targetNationId])
            ->exists();
    }
}
