<?php

namespace App\Services\StaffWorkQueue;

use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class OperationsCoordinationService
{
    public const CAPABILITY_CLAIM = 'operations.claim';

    public const CAPABILITY_RELEASE = 'operations.release';

    public function __construct(private readonly OperationsReadStore $readStore) {}

    /**
     * @return array<string, mixed>
     */
    public function claim(
        User $actor,
        string $workKey,
        string $occurrenceKey,
        string $sourceRevision,
        ?int $lockVersion,
        string $idempotencyKey,
    ): array {
        $this->authorizeActor($actor, requireCoordinate: true);
        $item = $this->readStore->findForUser($actor, $workKey);
        $invalid = $this->validateProjectedItem($item, $occurrenceKey, $sourceRevision);

        if ($invalid !== null) {
            return $invalid;
        }

        /** @var array<string, mixed> $item */
        return $this->retryOnUniqueConstraint(function () use (
            $actor,
            $item,
            $lockVersion,
            $idempotencyKey,
        ): array {
            return DB::transaction(function () use (
                $actor,
                $item,
                $lockVersion,
                $idempotencyKey,
            ): array {
                $row = $this->activeRow((string) $item['work_key']);
                $created = false;

                if ($row === null) {
                    if ($this->closedOccurrenceExists(
                        (string) $item['work_key'],
                        (string) $item['occurrence_key'],
                    )) {
                        return $this->error(
                            'work_item_changed',
                            'This item is no longer pending. Refresh before continuing.',
                            409,
                        );
                    }

                    if ($lockVersion !== null) {
                        return $this->error(
                            'coordination_conflict',
                            'Someone else changed this item. Refresh and try again.',
                            409,
                        );
                    }

                    $row = $this->createCoordination($item);
                    $created = true;
                    $this->event($row, 'discovered', $item);
                }

                if ($row->occurrence_key !== $item['occurrence_key']) {
                    return $this->error(
                        'work_item_changed',
                        'This item was reopened or replaced. Refresh before assigning it.',
                        409,
                    );
                }

                if ($this->hasUnexpiredAssignmentToAnotherActor($row, $actor)) {
                    return $this->alreadyClaimed($row);
                }

                if (! $created && ($lockVersion === null || $row->lock_version !== $lockVersion)) {
                    return $this->error(
                        'coordination_conflict',
                        'Someone else changed this item. Refresh and try again.',
                        409,
                        ['lock_version' => $row->lock_version],
                    );
                }

                $this->refreshSourceState($row, $item);
                $expiredAssigneeId = $this->clearExpiredAssignment($row);

                if ($expiredAssigneeId !== null) {
                    $this->event(
                        $row,
                        'assignment_expired',
                        $item,
                        metadata: ['reason' => 'assignment_ttl_elapsed'],
                        actorUserId: $actor->id,
                        subjectUserId: $expiredAssigneeId,
                    );
                }

                if ($row->assignee_user_id !== null) {
                    if ($row->assignee_user_id === $actor->id) {
                        return $this->success($row, $actor, idempotent: true);
                    }

                    return $this->alreadyClaimed($row);
                }

                $now = now();
                $row->forceFill([
                    'assignee_user_id' => $actor->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => $now,
                    'assignment_expires_at' => $now->copy()->addMinutes($this->assignmentTtlMinutes()),
                    'first_action_at' => $row->first_action_at ?? $now,
                    'last_activity_at' => $now,
                    'lock_version' => $row->lock_version + 1,
                ])->save();
                $row->setRelation('assignee', $actor);
                $this->event(
                    $row,
                    'claimed',
                    $item,
                    actorUserId: $actor->id,
                    subjectUserId: $actor->id,
                    correlationId: $idempotencyKey,
                    idempotencyKey: $this->eventIdempotencyKey('claim', $idempotencyKey),
                );

                return $this->success($row, $actor);
            }, attempts: 3);
        });
    }

    /** @return array<string, mixed> */
    public function release(
        User $actor,
        string $workKey,
        string $occurrenceKey,
        string $sourceRevision,
        int $lockVersion,
        string $idempotencyKey,
    ): array {
        $this->authorizeActor($actor);
        $item = $this->readStore->findForUser($actor, $workKey);
        $invalid = $this->validateProjectedItem($item, $occurrenceKey, $sourceRevision);

        if ($invalid !== null) {
            return $invalid;
        }

        /** @var array<string, mixed> $item */
        return DB::transaction(function () use (
            $actor,
            $item,
            $lockVersion,
            $idempotencyKey,
        ): array {
            $row = $this->activeRow((string) $item['work_key']);

            if ($row === null || $row->occurrence_key !== $item['occurrence_key']) {
                return $this->error(
                    'work_item_changed',
                    'This item is no longer active.',
                    409,
                );
            }

            if ($row->lock_version !== $lockVersion) {
                return $this->error(
                    'coordination_conflict',
                    'Someone else changed this item. Refresh and try again.',
                    409,
                    ['lock_version' => $row->lock_version],
                );
            }

            $this->refreshSourceState($row, $item);
            $expiredAssigneeId = $this->clearExpiredAssignment($row);

            if ($expiredAssigneeId !== null) {
                $row->forceFill([
                    'last_activity_at' => now(),
                    'lock_version' => $row->lock_version + 1,
                ])->save();
                $this->event(
                    $row,
                    'assignment_expired',
                    $item,
                    metadata: ['reason' => 'assignment_ttl_elapsed'],
                    actorUserId: $actor->id,
                    subjectUserId: $expiredAssigneeId,
                );

                return $this->error(
                    'assignment_expired',
                    'Your assignment expired before it could be released.',
                    409,
                    ['lock_version' => $row->lock_version],
                );
            }

            if ($row->assignee_user_id === null) {
                return $this->error('not_claimed', 'The work item is not currently assigned.', 409);
            }

            $canManage = Gate::forUser($actor)->allows((string) config('operations.permissions.manage'));

            if ($row->assignee_user_id !== $actor->id && ! $canManage) {
                throw new AuthorizationException('Only the person assigned to this item or an operations manager can release it.');
            }

            $releasedAssigneeId = $row->assignee_user_id;
            $row->forceFill([
                'assignee_user_id' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
                'assignment_expires_at' => null,
                'last_activity_at' => now(),
                'lock_version' => $row->lock_version + 1,
            ])->save();
            $row->unsetRelation('assignee');
            $this->event(
                $row,
                'released',
                $item,
                actorUserId: $actor->id,
                subjectUserId: $releasedAssigneeId,
                correlationId: $idempotencyKey,
                idempotencyKey: $this->eventIdempotencyKey('release', $idempotencyKey),
            );

            return $this->success($row, $actor);
        }, attempts: 3);
    }

    /** @param  array<string, mixed>  $item @return list<string> */
    public function capabilities(User $actor, array $item): array
    {
        if (! (bool) config('operations.features.coordination', false)
            || ! $this->sourceCanMutate($item)) {
            return [];
        }

        $gate = Gate::forUser($actor);
        $canCoordinate = $gate->allows((string) config('operations.permissions.coordinate'));
        $canManage = $gate->allows((string) config('operations.permissions.manage'));
        $assigneeId = data_get($item, 'coordination.assignee.id');

        if ($assigneeId === null) {
            return $canCoordinate ? [self::CAPABILITY_CLAIM] : [];
        }

        return ((int) $assigneeId === $actor->id || $canManage)
            ? [self::CAPABILITY_RELEASE]
            : [];
    }

    private function authorizeActor(User $actor, bool $requireCoordinate = false): void
    {
        if (! (bool) config('operations.features.coordination', false)) {
            throw new AuthorizationException('Work assignment is not enabled.');
        }

        if (! $actor->is_admin || $actor->disabled || $actor->verified_at === null) {
            throw new AuthorizationException('You need an active administrator account to assign work.');
        }

        $gate = Gate::forUser($actor);

        if ($requireCoordinate && ! $gate->allows((string) config('operations.permissions.coordinate'))) {
            throw new AuthorizationException('You do not have permission to assign this work.');
        }

        if (! $requireCoordinate
            && ! $gate->allows((string) config('operations.permissions.coordinate'))
            && ! $gate->allows((string) config('operations.permissions.manage'))) {
            throw new AuthorizationException('You do not have permission to assign this work.');
        }
    }

    /** @param  array<string, mixed>|null  $item @return array<string, mixed>|null */
    private function validateProjectedItem(?array $item, string $occurrenceKey, string $sourceRevision): ?array
    {
        if ($item === null) {
            return $this->error(
                'not_found',
                'This work item was not found or you do not have access to it.',
                404,
            );
        }

        if (! hash_equals((string) ($item['occurrence_key'] ?? ''), $occurrenceKey)
            || ! hash_equals((string) ($item['source_fingerprint'] ?? ''), $sourceRevision)) {
            return $this->error(
                'work_item_changed',
                'The work item changed after it was displayed. Refresh and try again.',
                409,
            );
        }

        if (! $this->sourceCanMutate($item)) {
            return $this->error(
                'source_unavailable',
                'This item cannot be assigned until its information is fully available and up to date.',
                409,
            );
        }

        return null;
    }

    /** @param  array<string, mixed>  $item */
    private function sourceCanMutate(array $item): bool
    {
        return ($item['freshness'] ?? 'unknown') !== 'stale'
            && (bool) ($item['source_complete'] ?? false)
            && ! (bool) ($item['source_truncated'] ?? false);
    }

    /** @param  array<string, mixed>  $item */
    private function createCoordination(array $item): OperationsWorkCoordination
    {
        return OperationsWorkCoordination::query()->create([
            'work_key' => $item['work_key'],
            'occurrence_key' => $item['occurrence_key'],
            'source_type' => $item['source_type'] ?? $item['type'],
            'source_fingerprint' => $item['source_fingerprint'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'source_updated_at' => $item['source_updated_at'] ?? null,
        ]);
    }

    private function activeRow(string $workKey): ?OperationsWorkCoordination
    {
        return OperationsWorkCoordination::query()
            ->active()
            ->where('work_key', $workKey)
            ->lockForUpdate()
            ->first();
    }

    private function closedOccurrenceExists(string $workKey, string $occurrenceKey): bool
    {
        return OperationsWorkCoordination::query()
            ->where('work_key', $workKey)
            ->where('occurrence_key', $occurrenceKey)
            ->whereNull('active_key')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /** @param  array<string, mixed>  $item */
    private function refreshSourceState(OperationsWorkCoordination $row, array $item): void
    {
        $changed = ! hash_equals($row->source_fingerprint, (string) $item['source_fingerprint']);
        $row->forceFill([
            'source_fingerprint' => $item['source_fingerprint'],
            'last_seen_at' => now(),
            'source_updated_at' => $item['source_updated_at'] ?? null,
        ])->save();

        if ($changed) {
            $this->event($row, 'changed', $item);
        }
    }

    private function clearExpiredAssignment(OperationsWorkCoordination $row): ?int
    {
        if ($row->assignee_user_id === null
            || ($row->assignment_expires_at !== null && $row->assignment_expires_at->isFuture())) {
            return null;
        }

        $assigneeId = $row->assignee_user_id;
        $row->forceFill([
            'assignee_user_id' => null,
            'assigned_by_user_id' => null,
            'assigned_at' => null,
            'assignment_expires_at' => null,
        ]);

        return $assigneeId;
    }

    private function hasUnexpiredAssignmentToAnotherActor(
        OperationsWorkCoordination $row,
        User $actor,
    ): bool {
        return $row->assignee_user_id !== null
            && $row->assignee_user_id !== $actor->id
            && $row->assignment_expires_at !== null
            && $row->assignment_expires_at->isFuture();
    }

    /** @return array<string, mixed> */
    private function alreadyClaimed(OperationsWorkCoordination $row): array
    {
        $assignee = User::query()->find($row->assignee_user_id);

        return $this->error(
            'already_claimed',
            'This work item is already assigned to someone else.',
            409,
            ['assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'label' => $assignee->name,
            ]],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int|string|bool|null>  $metadata
     */
    private function event(
        OperationsWorkCoordination $row,
        string $type,
        array $item,
        array $metadata = [],
        ?int $actorUserId = null,
        ?int $subjectUserId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
    ): OperationsWorkEvent {
        return OperationsWorkEvent::query()->create([
            'coordination_id' => $row->id,
            'work_key' => $row->work_key,
            'occurrence_key' => $row->occurrence_key,
            'source_type' => $row->source_type,
            'team_key' => $row->team_override_key
                ?? $item['team_key']
                ?? config("operations.sources.{$row->source_type}.team", 'systems'),
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function success(
        OperationsWorkCoordination $row,
        User $actor,
        bool $idempotent = false,
    ): array {
        $row->loadMissing('assignee:id,name');

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'work_key' => $row->work_key,
                'occurrence_key' => $row->occurrence_key,
                'coordination' => [
                    'assignee' => $row->assignee === null ? null : [
                        'id' => $row->assignee->id,
                        'label' => $row->assignee->name,
                        'is_me' => $row->assignee->is($actor),
                    ],
                    'assigned_at' => $row->assigned_at?->toIso8601String(),
                    'assignment_expires_at' => $row->assignment_expires_at?->toIso8601String(),
                    'lock_version' => $row->lock_version,
                ],
            ],
            'meta' => ['idempotent' => $idempotent],
        ];
    }

    /** @param  array<string, mixed>  $details @return array<string, mixed> */
    private function error(string $code, string $message, int $status, array $details = []): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function assignmentTtlMinutes(): int
    {
        return min(max((int) config('operations.coordination.assignment_ttl_minutes', 30), 5), 240);
    }

    private function eventIdempotencyKey(string $action, string $key): string
    {
        return 'operations:'.$action.':'.hash('sha256', $key);
    }

    /** @template TResult @param  callable(): TResult  $callback @return TResult */
    private function retryOnUniqueConstraint(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Operations claim retry loop exited unexpectedly.');
    }
}
