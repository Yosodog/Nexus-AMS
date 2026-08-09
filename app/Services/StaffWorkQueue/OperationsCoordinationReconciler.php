<?php

namespace App\Services\StaffWorkQueue;

use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OperationsCoordinationReconciler
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function overlay(array $items, User $actor): array
    {
        if ($items === [] || ! Schema::hasTable((new OperationsWorkCoordination)->getTable())) {
            return array_map(fn (array $item): array => $this->withoutPersistedCoordination($item), $items);
        }

        $workKeys = collect($items)
            ->pluck('work_key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
        $coordination = OperationsWorkCoordination::query()
            ->active()
            ->whereIn('work_key', $workKeys)
            ->with('assignee:id,name')
            ->get()
            ->keyBy(fn (OperationsWorkCoordination $row): string => $row->work_key.'|'.$row->occurrence_key);

        return array_map(function (array $item) use ($coordination, $actor): array {
            $key = (string) ($item['work_key'] ?? $item['key'] ?? '').'|'.(string) ($item['occurrence_key'] ?? '');
            /** @var OperationsWorkCoordination|null $row */
            $row = $coordination->get($key);

            if ($row === null) {
                return $this->withoutPersistedCoordination($item);
            }

            $assignmentExpired = $row->assignee_user_id !== null
                && ($row->assignment_expires_at === null || $row->assignment_expires_at->isPast());
            $item['coordination'] = [
                'assignee' => $assignmentExpired || $row->assignee === null ? null : [
                    'id' => $row->assignee->id,
                    'label' => $row->assignee->name,
                    'is_me' => $row->assignee->is($actor),
                ],
                'assigned_at' => $assignmentExpired ? null : $row->assigned_at?->toIso8601String(),
                'assignment_expires_at' => $assignmentExpired
                    ? null
                    : $row->assignment_expires_at?->toIso8601String(),
                'assignment_expired' => $assignmentExpired,
                'lock_version' => $row->lock_version,
                'source_revision' => (string) ($item['source_fingerprint'] ?? ''),
            ];

            return $item;
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{discovered: int, changed: int, reopened: int, closed: int, expired: int, skipped_closure: bool}
     */
    public function reconcile(array $snapshot): array
    {
        $counts = [
            'discovered' => 0,
            'changed' => 0,
            'reopened' => 0,
            'closed' => 0,
            'expired' => 0,
            'skipped_closure' => true,
        ];
        $sourceType = (string) ($snapshot['type'] ?? '');
        $counts['expired'] = $this->expireAssignmentsForSource($sourceType);

        if (($snapshot['state'] ?? null) === 'failed') {
            return $counts;
        }

        $seenWorkKeys = [];

        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $workKey = (string) ($item['work_key'] ?? $item['key'] ?? '');

            if ($workKey === '' || ! filled($item['occurrence_key'] ?? null)) {
                continue;
            }

            $seenWorkKeys[] = $workKey;
            $result = $this->reconcileItem($item);

            foreach (['discovered', 'changed', 'reopened', 'closed', 'expired'] as $key) {
                $counts[$key] += $result[$key];
            }
        }

        $canClose = (bool) ($snapshot['complete'] ?? false)
            && ! (bool) ($snapshot['truncated'] ?? false)
            && ($snapshot['state'] ?? null) !== 'failed';

        if ($canClose) {
            $counts['closed'] += $this->closeMissing(
                $sourceType,
                array_values(array_unique($seenWorkKeys)),
            );
            $counts['skipped_closure'] = false;
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $item @return array{discovered: int, changed: int, reopened: int, closed: int, expired: int} */
    private function reconcileItem(array $item): array
    {
        return $this->retryOnUniqueConstraint(function () use ($item): array {
            return DB::transaction(function () use ($item): array {
                $workKey = (string) ($item['work_key'] ?? $item['key']);
                $occurrenceKey = (string) $item['occurrence_key'];
                $active = OperationsWorkCoordination::query()
                    ->active()
                    ->where('work_key', $workKey)
                    ->lockForUpdate()
                    ->first();
                $reopened = false;
                $closed = 0;

                if ($active !== null && $active->occurrence_key !== $occurrenceKey) {
                    $this->close($active, 'replaced_by_new_occurrence');
                    $active = null;
                    $reopened = true;
                    $closed = 1;
                }

                if ($active === null) {
                    $reopened = $reopened || OperationsWorkCoordination::query()
                        ->where('work_key', $workKey)
                        ->whereNotNull('closed_at')
                        ->exists();
                    $active = OperationsWorkCoordination::query()->create([
                        'work_key' => $workKey,
                        'occurrence_key' => $occurrenceKey,
                        'source_type' => (string) ($item['source_type'] ?? $item['type']),
                        'source_fingerprint' => (string) $item['source_fingerprint'],
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                        'source_updated_at' => $item['source_updated_at'] ?? null,
                    ]);
                    $this->event($active, $reopened ? 'reopened' : 'discovered', $item);

                    return [
                        'discovered' => $reopened ? 0 : 1,
                        'changed' => 0,
                        'reopened' => $reopened ? 1 : 0,
                        'closed' => $closed,
                        'expired' => 0,
                    ];
                }

                $expired = $this->expireAssignment($active, $item);
                $changed = ! hash_equals($active->source_fingerprint, (string) $item['source_fingerprint']);
                $active->forceFill([
                    'source_fingerprint' => (string) $item['source_fingerprint'],
                    'last_seen_at' => now(),
                    'source_updated_at' => $item['source_updated_at'] ?? null,
                ])->save();

                if ($changed) {
                    $this->event($active, 'changed', $item);
                }

                return [
                    'discovered' => 0,
                    'changed' => $changed ? 1 : 0,
                    'reopened' => 0,
                    'closed' => 0,
                    'expired' => $expired ? 1 : 0,
                ];
            }, attempts: 3);
        });
    }

    /** @param  list<string>  $seenWorkKeys */
    private function closeMissing(string $sourceType, array $seenWorkKeys): int
    {
        if ($sourceType === '') {
            return 0;
        }

        return DB::transaction(function () use ($sourceType, $seenWorkKeys): int {
            $query = OperationsWorkCoordination::query()
                ->active()
                ->where('source_type', $sourceType)
                ->orderBy('work_key')
                ->lockForUpdate();

            if ($seenWorkKeys !== []) {
                $query->whereNotIn('work_key', $seenWorkKeys);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $this->close($row, 'missing_from_complete_source');
            }

            return $rows->count();
        }, attempts: 3);
    }

    private function expireAssignmentsForSource(string $sourceType): int
    {
        if ($sourceType === '') {
            return 0;
        }

        return DB::transaction(function () use ($sourceType): int {
            $rows = OperationsWorkCoordination::query()
                ->active()
                ->where('source_type', $sourceType)
                ->whereNotNull('assignee_user_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('assignment_expires_at')
                        ->orWhere('assignment_expires_at', '<=', now());
                })
                ->orderBy('work_key')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $this->expireAssignment($row, []);
            }

            return $rows->count();
        }, attempts: 3);
    }

    private function close(OperationsWorkCoordination $row, string $reason): void
    {
        $assigneeId = $row->assignee_user_id;
        $row->forceFill([
            'assignee_user_id' => null,
            'assigned_by_user_id' => null,
            'assigned_at' => null,
            'assignment_expires_at' => null,
            'last_activity_at' => now(),
            'closed_at' => now(),
            'active_key' => null,
            'lock_version' => $row->lock_version + 1,
        ])->save();
        $this->event($row, 'closed', metadata: ['reason' => $reason], subjectUserId: $assigneeId);
    }

    /** @param  array<string, mixed>  $item */
    private function expireAssignment(OperationsWorkCoordination $row, array $item): bool
    {
        if ($row->assignee_user_id === null
            || ($row->assignment_expires_at !== null && $row->assignment_expires_at->isFuture())) {
            return false;
        }

        $assigneeId = $row->assignee_user_id;
        $row->forceFill([
            'assignee_user_id' => null,
            'assigned_by_user_id' => null,
            'assigned_at' => null,
            'assignment_expires_at' => null,
            'last_activity_at' => now(),
            'lock_version' => $row->lock_version + 1,
        ])->save();
        $this->event(
            $row,
            'assignment_expired',
            $item,
            metadata: ['reason' => 'assignment_ttl_elapsed'],
            subjectUserId: $assigneeId,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int|string|bool|null>  $metadata
     */
    private function event(
        OperationsWorkCoordination $row,
        string $type,
        array $item = [],
        array $metadata = [],
        ?int $actorUserId = null,
        ?int $subjectUserId = null,
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
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $item @return array<string, mixed> */
    private function withoutPersistedCoordination(array $item): array
    {
        $item['coordination'] = [
            'assignee' => null,
            'assigned_at' => null,
            'assignment_expires_at' => null,
            'assignment_expired' => false,
            'lock_version' => null,
            'source_revision' => (string) ($item['source_fingerprint'] ?? ''),
        ];

        return $item;
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

        throw new \LogicException('Operations reconciliation retry loop exited unexpectedly.');
    }
}
