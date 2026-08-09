<?php

namespace App\Services\Discord;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\ReadinessProfile;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentResponse;
use App\Models\MilcomObjective;
use App\Models\MilcomReadinessSnapshot;
use App\Models\Nation;
use App\Models\User;
use App\Services\Milcom\ReadinessSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class DiscordMilcomReadProvider
{
    /** @var list<string> */
    private const CURRENT_ASSIGNMENT_STATUSES = [
        AssignmentStatus::Approved->value,
        AssignmentStatus::Dispatched->value,
        AssignmentStatus::Engaged->value,
    ];

    /** @var list<string> */
    private const VISIBLE_ASSIGNMENT_STATUSES = [
        AssignmentStatus::Approved->value,
        AssignmentStatus::Dispatched->value,
        AssignmentStatus::Engaged->value,
        AssignmentStatus::Completed->value,
    ];

    public function __construct(private ReadinessSnapshotService $readiness) {}

    /** @return Collection<int, MilcomAssignment> */
    public function currentAssignments(User $actor): Collection
    {
        $nationId = $this->actorNationId($actor);

        $assignments = MilcomAssignment::query()
            ->where('friendly_nation_id', $nationId)
            ->whereIn('status', self::CURRENT_ASSIGNMENT_STATUSES)
            ->whereHas('objective', fn ($query) => $query
                ->whereNotIn('status', [
                    ObjectiveStatus::Completed->value,
                    ObjectiveStatus::Cancelled->value,
                    ObjectiveStatus::Expired->value,
                ])
                ->whereHas('operation', fn ($operation) => $operation->whereNotIn('status', [
                    OperationStatus::Completed->value,
                    OperationStatus::Archived->value,
                ])))
            ->with([
                'objective:id,operation_id,target_nation_id,priority_tier,status,war_type,war_reason,deadline_at,discord_channel_id',
                'objective.operation:id,name,type,status,metadata',
                'objective.target:id,nation_name,leader_name,alliance_id,score,num_cities',
                'objective.target.alliance:id,name,acronym',
                'declaredWar:id,date,end_date,turns_left,att_resistance,def_resistance,winner_id',
            ])
            ->orderByRaw('EXISTS (SELECT 1 FROM milcom_objectives WHERE milcom_objectives.id = milcom_assignments.objective_id AND milcom_objectives.deadline_at IS NULL)')
            ->orderBy(
                MilcomObjective::query()
                    ->select('deadline_at')
                    ->whereColumn('milcom_objectives.id', 'milcom_assignments.objective_id')
                    ->limit(1),
            )
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return $assignments;
        }

        $responses = MilcomAssignmentResponse::query()
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->get()
            ->keyBy('assignment_id');

        $assignments->each(function (MilcomAssignment $assignment) use ($responses): void {
            $assignment->setRelation('discordActorResponse', $responses->get($assignment->id));
        });

        return $assignments;
    }

    /**
     * @return array{
     *     nation: Nation,
     *     profile: ReadinessProfile,
     *     snapshot: MilcomReadinessSnapshot
     * }|null
     */
    public function readiness(User $actor, ?int $requestedNationId = null): ?array
    {
        $actorNationId = $this->actorNationId($actor);
        $nationId = $requestedNationId ?? $actorNationId;

        if ($nationId !== $actorNationId
            && ! Gate::forUser($actor)->allows('view-wars')
            && ! Gate::forUser($actor)->allows('manage-war-room')) {
            throw new AuthorizationException('Viewing another nation requires war information authority.');
        }

        $snapshot = MilcomReadinessSnapshot::query()
            ->with('recommendationRun')
            ->where('nation_id', $nationId)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot?->recommendationRun === null) {
            return null;
        }

        $snapshotProfiles = $this->readiness->profilesForRun($snapshot->recommendationRun, [$nationId]);
        $profile = $snapshotProfiles[$nationId] ?? null;
        $nation = Nation::query()
            ->with('alliance:id,name,acronym')
            ->find($nationId);

        if (! $profile instanceof ReadinessProfile || ! $nation instanceof Nation) {
            return null;
        }

        return [
            'nation' => $nation,
            'profile' => $profile,
            'snapshot' => $snapshot,
        ];
    }

    public function warRoom(User $actor, int $objectiveId): ?MilcomObjective
    {
        $nationId = $this->actorNationId($actor);
        $canManage = Gate::forUser($actor)->allows('manage-war-room');
        $isParticipant = MilcomAssignment::query()
            ->where('objective_id', $objectiveId)
            ->where('friendly_nation_id', $nationId)
            ->whereIn('status', self::VISIBLE_ASSIGNMENT_STATUSES)
            ->exists();

        if (! $canManage && ! $isParticipant) {
            throw new AuthorizationException('Only assigned participants or Milcom managers may view this war room.');
        }

        $objective = MilcomObjective::query()->find($objectiveId);

        if (! $objective instanceof MilcomObjective || blank($objective->discord_channel_id)) {
            return null;
        }

        $objective->load([
            'operation:id,name,type,status,metadata',
            'target:id,nation_name,leader_name,alliance_id,score,num_cities',
            'target.alliance:id,name,acronym',
            'assignments' => fn ($query) => $query
                ->whereIn('status', self::VISIBLE_ASSIGNMENT_STATUSES)
                ->orderBy('rank')
                ->orderBy('id'),
            'assignments.friendlyNation:id,nation_name,leader_name',
            'assignments.declaredWar:id,date,end_date,turns_left,att_resistance,def_resistance,winner_id',
        ]);

        return $objective;
    }

    private function actorNationId(User $actor): int
    {
        if ($actor->disabled || $actor->verified_at === null || ! is_numeric($actor->nation_id)) {
            throw new AuthorizationException('Discord Milcom requires an active verified Nexus nation.');
        }

        $nationId = (int) $actor->nation_id;

        if ($nationId < 1) {
            throw new AuthorizationException('Discord Milcom requires an active verified Nexus nation.');
        }

        return $nationId;
    }
}
