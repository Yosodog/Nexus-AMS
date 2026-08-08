<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Models\MilcomAssignment;
use App\Models\MilcomDispatch;
use App\Models\MilcomEvent;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarAttack;
use App\Services\AllianceMembershipService;
use App\Services\RaidPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LifecycleReconciler
{
    public function __construct(
        private readonly AllianceMembershipService $membership,
        private readonly IncidentService $incidents,
        private readonly DiscordDispatchService $discord,
        private readonly MilcomEventRecorder $events,
        private readonly RaidPolicyService $raidPolicy,
    ) {}

    public function reconcileDeclaration(int $warId): void
    {
        $this->reconcileWarState($warId, true);
    }

    public function reconcileWar(int $warId): void
    {
        $this->reconcileWarState($warId, false);
    }

    private function reconcileWarState(int $warId, bool $evaluateRaidPolicy): void
    {
        $war = War::query()->find($warId);

        if ($war === null) {
            return;
        }

        DB::transaction(function () use ($evaluateRaidPolicy, $war): void {
            $assignment = MilcomAssignment::query()
                ->where('declared_war_id', $war->id)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = MilcomAssignment::query()
                    ->where('friendly_nation_id', $war->att_id)
                    ->whereHas('objective', fn ($query) => $query->where('target_nation_id', $war->def_id))
                    ->whereIn('status', [
                        AssignmentStatus::Approved->value,
                        AssignmentStatus::Dispatched->value,
                        AssignmentStatus::Engaged->value,
                    ])
                    ->orderByRaw("CASE WHEN status = 'dispatched' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")
                    ->lockForUpdate()
                    ->first();
            }

            if ($assignment === null) {
                if ($evaluateRaidPolicy && $this->membership->contains($war->att_alliance_id)) {
                    $this->recordRaidPolicyViolation($war);
                }

                return;
            }

            $objective = MilcomObjective::query()->lockForUpdate()->findOrFail($assignment->objective_id);
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($objective->operation_id);

            if ($operation->federation_action_required) {
                return;
            }

            $isEnded = $war->end_date !== null || (int) $war->turns_left <= 0;

            if (! $isEnded && $assignment->status !== AssignmentStatus::Engaged) {
                $assignment->forceFill([
                    'status' => AssignmentStatus::Engaged,
                    'declared_war_id' => $war->id,
                    'engaged_at' => now(),
                ])->save();
                $objective->forceFill([
                    'status' => ObjectiveStatus::Engaged,
                    'engaged_at' => $objective->engaged_at ?? now(),
                ])->save();
                $operation->forceFill([
                    'status' => OperationStatus::Active,
                    'current_stage' => 'live',
                ])->save();

                $this->events->record(
                    eventType: 'assignment.engaged',
                    source: 'game',
                    operationId: $operation->id,
                    objectiveId: $objective->id,
                    assignmentId: $assignment->id,
                    payload: ['war_id' => $war->id],
                );

                return;
            }

            if (! $isEnded) {
                return;
            }

            if ($assignment->status !== AssignmentStatus::Completed) {
                $assignment->forceFill([
                    'status' => AssignmentStatus::Completed,
                    'declared_war_id' => $war->id,
                    'completed_at' => now(),
                ])->save();

                $this->events->record(
                    eventType: 'assignment.completed',
                    source: 'game',
                    operationId: $operation->id,
                    objectiveId: $objective->id,
                    assignmentId: $assignment->id,
                    payload: [
                        'war_id' => $war->id,
                        'winner_id' => $war->winner_id,
                    ],
                );
            }

            $this->completeObjectiveIfReconciled($objective, $operation);
        }, attempts: 5);
    }

    private function recordRaidPolicyViolation(War $war): void
    {
        $evaluation = $this->raidPolicy->evaluateAlliance((int) $war->def_alliance_id);

        if ($evaluation->allowed) {
            return;
        }

        $eventType = MilcomEvent::RAID_POLICY_VIOLATION_PREFIX.$war->id;

        $war->loadMissing(['attacker:id,nation_name', 'defender:id,nation_name']);
        $this->events->record(
            eventType: $eventType,
            source: 'game',
            payload: [
                'war_id' => (int) $war->id,
                'friendly_nation_id' => (int) $war->att_id,
                'friendly_nation_name' => $war->attacker?->nation_name,
                'friendly_alliance_id' => (int) $war->att_alliance_id,
                'target_nation_id' => (int) $war->def_id,
                'target_nation_name' => $war->defender?->nation_name,
                'target_alliance_id' => (int) $war->def_alliance_id,
                'raid_policy' => $evaluation->toArray(),
                'evaluated_at' => now()->toIso8601String(),
            ],
            deduplicationKey: $eventType,
        );
    }

    public function recordAttack(int $attackId, int $warId): void
    {
        $attack = WarAttack::query()->find($attackId);

        if ($attack === null) {
            return;
        }

        $assignment = MilcomAssignment::query()
            ->where('declared_war_id', $warId)
            ->with('objective.operation')
            ->first();

        if ($assignment === null) {
            $this->reconcileWar($warId);
            $assignment = MilcomAssignment::query()
                ->where('declared_war_id', $warId)
                ->with('objective')
                ->first();

            if ($assignment === null) {
                return;
            }
        }

        $isAssignedAttacker = (int) $attack->att_id === (int) $assignment->friendly_nation_id;
        $outcome = (int) $attack->success > 0 ? 'success' : 'failed';
        $eventType = $isAssignedAttacker
            ? "war.attack.outgoing.{$outcome}.{$attackId}"
            : "war.attack.incoming.{$attackId}";

        if (MilcomEvent::query()
            ->where('operation_id', $assignment->objective->operation_id)
            ->where('event_type', $eventType)
            ->exists()) {
            return;
        }

        $this->events->record(
            eventType: $eventType,
            source: 'game',
            operationId: $assignment->objective->operation_id,
            objectiveId: $assignment->objective_id,
            assignmentId: $assignment->id,
            payload: [
                'attack_id' => $attackId,
                'war_id' => $warId,
                'attacker_nation_id' => (int) $attack->att_id,
                'defender_nation_id' => (int) $attack->def_id,
                'attack_type' => $attack->type->value,
                'success' => (int) $attack->success,
                'is_assigned_attacker' => $isAssignedAttacker,
            ],
        );
    }

    public function reconcileAll(): void
    {
        $warIds = MilcomAssignment::query()
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
                AssignmentStatus::Engaged->value,
            ])
            ->whereNotNull('declared_war_id')
            ->pluck('declared_war_id')
            ->map('intval')
            ->all();

        foreach ($warIds as $warId) {
            $this->reconcileWar($warId);
        }

        $this->linkUnmatchedDeclarations();
        $this->expireDeadlines();
        $this->reconcileDispatches();
        $this->archiveTerminalRooms();
        $this->recordCapacityConflicts();
    }

    private function linkUnmatchedDeclarations(): void
    {
        $assignments = MilcomAssignment::query()
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
            ])
            ->whereNull('declared_war_id')
            ->with('objective:id,target_nation_id')
            ->get();

        if ($assignments->isEmpty()) {
            return;
        }

        $friendlyIds = $assignments->pluck('friendly_nation_id')->unique()->values()->all();
        $targetIds = $assignments->pluck('objective.target_nation_id')->unique()->values()->all();
        $wars = War::query()
            ->active()
            ->whereIn('att_id', $friendlyIds)
            ->whereIn('def_id', $targetIds)
            ->get(['id', 'att_id', 'def_id'])
            ->keyBy(fn (War $war): string => "{$war->att_id}:{$war->def_id}");

        foreach ($assignments as $assignment) {
            $war = $wars["{$assignment->friendly_nation_id}:{$assignment->objective->target_nation_id}"] ?? null;

            if ($war !== null) {
                $this->reconcileWar((int) $war->id);
            }
        }
    }

    private function expireDeadlines(): void
    {
        $objectives = MilcomObjective::query()
            ->open()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->with('operation')
            ->orderBy('id')
            ->get();

        foreach ($objectives as $objective) {
            $reopenedIncidentIds = DB::transaction(function () use ($objective): array {
                $locked = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
                $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($locked->operation_id);

                if ($operation->federation_action_required) {
                    return [];
                }

                if (! $locked->status->isOpen() || $locked->engaged_at !== null) {
                    return [];
                }

                $reopenedIncidentIds = $locked->incidents()
                    ->whereIn('status', [
                        IncidentStatus::New->value,
                        IncidentStatus::Countering->value,
                        IncidentStatus::CoveredByPlan->value,
                    ])
                    ->pluck('id')
                    ->map('intval')
                    ->all();

                $locked->assignments()
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
                $locked->forceFill([
                    'status' => ObjectiveStatus::Expired,
                    'open_key' => null,
                    'expired_at' => now(),
                ])->save();
                $locked->incidents()->whereIn('status', [
                    IncidentStatus::New->value,
                    IncidentStatus::Countering->value,
                    IncidentStatus::CoveredByPlan->value,
                ])->update([
                    'status' => IncidentStatus::New->value,
                    'objective_id' => null,
                    'coverage_reason' => 'The assigned team did not declare before the deadline.',
                    'updated_at' => now(),
                ]);

                $this->events->record(
                    eventType: 'objective.expired',
                    operationId: $locked->operation_id,
                    objectiveId: $locked->id,
                );
                $this->discord->queueArchiveLocked($locked);
                $this->completeOperationIfReconciled($locked->operation);

                return $reopenedIncidentIds;
            }, attempts: 5);

            $incidents = MilcomIncident::query()
                ->with('war')
                ->whereIn('id', $reopenedIncidentIds)
                ->get();

            foreach ($incidents as $incident) {
                $war = $incident->war;

                if ($war === null || $war->end_date !== null || (int) $war->turns_left <= 0) {
                    $incident->forceFill([
                        'status' => IncidentStatus::Resolved,
                        'resolved_at' => now(),
                    ])->save();

                    continue;
                }

                $this->incidents->ingest(
                    warId: (int) $war->id,
                    aggressorNationId: (int) $incident->aggressor_nation_id,
                    aggressorAllianceId: $war->att_alliance_id !== null ? (int) $war->att_alliance_id : null,
                    attackedNationId: (int) $incident->attacked_nation_id,
                    attackedAllianceId: $war->def_alliance_id !== null ? (int) $war->def_alliance_id : null,
                );
            }
        }
    }

    private function completeObjectiveIfReconciled(
        MilcomObjective $objective,
        MilcomOperation $operation,
    ): void {
        $stillOpen = $objective->assignments()
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
                AssignmentStatus::Engaged->value,
            ])
            ->exists();

        if ($stillOpen) {
            return;
        }

        $objective->forceFill([
            'status' => ObjectiveStatus::Completed,
            'open_key' => null,
            'completed_at' => now(),
        ])->save();
        $objective->incidents()->update([
            'status' => IncidentStatus::Resolved->value,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->events->record(
            eventType: 'objective.completed',
            source: 'game',
            operationId: $operation->id,
            objectiveId: $objective->id,
        );
        $this->discord->queueArchiveLocked($objective);
        $this->completeOperationIfReconciled($operation);
    }

    private function completeOperationIfReconciled(MilcomOperation $operation): void
    {
        if ($operation->federation_action_required) {
            return;
        }

        $hasOpenObjectives = $operation->objectives()->open()->exists();

        if ($hasOpenObjectives) {
            return;
        }

        $operation->forceFill([
            'status' => OperationStatus::Completed,
            'current_stage' => 'complete',
            'completed_at' => now(),
        ])->save();

        $this->events->record(
            eventType: 'operation.completed',
            source: 'system',
            operationId: $operation->id,
        );
    }

    private function recordCapacityConflicts(): void
    {
        $reservations = MilcomAssignment::query()
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
            ])
            ->selectRaw('friendly_nation_id, COUNT(*) as aggregate')
            ->groupBy('friendly_nation_id')
            ->pluck('aggregate', 'friendly_nation_id');

        if ($reservations->isEmpty()) {
            return;
        }

        $activeWars = War::query()
            ->active()
            ->whereIn('att_id', $reservations->keys())
            ->selectRaw('att_id, COUNT(*) as aggregate')
            ->groupBy('att_id')
            ->pluck('aggregate', 'att_id');

        $nations = Nation::query()->whereIn('id', $reservations->keys())->get()->keyBy('id');

        $conflicts = [];

        foreach ($reservations as $nationId => $reserved) {
            $nation = $nations[$nationId] ?? null;

            if ($nation === null) {
                continue;
            }

            $capacity = (int) config('milcom.game_rules.base_offensive_slots', 5);

            foreach ((array) config('milcom.game_rules.offensive_slot_projects', []) as $project => $modifier) {
                if ((bool) ($nation->projects[$project] ?? false)) {
                    $capacity += (int) $modifier;
                }
            }

            $used = (int) ($activeWars[$nationId] ?? 0) + (int) $reserved;

            if ($used <= $capacity) {
                continue;
            }

            $conflicts[(int) $nationId] = [
                'nation_id' => (int) $nationId,
                'capacity' => $capacity,
                'active_offensive_wars' => (int) ($activeWars[$nationId] ?? 0),
                'reservations' => (int) $reserved,
            ];
        }

        if ($conflicts === []) {
            return;
        }

        $assignments = MilcomAssignment::query()
            ->whereIn('friendly_nation_id', array_keys($conflicts))
            ->whereIn('status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
            ])
            ->with('objective:id,operation_id')
            ->orderBy('id')
            ->get()
            ->groupBy('friendly_nation_id');

        foreach ($conflicts as $nationId => $context) {
            $operationAssignments = $assignments->get($nationId, collect())
                ->filter(fn (MilcomAssignment $assignment): bool => $assignment->objective !== null)
                ->groupBy(fn (MilcomAssignment $assignment): int => (int) $assignment->objective->operation_id);

            foreach ($operationAssignments as $operationId => $affectedAssignments) {
                /** @var MilcomAssignment $assignment */
                $assignment = $affectedAssignments->first();
                $eventType = "capacity.conflict.{$nationId}";

                if (MilcomEvent::query()
                    ->where('operation_id', $operationId)
                    ->where('event_type', $eventType)
                    ->where('created_at', '>=', now()->subHour())
                    ->exists()) {
                    continue;
                }

                $this->events->record(
                    eventType: $eventType,
                    source: 'system',
                    operationId: (int) $operationId,
                    objectiveId: $assignment->objective_id,
                    assignmentId: $assignment->id,
                    payload: $context,
                );
                Log::warning('Milcom capacity reconciliation conflict detected.', [
                    ...$context,
                    'operation_id' => (int) $operationId,
                    'objective_id' => $assignment->objective_id,
                    'assignment_id' => $assignment->id,
                ]);
            }
        }
    }

    private function reconcileDispatches(): void
    {
        $dispatches = MilcomDispatch::query()
            ->where('status', DispatchStatus::Queued->value)
            ->whereNotNull('queue_id')
            ->with('objective')
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($dispatches->isEmpty()) {
            return;
        }

        $commands = DiscordQueue::query()
            ->whereIn('id', $dispatches->pluck('queue_id'))
            ->get()
            ->keyBy('id');

        foreach ($dispatches as $dispatch) {
            if ($dispatch->objective?->operation?->federation_action_required) {
                continue;
            }

            $command = $commands[$dispatch->queue_id] ?? null;

            if ($command === null) {
                continue;
            }

            if ($command->status === DiscordQueueStatus::Failed) {
                $dispatch->forceFill([
                    'status' => DispatchStatus::Failed,
                    'errors' => $command->last_error,
                    'failed_at' => now(),
                ])->save();
                $this->events->record(
                    eventType: 'objective.discord_failed',
                    operationId: $dispatch->operation_id,
                    objectiveId: $dispatch->objective_id,
                    payload: ['dispatch_id' => $dispatch->id, 'queue_id' => $command->id],
                );
                Log::warning('Milcom Discord dispatch failed.', [
                    'operation_id' => $dispatch->operation_id,
                    'objective_id' => $dispatch->objective_id,
                    'dispatch_id' => $dispatch->id,
                    'queue_id' => $command->id,
                    'error' => $command->last_error,
                ]);

                continue;
            }

            if ($command->status !== DiscordQueueStatus::Complete) {
                continue;
            }

            $isArchive = str_contains($dispatch->dedupe_key, ':archive:');
            $channelId = (string) (
                $dispatch->external_channel_id
                ?: ($command->result['discord_channel_id'] ?? '')
                ?: $dispatch->objective?->discord_channel_id
            );
            $dispatch->forceFill([
                'status' => $isArchive ? DispatchStatus::Archived : DispatchStatus::Sent,
                'external_channel_id' => $channelId !== '' ? $channelId : null,
                'sent_at' => $isArchive ? $dispatch->sent_at : ($dispatch->sent_at ?? now()),
                'archived_at' => $isArchive ? now() : null,
            ])->save();

            if (! $isArchive && $channelId !== '' && $dispatch->objective !== null
                && trim((string) $dispatch->objective->discord_channel_id) === '') {
                $dispatch->objective->forceFill(['discord_channel_id' => $channelId])->save();
            }
        }
    }

    private function archiveTerminalRooms(): void
    {
        $objectives = MilcomObjective::query()
            ->whereIn('status', [
                ObjectiveStatus::Completed->value,
                ObjectiveStatus::Cancelled->value,
                ObjectiveStatus::Expired->value,
            ])
            ->whereNotNull('discord_channel_id')
            ->whereDoesntHave('dispatches', fn ($query) => $query
                ->where('dedupe_key', 'like', '%:archive:v%'))
            ->orderBy('id')
            ->limit(500)
            ->get(['id']);

        foreach ($objectives as $objective) {
            DB::transaction(function () use ($objective): void {
                $locked = MilcomObjective::query()->lockForUpdate()->findOrFail($objective->id);
                $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($locked->operation_id);

                if ($operation->federation_action_required) {
                    return;
                }

                if (! in_array($locked->status, [
                    ObjectiveStatus::Completed,
                    ObjectiveStatus::Cancelled,
                    ObjectiveStatus::Expired,
                ], true) || trim((string) $locked->discord_channel_id) === '') {
                    return;
                }

                $dispatchVersion = max(1, (int) $locked->dispatch_version);
                $creationDispatch = MilcomDispatch::query()
                    ->where('objective_id', $locked->id)
                    ->where('dispatch_version', $dispatchVersion)
                    ->where('dedupe_key', "milcom-objective:{$locked->id}:room:v{$dispatchVersion}")
                    ->latest('id')
                    ->first();

                if ($creationDispatch?->queue_id === null
                    || ! DiscordQueue::query()
                        ->whereKey($creationDispatch->queue_id)
                        ->where('status', DiscordQueueStatus::Complete->value)
                        ->exists()) {
                    return;
                }

                $this->discord->queueArchiveLocked($locked);
            }, attempts: 5);
        }
    }
}
