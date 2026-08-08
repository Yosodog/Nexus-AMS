<?php

namespace App\Services\Milcom;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Models\MilcomDispatch;
use App\Models\MilcomObjective;
use App\Models\Nation;
use App\Services\Discord\DiscordQueueService;
use App\Services\SettingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscordDispatchService
{
    public function __construct(
        private readonly DiscordQueueService $discordQueue,
        private readonly MilcomEventRecorder $events,
        private readonly FederationOperationGuard $federationGuard,
    ) {}

    /**
     * The caller must hold the operation, objective, assignment, and capacity locks.
     */
    public function queueLocked(MilcomObjective $objective, int $actorUserId): MilcomDispatch
    {
        $objective->loadMissing([
            'operation',
            'target.alliance',
            'target.military',
            'sourceIncident.attackedNation.user.discordAccounts',
            'assignments.friendlyNation.alliance',
            'assignments.friendlyNation.military',
            'assignments.friendlyNation.user.discordAccounts',
            'assignments.friendlyNation.accountProfile',
        ]);

        $operation = $objective->operation;
        $this->federationGuard->assertMutable($operation, 'discord_dispatch');
        $forumId = trim((string) ($operation->discord_forum_id
            ?: config('milcom.discord.forum_id')
            ?: SettingService::getDiscordWarRoomForumId()));

        if ($forumId === '') {
            throw ValidationException::withMessages([
                'discord_forum_id' => 'Set a Discord war room forum before creating the room.',
            ]);
        }

        $dispatchVersion = (int) $objective->dispatch_version + 1;
        $dedupeKey = "milcom-objective:{$objective->id}:room:v{$dispatchVersion}";

        $dispatch = MilcomDispatch::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => $dispatchVersion,
            'status' => DispatchStatus::Pending,
            'dedupe_key' => $dedupeKey,
            'payload_snapshot' => [],
        ]);

        $payload = $this->creationPayload($objective, $dispatch, $forumId);
        $queueItem = $this->discordQueue->enqueue(
            'WAR_ROOM_CREATE',
            $payload,
            dedupeKey: $dedupeKey,
        );

        $dispatch->forceFill([
            'status' => DispatchStatus::Queued,
            'queue_id' => $queueItem->id,
            'payload_snapshot' => $payload,
            'queued_at' => now(),
        ])->save();

        $objective->forceFill([
            'status' => ObjectiveStatus::Dispatched,
            'dispatch_version' => $dispatchVersion,
            'dispatched_at' => now(),
        ])->save();

        $objective->assignments()
            ->where('status', AssignmentStatus::Approved->value)
            ->update([
                'status' => AssignmentStatus::Dispatched->value,
                'dispatched_at' => now(),
                'updated_at' => now(),
            ]);

        $operation->forceFill([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'dispatch_version' => max((int) $operation->dispatch_version, $dispatchVersion),
            'dispatched_at' => $operation->dispatched_at ?? now(),
        ])->save();

        $this->events->record(
            eventType: 'objective.discord_queued',
            source: 'officer',
            operationId: $operation->id,
            objectiveId: $objective->id,
            actorUserId: $actorUserId,
            payload: [
                'dispatch_id' => $dispatch->id,
                'queue_id' => $queueItem->id,
                'dedupe_key' => $dedupeKey,
            ],
        );

        return $dispatch;
    }

    public function queueArchiveLocked(MilcomObjective $objective): ?MilcomDispatch
    {
        $objective->loadMissing('operation');
        $this->federationGuard->assertMutable($objective->operation, 'discord_archive');

        if (trim((string) $objective->discord_channel_id) === '') {
            return null;
        }

        $dispatchVersion = max(1, (int) $objective->dispatch_version);
        $dedupeKey = "milcom-objective:{$objective->id}:archive:v{$dispatchVersion}";
        $payload = [
            'discord_channel_id' => $objective->discord_channel_id,
            'source' => [
                'type' => 'milcom_objective',
                'id' => $objective->id,
            ],
            'archive' => [
                'lock' => true,
                'title_prefix' => '[Archived] ',
            ],
            'archived_at' => now()->toIso8601String(),
        ];

        $dispatch = MilcomDispatch::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'operation_id' => $objective->operation_id,
                'objective_id' => $objective->id,
                'dispatch_version' => $dispatchVersion,
                'status' => DispatchStatus::Pending,
                'payload_snapshot' => $payload,
            ],
        );

        if ($dispatch->status !== DispatchStatus::Pending) {
            return $dispatch;
        }

        $queueItem = $this->discordQueue->enqueue(
            'WAR_ROOM_ARCHIVE',
            $payload,
            dedupeKey: $dedupeKey,
        );

        $dispatch->forceFill([
            'status' => DispatchStatus::Queued,
            'queue_id' => $queueItem->id,
            'queued_at' => now(),
        ])->save();

        $this->events->record(
            eventType: 'objective.discord_archive_queued',
            operationId: $objective->operation_id,
            objectiveId: $objective->id,
            payload: ['dispatch_id' => $dispatch->id, 'queue_id' => $queueItem->id],
        );

        return $dispatch;
    }

    /**
     * Requeue the original Discord command so a persisted room checkpoint is
     * reused instead of creating a second forum room.
     */
    public function retryLocked(MilcomObjective $objective, int $actorUserId): MilcomDispatch
    {
        $objective->loadMissing('operation');
        $this->federationGuard->assertMutable($objective->operation, 'discord_retry');

        if (! $objective->status->isOpen()) {
            throw ValidationException::withMessages([
                'dispatch' => 'You cannot retry a room for a finished target.',
            ]);
        }

        $failedDispatch = $objective->dispatches()
            ->where('status', DispatchStatus::Failed->value)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($failedDispatch?->queue_id === null) {
            throw ValidationException::withMessages([
                'dispatch' => 'There is no failed Discord room to retry.',
            ]);
        }

        $queueItem = DiscordQueue::query()->lockForUpdate()->find($failedDispatch->queue_id);

        if ($queueItem === null || $queueItem->status !== DiscordQueueStatus::Failed) {
            throw ValidationException::withMessages([
                'dispatch' => 'The Discord retry is no longer available. Refresh and try again.',
            ]);
        }

        $queueItem->forceFill([
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'claim_request_id' => null,
            'worker_id' => null,
            'lease_token' => null,
            'leased_until' => null,
            'last_error' => null,
            'completed_at' => null,
        ])->save();
        $failedDispatch->forceFill([
            'status' => DispatchStatus::Queued,
            'errors' => null,
            'failed_at' => null,
            'queued_at' => now(),
        ])->save();

        $this->events->record(
            eventType: 'objective.discord_retry_queued',
            source: 'officer',
            operationId: $objective->operation_id,
            objectiveId: $objective->id,
            actorUserId: $actorUserId,
            payload: [
                'dispatch_id' => $failedDispatch->id,
                'queue_id' => $queueItem->id,
                'dedupe_key' => $failedDispatch->dedupe_key,
                'checkpoint_reused' => filled(data_get($queueItem->result, 'discord_channel_id')),
            ],
        );

        return $failedDispatch;
    }

    /**
     * @return array<string, mixed>
     */
    private function creationPayload(
        MilcomObjective $objective,
        MilcomDispatch $dispatch,
        string $forumId,
    ): array {
        $operation = $objective->operation;
        $target = $objective->target;
        $defenseRoleId = trim((string) (
            config('milcom.discord.defense_role_id')
            ?: SettingService::getDiscordWarRoomDefenseRoleId()
        ));
        $sourceUrl = $operation->type === OperationType::Plan
            ? route('admin.milcom.plans.show', $operation)
            : route('admin.milcom.counters', ['objective' => $objective->id]);
        $assigned = $objective->assignments
            ->filter(fn ($assignment): bool => in_array($assignment->status, [
                AssignmentStatus::Approved,
                AssignmentStatus::Dispatched,
            ], true))
            ->sortBy('rank')
            ->map(fn ($assignment): array => $this->memberPayload(
                $assignment->friendlyNation,
                (float) $assignment->score,
                'attacker'
            ))
            ->values()
            ->all();
        $attackedNation = $objective->sourceIncident?->attackedNation;
        $wave = (int) ($operation->metadata['wave'] ?? 1);
        $storedTags = SettingService::getValue('milcom_forum_tag_ids');
        $storedTags = is_string($storedTags) ? json_decode($storedTags, true) : null;
        $tags = array_values(array_filter(
            array_map('strval', is_array($storedTags)
                ? $storedTags
                : (array) config('milcom.discord.forum_tag_ids', [])),
            static fn (string $tag): bool => $tag !== ''
        ));

        return [
            'dispatch_id' => $dispatch->id,
            'forum_channel_id' => $forumId,
            'forum_tag_ids' => array_slice($tags, 0, 5),
            'source' => [
                'type' => 'milcom_objective',
                'id' => $objective->id,
                'operation_id' => $operation->id,
                'operation_type' => $operation->type->value,
                'name' => Str::limit($operation->name, 160, ''),
                'url' => $sourceUrl,
            ],
            'objective' => [
                'id' => $objective->id,
                'wave' => $wave,
                'priority' => $objective->priority_tier->value,
                'deadline_at' => $objective->deadline_at?->toIso8601String(),
            ],
            'wave' => $wave,
            'priority_tier' => $objective->priority_tier->value,
            'target' => $this->targetPayload($target),
            'war_type' => [
                'key' => $objective->war_type,
                'label' => Str::headline($objective->war_type),
            ],
            'reason' => $objective->war_reason ?: $operation->default_war_reason,
            'defense_role_id' => $operation->type === OperationType::Counter && $defenseRoleId !== ''
                ? $defenseRoleId
                : null,
            'attacked_member' => $attackedNation !== null
                ? $this->memberPayload($attackedNation, null, 'defender')
                : null,
            'assigned_members' => $assigned,
            'links' => [
                'declare_war' => "https://politicsandwar.com/nation/war/declare/id={$target->id}",
                'target_nation' => "https://politicsandwar.com/nation/id={$target->id}",
                'war_simulators' => route('defense.simulators'),
                'operation' => $sourceUrl,
                'war_timeline' => $objective->sourceIncident !== null
                    ? "https://politicsandwar.com/nation/war/timeline/war={$objective->sourceIncident->war_id}"
                    : $sourceUrl.'#timeline',
            ],
            'room_name_suggestion' => Str::limit(sprintf(
                '%s-%d-%s-%d',
                $operation->type->value,
                $objective->id,
                Str::of($target->leader_name ?: $target->nation_name ?: 'target')->slug('-'),
                $target->id,
            ), 100, ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetPayload(Nation $nation): array
    {
        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'beige_turns' => $nation->beige_turns,
            'offensive_wars' => $nation->offensive_wars_count,
            'defensive_wars' => $nation->defensive_wars_count,
            'alliance' => $nation->alliance !== null ? [
                'id' => $nation->alliance->id,
                'name' => $nation->alliance->name,
                'acronym' => $nation->alliance->acronym,
            ] : null,
            'military' => $nation->military !== null ? [
                'soldiers' => $nation->military->soldiers,
                'tanks' => $nation->military->tanks,
                'aircraft' => $nation->military->aircraft,
                'ships' => $nation->military->ships,
                'missiles' => $nation->military->missiles,
                'nukes' => $nation->military->nukes,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(Nation $nation, ?float $score, string $role): array
    {
        $discordId = $nation->user?->discordAccounts
            ?->whereNull('unlinked_at')
            ->sortByDesc('linked_at')
            ->first()
            ?->discord_id
            ?? $nation->accountProfile?->discord_id
            ?? $nation->discord_id;

        return [
            'nation_id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'role' => $role,
            'match_score' => $score,
            'discord_id' => $discordId !== null ? (string) $discordId : null,
            'mention' => $discordId !== null ? "<@{$discordId}>" : null,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'offensive_wars' => $nation->offensive_wars_count,
            'defensive_wars' => $nation->defensive_wars_count,
            'links' => [
                'nation' => "https://politicsandwar.com/nation/id={$nation->id}",
            ],
        ];
    }
}
