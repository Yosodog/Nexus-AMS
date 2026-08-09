<?php

namespace App\Services\Discord;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentResponse;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\User;
use App\Models\War;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class DiscordMilcomAssignmentResponseService
{
    public const ASSIGNMENT_TYPE = 'milcom_v2';

    public const INTENT_ACTION = 'milcom_v2.assignment_response';

    /** @var list<string> */
    public const RESPONSES = ['acknowledged', 'unavailable'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @return array{
     *     assignment: MilcomAssignment,
     *     response: string,
     *     reason: string|null,
     *     resource_version: string
     * }
     */
    public function preview(
        User $actor,
        int $assignmentId,
        string $response,
        ?string $reason,
    ): array {
        [$response, $reason] = $this->normalizedResponse($response, $reason, staleIntent: false);
        $assignment = $this->liveAssignment($actor, $assignmentId);

        if (! $assignment instanceof MilcomAssignment) {
            throw new DiscordMilcomAssignmentResponseException(
                'milcom_assignment_not_found',
                'No current Milcom-v2 assignment was found for this actor.',
                404,
            );
        }

        $currentResponse = $this->currentResponse($assignmentId);
        $assignment->setRelation('discordActorResponse', $currentResponse);

        return [
            'assignment' => $assignment,
            'response' => $response,
            'reason' => $reason,
            'resource_version' => $this->resourceVersion($assignment, $currentResponse),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirm(
        User $actor,
        int $assignmentId,
        array $payload,
        string $interactionId,
    ): MilcomAssignmentResponse {
        return DB::transaction(function () use ($actor, $assignmentId, $payload, $interactionId): MilcomAssignmentResponse {
            $actorNationId = $this->actorNationId($actor);

            if ((int) ($payload['assignment_id'] ?? 0) !== $assignmentId
                || (int) ($payload['actor_nation_id'] ?? 0) !== $actorNationId
                || ! is_string($payload['resource_version'] ?? null)) {
                throw $this->intentMismatch();
            }

            [$response, $reason] = $this->normalizedResponse(
                $payload['response'] ?? null,
                $payload['reason'] ?? null,
                staleIntent: true,
            );
            $assignment = $this->lockedLiveAssignment($actor, $assignmentId);

            if (! $assignment instanceof MilcomAssignment) {
                throw $this->staleAssignment();
            }

            $currentResponse = $this->currentResponse($assignmentId, lock: true);
            $resourceVersion = $this->resourceVersion($assignment, $currentResponse);

            if (! hash_equals((string) $payload['resource_version'], $resourceVersion)) {
                throw $this->staleAssignment();
            }

            $assignmentResponse = MilcomAssignmentResponse::query()->updateOrCreate(
                [
                    'assignment_id' => $assignmentId,
                ],
                [
                    'user_id' => $actor->id,
                    'nation_id' => $actorNationId,
                    'response' => $response,
                    'reason' => $reason,
                    'discord_interaction_id' => $interactionId,
                    'responded_at' => now(),
                ],
            );

            $this->audit->success(
                category: 'milcom',
                action: 'discord_milcom_v2_assignment_response_recorded',
                subject: $assignmentResponse,
                context: [
                    'assignment_type' => self::ASSIGNMENT_TYPE,
                    'assignment_id' => $assignmentId,
                    'nation_id' => $actorNationId,
                    'response' => $response,
                ],
                message: 'A Discord actor recorded a Milcom-v2 assignment response.',
                actorOverride: [
                    'type' => 'user',
                    'id' => (int) $actor->id,
                    'name' => (string) $actor->username,
                ],
            );

            return $assignmentResponse;
        }, attempts: 3);
    }

    /**
     * @param  array{
     *     assignment: MilcomAssignment,
     *     response: string,
     *     reason: string|null,
     *     resource_version: string
     * }  $preview
     * @return array{assignment_id: int, actor_nation_id: int, response: string, reason: string|null, resource_version: string}
     */
    public function intentPayload(User $actor, array $preview): array
    {
        return [
            'assignment_id' => (int) $preview['assignment']->id,
            'actor_nation_id' => $this->actorNationId($actor),
            'response' => (string) $preview['response'],
            'reason' => is_string($preview['reason']) ? $preview['reason'] : null,
            'resource_version' => (string) $preview['resource_version'],
        ];
    }

    private function liveAssignment(User $actor, int $assignmentId): ?MilcomAssignment
    {
        $assignment = MilcomAssignment::query()
            ->whereKey($assignmentId)
            ->where('friendly_nation_id', $this->actorNationId($actor))
            ->with([
                'objective:id,operation_id,target_nation_id,priority_tier,status,war_type,war_reason,deadline_at,discord_channel_id,updated_at',
                'objective.operation:id,name,type,status,metadata,updated_at',
                'objective.target:id,nation_name,leader_name,alliance_id,score,num_cities',
                'objective.target.alliance:id,name,acronym',
                'declaredWar:id,date,end_date,turns_left,att_resistance,def_resistance,winner_id,updated_at',
            ])
            ->first();

        return $assignment instanceof MilcomAssignment && $this->isLive($assignment)
            ? $assignment
            : null;
    }

    private function lockedLiveAssignment(User $actor, int $assignmentId): ?MilcomAssignment
    {
        $assignment = MilcomAssignment::query()
            ->whereKey($assignmentId)
            ->where('friendly_nation_id', $this->actorNationId($actor))
            ->lockForUpdate()
            ->first();

        if (! $assignment instanceof MilcomAssignment) {
            return null;
        }

        $objective = MilcomObjective::query()->lockForUpdate()->find($assignment->objective_id);
        $operation = $objective instanceof MilcomObjective
            ? MilcomOperation::query()->lockForUpdate()->find($objective->operation_id)
            : null;
        $war = $assignment->declared_war_id !== null
            ? War::query()->lockForUpdate()->find($assignment->declared_war_id)
            : null;

        if (! $objective instanceof MilcomObjective || ! $operation instanceof MilcomOperation) {
            return null;
        }

        $assignment->setRelation('objective', $objective->setRelation('operation', $operation));
        $assignment->setRelation('declaredWar', $war);

        return $this->isLive($assignment) ? $assignment : null;
    }

    private function isLive(MilcomAssignment $assignment): bool
    {
        $objective = $assignment->objective;
        $operation = $objective?->operation;
        $war = $assignment->declaredWar;

        return $assignment->status instanceof AssignmentStatus
            && $assignment->status->reservesCapacity()
            && $objective instanceof MilcomObjective
            && $objective->status->isOpen()
            && $operation instanceof MilcomOperation
            && ! $operation->status->isTerminal()
            && (! $war instanceof War || ($war->end_date === null && (int) $war->turns_left > 0));
    }

    private function currentResponse(int $assignmentId, bool $lock = false): ?MilcomAssignmentResponse
    {
        $query = MilcomAssignmentResponse::query()
            ->where('assignment_id', $assignmentId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return array{string, string|null} */
    private function normalizedResponse(mixed $response, mixed $reason, bool $staleIntent): array
    {
        if (! is_string($response) || ! in_array($response, self::RESPONSES, true)) {
            throw $staleIntent ? $this->intentMismatch() : new DiscordMilcomAssignmentResponseException(
                'invalid_milcom_assignment_response',
                'Response must be acknowledged or unavailable.',
                422,
            );
        }

        if ($response === 'acknowledged') {
            if ($reason !== null && $reason !== '') {
                throw $staleIntent ? $this->intentMismatch() : new DiscordMilcomAssignmentResponseException(
                    'invalid_milcom_assignment_response_reason',
                    'A reason is only accepted when the assignment is unavailable.',
                    422,
                );
            }

            return [$response, null];
        }

        if (! is_string($reason)) {
            throw $staleIntent ? $this->intentMismatch() : new DiscordMilcomAssignmentResponseException(
                'milcom_assignment_response_reason_required',
                'A reason is required when the assignment is unavailable.',
                422,
            );
        }

        $reason = trim($reason);
        if ($reason === '' || Str::length($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            throw $staleIntent ? $this->intentMismatch() : new DiscordMilcomAssignmentResponseException(
                'invalid_milcom_assignment_response_reason',
                'The unavailable reason must be plain text between 1 and 500 characters.',
                422,
            );
        }

        return [$response, $reason];
    }

    private function actorNationId(User $actor): int
    {
        if ($actor->disabled || $actor->verified_at === null || ! is_numeric($actor->nation_id)) {
            throw $this->intentMismatch();
        }

        $nationId = (int) $actor->nation_id;
        if ($nationId < 1) {
            throw $this->intentMismatch();
        }

        return $nationId;
    }

    /** @throws JsonException */
    private function resourceVersion(
        MilcomAssignment $assignment,
        ?MilcomAssignmentResponse $currentResponse,
    ): string {
        $objective = $assignment->objective;
        $operation = $objective->operation;
        $war = $assignment->declaredWar;

        return hash('sha256', json_encode([
            'assignment' => [
                'id' => (int) $assignment->id,
                'nation_id' => (int) $assignment->friendly_nation_id,
                'status' => $assignment->status->value,
                'updated_at' => $assignment->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ],
            'objective' => [
                'id' => (int) $objective->id,
                'status' => $objective->status->value,
                'updated_at' => $objective->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ],
            'operation' => [
                'id' => (int) $operation->id,
                'status' => $operation->status->value,
                'updated_at' => $operation->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ],
            'war' => $war instanceof War ? [
                'id' => (int) $war->id,
                'end_date' => $war->end_date,
                'turns_left' => (int) $war->turns_left,
                'updated_at' => $war->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ] : null,
            'current_response' => $currentResponse instanceof MilcomAssignmentResponse ? [
                'id' => (int) $currentResponse->id,
                'nation_id' => (int) $currentResponse->nation_id,
                'response' => (string) $currentResponse->response,
                'reason_hash' => hash('sha256', (string) $currentResponse->reason),
                'updated_at' => $currentResponse->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function staleAssignment(): DiscordMilcomAssignmentResponseException
    {
        return new DiscordMilcomAssignmentResponseException(
            'milcom_assignment_response_stale',
            'This Milcom-v2 assignment changed or ended after the preview. Refresh before responding.',
            409,
            'Refresh the assignment and create a new response preview.',
        );
    }

    private function intentMismatch(): DiscordMilcomAssignmentResponseException
    {
        return new DiscordMilcomAssignmentResponseException(
            'milcom_assignment_response_intent_mismatch',
            'The response intent does not match this actor or assignment.',
            409,
            'Create a new response preview for this assignment.',
        );
    }
}
