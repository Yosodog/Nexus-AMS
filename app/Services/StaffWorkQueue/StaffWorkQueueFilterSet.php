<?php

namespace App\Services\StaffWorkQueue;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class StaffWorkQueueFilterSet
{
    public const URGENCIES = ['urgent', 'attention', 'routine'];

    public const SORTS = ['operational_priority', 'due', 'age', 'changed'];

    /** @deprecated Use $domainOwner. */
    public ?string $owner;

    public function __construct(
        public ?string $search = null,
        public ?string $type = null,
        public ?string $urgency = null,
        public ?string $domainOwner = null,
        public ?string $team = null,
        public ?string $priority = null,
        public ?string $severity = null,
        public ?string $attentionReason = null,
        public ?string $assignee = null,
        public ?string $requester = null,
        public ?string $nextActor = null,
        public ?bool $overdue = null,
        public ?bool $blocked = null,
        public ?bool $watched = null,
        public ?string $dueFrom = null,
        public ?string $dueTo = null,
        public ?string $changedFrom = null,
        public ?string $changedTo = null,
        public ?string $freshness = null,
        public string $sort = 'operational_priority',
        public string $direction = 'desc',
    ) {
        $this->owner = $this->domainOwner;
    }

    /** @deprecated Use $domainOwner. */
    public function owner(): ?string
    {
        return $this->domainOwner;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedTypes
     */
    public static function fromArray(array $input, array $allowedTypes): self
    {
        $validated = Validator::make($input, self::rules($allowedTypes))->validate();

        return new self(
            search: self::nullableString($validated['q'] ?? null),
            type: self::nullableString($validated['type'] ?? null),
            urgency: self::nullableString($validated['urgency'] ?? null),
            domainOwner: self::nullableString($validated['domain_owner'] ?? $validated['owner'] ?? null),
            team: self::nullableString($validated['team'] ?? null),
            priority: self::nullableString($validated['priority'] ?? null),
            severity: self::nullableString($validated['severity'] ?? null),
            attentionReason: self::nullableString($validated['attention_reason'] ?? null),
            assignee: self::nullableString($validated['assignee'] ?? null),
            requester: self::nullableString($validated['requester'] ?? null),
            nextActor: self::nullableString($validated['next_actor'] ?? null),
            overdue: array_key_exists('overdue', $validated) ? (bool) $validated['overdue'] : null,
            blocked: array_key_exists('blocked', $validated) ? (bool) $validated['blocked'] : null,
            watched: array_key_exists('watched', $validated) ? (bool) $validated['watched'] : null,
            dueFrom: self::nullableString($validated['due_from'] ?? null),
            dueTo: self::nullableString($validated['due_to'] ?? null),
            changedFrom: self::nullableString($validated['changed_from'] ?? null),
            changedTo: self::nullableString($validated['changed_to'] ?? null),
            freshness: self::nullableString($validated['freshness'] ?? null),
            sort: (string) ($validated['sort'] ?? 'operational_priority'),
            direction: (string) ($validated['direction'] ?? 'desc'),
        );
    }

    /**
     * @param  list<string>  $allowedTypes
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $allowedTypes): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in($allowedTypes)],
            'urgency' => ['nullable', 'string', Rule::in(self::URGENCIES)],
            'owner' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9:_-]+\z/i'],
            'domain_owner' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9:_-]+\z/i'],
            'team' => ['nullable', 'string', Rule::in(array_keys((array) config('operations.teams', [])))],
            'priority' => ['nullable', 'string', Rule::in(['p0', 'p1', 'p2', 'p3'])],
            'severity' => ['nullable', 'string', Rule::in(['critical', 'high', 'moderate', 'low', 'unknown'])],
            'attention_reason' => ['nullable', 'string', Rule::in([
                'overdue', 'due_soon', 'blocked', 'failed_delivery', 'critical_gap',
                'unassigned_staff', 'recent_change', 'stale_source', 'escalated', 'aged',
            ])],
            'assignee' => ['nullable', 'string', 'max:100', 'regex:/\A(?:me|unassigned|[1-9][0-9]*)\z/'],
            'requester' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9:_-]+\z/i'],
            'next_actor' => ['nullable', 'string', Rule::in(['staff', 'requester', 'participant', 'system'])],
            'overdue' => ['nullable', 'boolean'],
            'blocked' => ['nullable', 'boolean'],
            'watched' => ['nullable', 'boolean'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'changed_from' => ['nullable', 'date'],
            'changed_to' => ['nullable', 'date', 'after_or_equal:changed_from'],
            'freshness' => ['nullable', 'string', Rule::in(['fresh', 'aging', 'stale', 'unknown'])],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null
            || $this->type !== null
            || $this->urgency !== null
            || $this->domainOwner !== null
            || $this->team !== null
            || $this->priority !== null
            || $this->severity !== null
            || $this->attentionReason !== null
            || $this->assignee !== null
            || $this->requester !== null
            || $this->nextActor !== null
            || $this->overdue !== null
            || $this->blocked !== null
            || $this->watched !== null
            || $this->dueFrom !== null
            || $this->dueTo !== null
            || $this->changedFrom !== null
            || $this->changedTo !== null
            || $this->freshness !== null;
    }

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->search,
            'type' => $this->type,
            'urgency' => $this->urgency,
            'domain_owner' => $this->domainOwner,
            'team' => $this->team,
            'priority' => $this->priority,
            'severity' => $this->severity,
            'attention_reason' => $this->attentionReason,
            'assignee' => $this->assignee,
            'requester' => $this->requester,
            'next_actor' => $this->nextActor,
            'overdue' => $this->overdue,
            'blocked' => $this->blocked,
            'watched' => $this->watched,
            'due_from' => $this->dueFrom,
            'due_to' => $this->dueTo,
            'changed_from' => $this->changedFrom,
            'changed_to' => $this->changedTo,
            'freshness' => $this->freshness,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
