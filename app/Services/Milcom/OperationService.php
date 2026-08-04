<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Exceptions\StaleGenerationException;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomOperationAlliance;
use App\Models\MilcomOperationNation;
use App\Models\Nation;
use App\Models\War;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationService
{
    public function __construct(
        private readonly MilcomEventRecorder $events,
        private readonly DiscordDispatchService $discord,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPlan(int $creatorUserId, array $attributes): MilcomOperation
    {
        return DB::transaction(function () use ($creatorUserId, $attributes): MilcomOperation {
            $operation = MilcomOperation::query()->create([
                'type' => OperationType::Plan,
                'status' => OperationStatus::Draft,
                'current_stage' => 'scope',
                'name' => $attributes['name'],
                'doctrine_version' => 'fixed-v1',
                'default_war_type' => $attributes['default_war_type']
                    ?? (SettingService::getValue('milcom_default_war_type')
                        ?: config('milcom.discord.default_war_type', 'ORDINARY')),
                'default_war_reason' => $attributes['default_war_reason']
                    ?? (SettingService::getValue('milcom_default_war_reason')
                        ?: config('milcom.discord.default_war_reason', 'Alliance operations')),
                'discord_forum_id' => $attributes['discord_forum_id']
                    ?? (SettingService::getDiscordWarRoomForumId()
                        ?: config('milcom.discord.forum_id')),
                'deadline_at' => $attributes['deadline_at'] ?? null,
                'generation_version' => 1,
                'dispatch_version' => 0,
                'created_by' => $creatorUserId,
                'metadata' => [
                    'wave' => $attributes['wave'] ?? 1,
                    'base_name' => $attributes['name'],
                ],
            ]);
            $operation->forceFill([
                'metadata' => [
                    ...($operation->metadata ?? []),
                    'series_root_id' => $operation->id,
                ],
            ])->save();

            $this->events->record(
                eventType: 'operation.created',
                source: 'officer',
                operationId: $operation->id,
                actorUserId: $creatorUserId,
                payload: ['type' => OperationType::Plan->value],
            );

            return $operation;
        }, attempts: 5);
    }

    /**
     * @param  list<int>  $friendlyAllianceIds
     * @param  list<int>  $enemyAllianceIds
     * @param  list<int>  $includedFriendlyNationIds
     * @param  list<int>  $excludedFriendlyNationIds
     * @param  list<int>  $includedTargetNationIds
     * @param  list<int>  $excludedTargetNationIds
     * @param  array<int, string>  $priorityOverrides
     */
    public function commitScope(
        MilcomOperation $operation,
        int $generationVersion,
        int $actorUserId,
        array $friendlyAllianceIds,
        array $enemyAllianceIds,
        array $includedFriendlyNationIds = [],
        array $excludedFriendlyNationIds = [],
        array $includedTargetNationIds = [],
        array $excludedTargetNationIds = [],
        array $priorityOverrides = [],
    ): MilcomOperation {
        return DB::transaction(function () use (
            $operation,
            $generationVersion,
            $actorUserId,
            $friendlyAllianceIds,
            $enemyAllianceIds,
            $includedFriendlyNationIds,
            $excludedFriendlyNationIds,
            $includedTargetNationIds,
            $excludedTargetNationIds,
            $priorityOverrides,
        ): MilcomOperation {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->assertGeneration($locked, $generationVersion);

            if ($locked->type !== OperationType::Plan) {
                throw ValidationException::withMessages(['operation' => 'Only mass war plans let you change alliances and nations.']);
            }

            if ($locked->status === OperationStatus::Active
                || filled(data_get($locked->metadata, 'finalized_at'))
                || $locked->dispatches()->exists()) {
                throw ValidationException::withMessages([
                    'scope' => 'This wave is finalized. Create a new wave to change alliances or targets.',
                ]);
            }

            $friendlyAllianceIds = $this->normalizeIds($friendlyAllianceIds);
            $enemyAllianceIds = $this->normalizeIds($enemyAllianceIds);
            $includedFriendlyNationIds = $this->normalizeIds($includedFriendlyNationIds);
            $excludedFriendlyNationIds = $this->normalizeIds($excludedFriendlyNationIds);
            $includedTargetNationIds = $this->normalizeIds($includedTargetNationIds);
            $excludedTargetNationIds = $this->normalizeIds($excludedTargetNationIds);

            if ($friendlyAllianceIds === [] && $includedFriendlyNationIds === []) {
                throw ValidationException::withMessages(['friendly_scope' => 'Add at least one friendly alliance or nation.']);
            }

            if ($enemyAllianceIds === [] && $includedTargetNationIds === []) {
                throw ValidationException::withMessages(['target_scope' => 'Add at least one enemy alliance or target.']);
            }

            MilcomOperationAlliance::query()->where('operation_id', $locked->id)->delete();
            MilcomOperationNation::query()->where('operation_id', $locked->id)->delete();

            $allianceRows = [
                ...array_map(fn (int $id): array => [
                    'operation_id' => $locked->id,
                    'alliance_id' => $id,
                    'role' => 'friendly',
                    'included' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $friendlyAllianceIds),
                ...array_map(fn (int $id): array => [
                    'operation_id' => $locked->id,
                    'alliance_id' => $id,
                    'role' => 'enemy',
                    'included' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $enemyAllianceIds),
            ];

            if ($allianceRows !== []) {
                MilcomOperationAlliance::query()->insert($allianceRows);
            }

            $nationRows = [
                ...$this->scopeNationRows($locked->id, 'friendly', $includedFriendlyNationIds, true),
                ...$this->scopeNationRows($locked->id, 'friendly', $excludedFriendlyNationIds, false),
                ...$this->scopeNationRows($locked->id, 'target', $includedTargetNationIds, true),
                ...$this->scopeNationRows($locked->id, 'target', $excludedTargetNationIds, false),
            ];

            if ($nationRows !== []) {
                MilcomOperationNation::query()->insert($nationRows);
            }

            $targets = Nation::query()
                ->where(function ($query) use ($enemyAllianceIds, $includedTargetNationIds): void {
                    if ($enemyAllianceIds !== []) {
                        $query->whereIn('alliance_id', $enemyAllianceIds);
                    }

                    if ($includedTargetNationIds !== []) {
                        $method = $enemyAllianceIds !== [] ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('id', $includedTargetNationIds);
                    }
                })
                ->whereNotIn('id', $excludedTargetNationIds)
                ->orderBy('id')
                ->get(['id', 'vacation_mode_turns', 'beige_turns']);
            $targetIds = $targets
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($targetIds === []) {
                throw ValidationException::withMessages(['target_scope' => 'No targets were found in the alliances and nations you entered.']);
            }

            $threats = $this->threatScores($targetIds);
            $criticalTargets = $this->activeAggressorsAgainstFriendlyScope(
                $targetIds,
                $friendlyAllianceIds,
                $includedFriendlyNationIds
            );
            $undeclarableTargets = $targets
                ->filter(fn (Nation $nation): bool => (int) $nation->vacation_mode_turns > 0
                    || (int) $nation->beige_turns > 0)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $tiers = $this->assignTiers(
                $targetIds,
                $threats,
                $criticalTargets,
                $priorityOverrides,
                $undeclarableTargets,
            );

            MilcomObjective::query()->where('operation_id', $locked->id)->delete();
            $generation = (int) $locked->generation_version + 1;
            $objectiveRows = [];

            foreach ($targetIds as $targetId) {
                $tier = $tiers[$targetId];
                $depth = $tier->defaultDepth();
                $objectiveRows[] = [
                    'operation_id' => $locked->id,
                    'target_nation_id' => $targetId,
                    'priority_tier' => $tier->value,
                    'priority_score' => $threats[$targetId] ?? 0,
                    'desired_team_depth' => $depth['desired'],
                    'minimum_team_depth' => $depth['minimum'],
                    'war_type' => $locked->default_war_type,
                    'war_reason' => $locked->default_war_reason,
                    'deadline_at' => $locked->deadline_at,
                    'status' => $tier === PriorityTier::Hold
                        ? ObjectiveStatus::Blocked->value
                        : ObjectiveStatus::Pending->value,
                    'generation_version' => $generation,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            MilcomObjective::query()->insert($objectiveRows);

            $locked->forceFill([
                'generation_version' => $generation,
                'status' => OperationStatus::Draft,
                'current_stage' => 'objectives',
            ])->save();

            $this->events->record(
                eventType: 'operation.scope_committed',
                source: 'officer',
                operationId: $locked->id,
                actorUserId: $actorUserId,
                payload: [
                    'friendly_alliances' => count($friendlyAllianceIds),
                    'enemy_alliances' => count($enemyAllianceIds),
                    'targets' => count($targetIds),
                    'generation_version' => $generation,
                ],
            );

            return $locked->fresh(['alliances', 'nations']);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateObjective(
        MilcomObjective $objective,
        int $generationVersion,
        int $actorUserId,
        array $changes,
    ): MilcomObjective {
        return DB::transaction(function () use ($objective, $generationVersion, $actorUserId, $changes): MilcomObjective {
            $lockedObjective = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($lockedObjective->operation_id);
            $this->assertGeneration($operation, $generationVersion);

            if (in_array($lockedObjective->status, [
                ObjectiveStatus::Approved,
                ObjectiveStatus::Dispatching,
                ObjectiveStatus::Dispatched,
                ObjectiveStatus::Engaged,
                ObjectiveStatus::Completed,
                ObjectiveStatus::Cancelled,
                ObjectiveStatus::Expired,
            ], true)) {
                throw ValidationException::withMessages(['objective' => 'You cannot change this team after it is approved or sent to Discord.']);
            }

            if (isset($changes['priority_tier'])) {
                $tier = PriorityTier::from($changes['priority_tier']);
                $changes['priority_tier'] = $tier;
                $defaults = $tier->defaultDepth();
                $changes['desired_team_depth'] ??= $defaults['desired'];
                $changes['minimum_team_depth'] ??= $defaults['minimum'];
            }

            if ((int) ($changes['minimum_team_depth'] ?? $lockedObjective->minimum_team_depth)
                > (int) ($changes['desired_team_depth'] ?? $lockedObjective->desired_team_depth)) {
                throw ValidationException::withMessages(['minimum_team_depth' => 'The minimum team size cannot be larger than the desired size.']);
            }

            $generation = (int) $operation->generation_version + 1;
            $lockedObjective->fill($changes);
            $lockedObjective->generation_version = $generation;
            $lockedObjective->status = ObjectiveStatus::Pending;
            $lockedObjective->save();

            MilcomObjective::query()
                ->where('operation_id', $operation->id)
                ->where('id', '!=', $lockedObjective->id)
                ->update([
                    'generation_version' => $generation,
                    'updated_at' => now(),
                ]);

            $operation->forceFill([
                'generation_version' => $generation,
                'status' => $operation->dispatched_at !== null
                    ? OperationStatus::Active
                    : OperationStatus::Draft,
                'current_stage' => $operation->dispatched_at !== null ? 'live' : 'objectives',
            ])->save();

            $this->events->record(
                eventType: 'objective.updated',
                source: 'officer',
                operationId: $operation->id,
                objectiveId: $lockedObjective->id,
                actorUserId: $actorUserId,
                payload: ['changes' => array_keys($changes), 'generation_version' => $generation],
            );

            return $lockedObjective;
        }, attempts: 5);
    }

    public function archive(MilcomOperation $operation, int $actorUserId): MilcomOperation
    {
        return DB::transaction(function () use ($operation, $actorUserId): MilcomOperation {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if ($locked->status === OperationStatus::Archived) {
                return $locked;
            }

            if ($locked->status !== OperationStatus::Completed) {
                throw ValidationException::withMessages([
                    'operation' => 'Complete the plan before archiving it.',
                ]);
            }

            $locked->forceFill([
                'status' => OperationStatus::Archived,
                'archived_at' => now(),
                'current_stage' => 'archived',
            ])->save();

            $locked->objectives()
                ->whereNotNull('discord_channel_id')
                ->orderBy('id')
                ->get()
                ->each(fn (MilcomObjective $objective) => $this->discord->queueArchiveLocked($objective));

            $this->events->record(
                eventType: 'operation.archived',
                source: 'officer',
                operationId: $locked->id,
                actorUserId: $actorUserId,
            );

            return $locked;
        }, attempts: 5);
    }

    public function activate(MilcomOperation $operation, int $actorUserId): MilcomOperation
    {
        return DB::transaction(function () use ($operation, $actorUserId): MilcomOperation {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if ($locked->status === OperationStatus::Active) {
                return $locked;
            }

            if ($locked->type !== OperationType::Plan) {
                throw ValidationException::withMessages([
                    'operation' => 'Only mass war plans can be finalized this way.',
                ]);
            }

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'operation' => 'This operation has already ended. Clone it to start another wave.',
                ]);
            }

            $unresolvedStatuses = [
                ObjectiveStatus::Pending->value,
                ObjectiveStatus::Review->value,
                ObjectiveStatus::Blocked->value,
            ];
            $autoHeldObjectiveIds = $locked->objectives()
                ->where('priority_tier', '!=', PriorityTier::Hold->value)
                ->whereIn('status', $unresolvedStatuses)
                ->whereDoesntHave('assignments', fn (Builder $query) => $query
                    ->whereNotIn('status', [
                        AssignmentStatus::Released->value,
                        AssignmentStatus::Failed->value,
                    ]))
                ->lockForUpdate()
                ->pluck('id');

            if ($autoHeldObjectiveIds->isNotEmpty()) {
                MilcomObjective::query()
                    ->whereKey($autoHeldObjectiveIds)
                    ->update([
                        'priority_tier' => PriorityTier::Hold->value,
                        'minimum_team_depth' => 0,
                        'desired_team_depth' => 0,
                        'updated_at' => now(),
                    ]);

                $this->events->record(
                    eventType: 'operation.unstaffed_targets_held',
                    source: 'officer',
                    operationId: $locked->id,
                    actorUserId: $actorUserId,
                    payload: [
                        'count' => $autoHeldObjectiveIds->count(),
                        'objective_ids' => $autoHeldObjectiveIds->values()->all(),
                    ],
                );
            }

            $unresolvedTargets = $locked->objectives()
                ->where('priority_tier', '!=', PriorityTier::Hold->value)
                ->whereIn('status', $unresolvedStatuses)
                ->lockForUpdate()
                ->pluck('id')
                ->count();

            if ($unresolvedTargets > 0) {
                throw ValidationException::withMessages([
                    'operation' => $unresolvedTargets.' '.str('target')->plural($unresolvedTargets).' still need approval, cancellation, or Hold status.',
                ]);
            }

            $hasApprovedTeam = $locked->assignmentsThroughObjectives()
                ->whereIn('milcom_assignments.status', [
                    AssignmentStatus::Approved->value,
                    AssignmentStatus::Dispatched->value,
                    AssignmentStatus::Engaged->value,
                ])
                ->exists();

            if (! $hasApprovedTeam) {
                throw ValidationException::withMessages([
                    'operation' => 'Approve at least one target before finalizing the wave.',
                ]);
            }

            $locked->forceFill([
                'status' => OperationStatus::Active,
                'current_stage' => 'live',
                'approved_at' => $locked->approved_at ?? now(),
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'finalized_at' => now()->toIso8601String(),
                    'auto_held_target_count' => $autoHeldObjectiveIds->count(),
                ],
            ])->save();

            $this->events->record(
                eventType: 'operation.activated',
                source: 'officer',
                operationId: $locked->id,
                actorUserId: $actorUserId,
            );

            return $locked;
        }, attempts: 5);
    }

    public function complete(MilcomOperation $operation, int $actorUserId): MilcomOperation
    {
        return DB::transaction(function () use ($operation, $actorUserId): MilcomOperation {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if (in_array($locked->status, [OperationStatus::Completed, OperationStatus::Archived], true)) {
                return $locked;
            }

            if ($locked->assignmentsThroughObjectives()
                ->where('milcom_assignments.status', AssignmentStatus::Engaged->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'operation' => 'Wait for active wars to finish updating before completing this plan.',
                ]);
            }

            $objectives = $locked->objectives()->open()->orderBy('id')->lockForUpdate()->get();

            foreach ($objectives as $objective) {
                $objective->assignments()
                    ->whereIn('status', [
                        AssignmentStatus::Proposed->value,
                        AssignmentStatus::Approved->value,
                        AssignmentStatus::Dispatched->value,
                    ])
                    ->update([
                        'status' => AssignmentStatus::Released->value,
                        'released_at' => now(),
                        'updated_at' => now(),
                    ]);
                $objective->forceFill([
                    'status' => ObjectiveStatus::Completed,
                    'open_key' => null,
                    'completed_at' => now(),
                ])->save();
                $objective->incidents()
                    ->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Ignored->value])
                    ->update([
                        'status' => IncidentStatus::Resolved->value,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->discord->queueArchiveLocked($objective);
            }
            $locked->forceFill([
                'status' => OperationStatus::Completed,
                'completed_at' => now(),
                'current_stage' => 'complete',
            ])->save();

            $this->events->record(
                eventType: 'operation.completed',
                source: 'officer',
                operationId: $locked->id,
                actorUserId: $actorUserId,
            );

            return $locked;
        }, attempts: 5);
    }

    public function cancelObjective(
        MilcomObjective $objective,
        int $generationVersion,
        int $actorUserId,
        string $reason,
    ): MilcomObjective {
        return DB::transaction(function () use (
            $objective,
            $generationVersion,
            $actorUserId,
            $reason,
        ): MilcomObjective {
            $locked = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($locked->operation_id);
            $this->assertGeneration($operation, $generationVersion);

            if (in_array($locked->status, [
                ObjectiveStatus::Engaged,
                ObjectiveStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'objective' => 'You cannot cancel a target after a war starts or finishes.',
                ]);
            }

            $locked->assignments()
                ->whereNotIn('status', ['completed', 'released'])
                ->update([
                    'status' => 'released',
                    'override_reason' => $reason,
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
            $locked->forceFill([
                'status' => ObjectiveStatus::Cancelled,
                'open_key' => null,
                'cancelled_at' => now(),
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'cancellation_reason' => $reason,
                ],
            ])->save();

            if ($operation->type === OperationType::Counter) {
                $locked->incidents()
                    ->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Ignored->value])
                    ->update([
                        'status' => IncidentStatus::Ignored->value,
                        'ignored_reason' => $reason,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                $locked->incidents()
                    ->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Ignored->value])
                    ->update([
                        'status' => IncidentStatus::New->value,
                        'objective_id' => null,
                        'coverage_reason' => 'An officer cancelled the plan target that covered this war.',
                        'updated_at' => now(),
                    ]);
            }
            $this->discord->queueArchiveLocked($locked);

            $this->events->record(
                eventType: 'objective.cancelled',
                source: 'officer',
                operationId: $operation->id,
                objectiveId: $locked->id,
                actorUserId: $actorUserId,
                payload: ['reason' => $reason],
            );

            if (! $operation->objectives()->open()->exists()) {
                $operation->forceFill([
                    'status' => OperationStatus::Completed,
                    'current_stage' => 'complete',
                    'completed_at' => now(),
                ])->save();
                $this->events->record(
                    eventType: 'operation.completed',
                    source: 'system',
                    operationId: $operation->id,
                    payload: ['reason' => 'all_objectives_reconciled'],
                );
            }

            return $locked;
        }, attempts: 5);
    }

    public function clone(MilcomOperation $operation, int $creatorUserId): MilcomOperation
    {
        return DB::transaction(function () use ($operation, $creatorUserId): MilcomOperation {
            $source = $operation->load(['alliances', 'nations', 'objectives']);
            $sourceMetadata = $source->metadata ?? [];
            $seriesRootId = (int) ($sourceMetadata['series_root_id'] ?? $source->id);
            $waveNumber = max(1, (int) ($sourceMetadata['wave'] ?? 1));
            $baseName = trim((string) ($sourceMetadata['base_name'] ?? $source->name));
            $nextWaveNumber = MilcomOperation::query()
                ->plans()
                ->where(function ($query) use ($seriesRootId): void {
                    $query->whereKey($seriesRootId)
                        ->orWhere('metadata->series_root_id', $seriesRootId);
                })
                ->lockForUpdate()
                ->get(['id', 'metadata'])
                ->max(fn (MilcomOperation $wave): int => (int) data_get($wave->metadata, 'wave', 1)) + 1;

            if (! isset($sourceMetadata['series_root_id'], $sourceMetadata['base_name'])) {
                $source->forceFill([
                    'metadata' => [
                        ...$sourceMetadata,
                        'series_root_id' => $seriesRootId,
                        'base_name' => $baseName,
                        'wave' => $waveNumber,
                    ],
                ])->save();
            }

            $copy = $source->replicate([
                'status',
                'current_stage',
                'generation_version',
                'dispatch_version',
                'created_by',
                'generated_at',
                'approved_at',
                'dispatched_at',
                'completed_at',
                'archived_at',
                'failed_at',
                'failure_details',
            ]);
            $copy->forceFill([
                'name' => $baseName.' - Wave '.$nextWaveNumber,
                'status' => OperationStatus::Draft,
                'current_stage' => 'scope',
                'generation_version' => 1,
                'dispatch_version' => 0,
                'created_by' => $creatorUserId,
                'metadata' => [
                    ...$sourceMetadata,
                    'series_root_id' => $seriesRootId,
                    'base_name' => $baseName,
                    'wave' => $nextWaveNumber,
                    'source_operation_id' => $source->id,
                    'finalized_at' => null,
                ],
            ])->save();

            foreach ($source->alliances as $alliance) {
                $copy->alliances()->create($alliance->only(['alliance_id', 'role', 'included', 'metadata']));
            }

            foreach ($source->nations as $nation) {
                $copy->nations()->create($nation->only(['nation_id', 'role', 'included', 'reason']));
            }

            foreach ($source->objectives as $objective) {
                $copy->objectives()->create([
                    ...$objective->only([
                        'target_nation_id',
                        'priority_tier',
                        'priority_score',
                        'desired_team_depth',
                        'minimum_team_depth',
                        'war_type',
                        'war_reason',
                        'deadline_at',
                        'metadata',
                    ]),
                    'status' => ObjectiveStatus::Pending,
                    'generation_version' => 1,
                    'open_key' => null,
                ]);
            }

            $this->events->record(
                eventType: 'operation.cloned',
                source: 'officer',
                operationId: $copy->id,
                actorUserId: $creatorUserId,
                payload: ['source_operation_id' => $source->id],
            );

            return $copy;
        }, attempts: 5);
    }

    private function assertGeneration(MilcomOperation $operation, int $generationVersion): void
    {
        if ((int) $operation->generation_version !== $generationVersion) {
            throw new StaleGenerationException($generationVersion, (int) $operation->generation_version);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $nationIds
     * @return list<array<string, mixed>>
     */
    private function scopeNationRows(int $operationId, string $role, array $nationIds, bool $included): array
    {
        return array_map(fn (int $nationId): array => [
            'operation_id' => $operationId,
            'nation_id' => $nationId,
            'role' => $role,
            'included' => $included,
            'created_at' => now(),
            'updated_at' => now(),
        ], $nationIds);
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<int, float>
     */
    private function threatScores(array $targetIds): array
    {
        return Nation::query()
            ->with('military')
            ->whereIn('id', $targetIds)
            ->get()
            ->mapWithKeys(static function (Nation $nation): array {
                $military = $nation->military;
                $score = ((float) $nation->score * 0.35)
                    + ((int) $nation->num_cities * 75)
                    + ((int) ($military?->aircraft ?? 0) * 1.5)
                    + ((int) ($military?->tanks ?? 0) * 0.05)
                    + ((int) ($military?->ships ?? 0) * 3);

                return [(int) $nation->id => round($score / 100, 2)];
            })
            ->all();
    }

    /**
     * @param  list<int>  $targetIds
     * @param  list<int>  $friendlyAllianceIds
     * @param  list<int>  $includedFriendlyNationIds
     * @return list<int>
     */
    private function activeAggressorsAgainstFriendlyScope(
        array $targetIds,
        array $friendlyAllianceIds,
        array $includedFriendlyNationIds,
    ): array {
        return War::query()
            ->active()
            ->whereIn('att_id', $targetIds)
            ->where(function ($query) use ($friendlyAllianceIds, $includedFriendlyNationIds): void {
                if ($friendlyAllianceIds !== []) {
                    $query->whereIn('def_alliance_id', $friendlyAllianceIds);
                }

                if ($includedFriendlyNationIds !== []) {
                    $method = $friendlyAllianceIds !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('def_id', $includedFriendlyNationIds);
                }
            })
            ->distinct()
            ->pluck('att_id')
            ->map('intval')
            ->all();
    }

    /**
     * @param  list<int>  $targetIds
     * @param  array<int, float>  $threats
     * @param  list<int>  $criticalTargets
     * @param  array<int, string>  $priorityOverrides
     * @param  list<int>  $undeclarableTargets
     * @return array<int, PriorityTier>
     */
    private function assignTiers(
        array $targetIds,
        array $threats,
        array $criticalTargets,
        array $priorityOverrides,
        array $undeclarableTargets,
    ): array {
        $criticalLookup = array_fill_keys($criticalTargets, true);
        $undeclarableLookup = array_fill_keys($undeclarableTargets, true);
        $remaining = array_values(array_filter(
            $targetIds,
            fn (int $id): bool => ! isset($criticalLookup[$id])
                && ! isset($priorityOverrides[$id])
                && ! isset($undeclarableLookup[$id])
        ));
        usort($remaining, static fn (int $left, int $right): int => [
            $threats[$right] ?? 0,
            $left,
        ] <=> [
            $threats[$left] ?? 0,
            $right,
        ]);
        $highLookup = array_fill_keys(array_slice($remaining, 0, (int) ceil(count($remaining) * 0.25)), true);
        $tiers = [];

        foreach ($targetIds as $targetId) {
            if (isset($undeclarableLookup[$targetId])) {
                $tiers[$targetId] = PriorityTier::Hold;
            } elseif (isset($priorityOverrides[$targetId])) {
                $tiers[$targetId] = PriorityTier::from($priorityOverrides[$targetId]);
            } elseif (isset($criticalLookup[$targetId])) {
                $tiers[$targetId] = PriorityTier::Critical;
            } elseif (isset($highLookup[$targetId])) {
                $tiers[$targetId] = PriorityTier::High;
            } else {
                $tiers[$targetId] = PriorityTier::Standard;
            }
        }

        return $tiers;
    }
}
