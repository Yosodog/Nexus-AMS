<?php

namespace App\Services\StaffWorkQueue;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class StaffWorkQueueQuery
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $queryParameters
     */
    public function paginate(
        array $items,
        StaffWorkQueueFilterSet $filters,
        int $page,
        int $perPage,
        array $queryParameters,
    ): LengthAwarePaginator {
        $now = CarbonImmutable::now();
        $filtered = collect($items)
            ->map(fn (array $item): array => $this->enrich($item, $now))
            ->filter(fn (array $item): bool => $this->matches($item, $filters));

        $sorted = $filtered->sort(fn (array $left, array $right): int => $this->compare($left, $right, $filters));

        $page = max(1, $page);
        $perPage = min(max($perPage, 1), (int) config('operations.pagination.maximum', 100));

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            [
                'path' => Route::has('admin.operations.queue')
                    ? route('admin.operations.queue')
                    : route('admin.work-queue.index'),
                'query' => collect($queryParameters)->except('page')->all(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    public function ownerOptions(array $items): array
    {
        return ['unassigned' => 'No domain owner'] + collect($items)
            ->map(function (array $item): ?array {
                $key = $item['domain_owner']['key'] ?? $item['owner_key'] ?? null;
                $label = $item['domain_owner']['label'] ?? $item['owner_label'] ?? null;

                return filled($key) && filled($label) ? [(string) $key => (string) $label] : null;
            })
            ->filter()
            ->collapse()
            ->sort()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrich(array $item, CarbonImmutable $now): array
    {
        $createdAt = CarbonImmutable::parse((string) $item['created_at']);
        $dueAt = filled($item['due_at'] ?? null)
            ? CarbonImmutable::parse((string) $item['due_at'])
            : null;
        $operationalTargetAt = filled($item['operational_target_at'] ?? null)
            ? CarbonImmutable::parse((string) $item['operational_target_at'])
            : null;
        $effectiveDueAt = $dueAt ?? $operationalTargetAt;

        $item['age_seconds'] = max(0, $createdAt->diffInSeconds($now, false));
        $item['is_overdue'] = $effectiveDueAt?->lessThanOrEqualTo($now) ?? false;
        $item['is_due_soon'] = $effectiveDueAt !== null
            && ! $item['is_overdue']
            && $effectiveDueAt->lessThanOrEqualTo($now->addSeconds((int) config('operations.attention.due_soon_seconds', 21600)));
        $item['attention_reasons'] = $this->attentionReasons($item);
        $item['urgency'] = $this->urgency(
            ageSeconds: (int) $item['age_seconds'],
            dueAt: $effectiveDueAt,
            now: $now,
            hint: is_string($item['urgency_hint'] ?? null) ? $item['urgency_hint'] : null,
            priority: (string) ($item['priority'] ?? 'p3'),
            severity: (string) ($item['severity'] ?? 'unknown'),
            reasons: $item['attention_reasons'],
        );

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matches(array $item, StaffWorkQueueFilterSet $filters): bool
    {
        if ($filters->type !== null && $item['type'] !== $filters->type) {
            return false;
        }

        if ($filters->urgency !== null && $item['urgency'] !== $filters->urgency) {
            return false;
        }

        if ($filters->domainOwner !== null) {
            $domainOwner = $item['domain_owner']['key'] ?? $item['owner_key'] ?? null;

            if ($filters->domainOwner === 'unassigned' && filled($domainOwner)) {
                return false;
            }

            if ($filters->domainOwner !== 'unassigned' && $domainOwner !== $filters->domainOwner) {
                return false;
            }
        }

        if ($filters->team !== null && ($item['team_key'] ?? null) !== $filters->team) {
            return false;
        }

        if ($filters->priority !== null && ($item['priority'] ?? null) !== $filters->priority) {
            return false;
        }

        if ($filters->severity !== null && ($item['severity'] ?? null) !== $filters->severity) {
            return false;
        }

        if ($filters->attentionReason !== null && ! in_array($filters->attentionReason, (array) ($item['attention_reasons'] ?? []), true)) {
            return false;
        }

        if ($filters->assignee !== null) {
            $assigneeId = $item['coordination']['assignee']['id'] ?? $item['assignee']['id'] ?? null;

            if ($filters->assignee === 'unassigned' && $assigneeId !== null) {
                return false;
            }

            if ($filters->assignee === 'me' && ! (bool) ($item['coordination']['assignee']['is_me'] ?? false)) {
                return false;
            }

            if (ctype_digit($filters->assignee) && (string) $assigneeId !== $filters->assignee) {
                return false;
            }
        }

        if ($filters->requester !== null && ($item['requester']['key'] ?? null) !== $filters->requester) {
            return false;
        }

        if ($filters->nextActor !== null && ($item['next_actor'] ?? null) !== $filters->nextActor) {
            return false;
        }

        if ($filters->overdue !== null && (bool) ($item['is_overdue'] ?? false) !== $filters->overdue) {
            return false;
        }

        if ($filters->blocked !== null && (bool) ($item['blocked'] ?? false) !== $filters->blocked) {
            return false;
        }

        if ($filters->watched !== null && (bool) ($item['coordination']['watched'] ?? false) !== $filters->watched) {
            return false;
        }

        if ($filters->freshness !== null && ($item['freshness'] ?? 'unknown') !== $filters->freshness) {
            return false;
        }

        if (! $this->withinRange($item['due_at'] ?? null, $filters->dueFrom, $filters->dueTo)) {
            return false;
        }

        if (! $this->withinRange($item['source_updated_at'] ?? null, $filters->changedFrom, $filters->changedTo)) {
            return false;
        }

        if ($filters->search === null) {
            return true;
        }

        $haystack = collect([
            $item['key'] ?? null,
            $item['id'] ?? null,
            $item['type_label'] ?? null,
            $item['subject'] ?? null,
            $item['owner_label'] ?? null,
            $item['domain_owner']['label'] ?? null,
            $item['requester']['label'] ?? null,
            $item['waiting_on']['label'] ?? null,
            $item['team_key'] ?? null,
            $item['summary'] ?? null,
            $item['status_label'] ?? null,
            ...((array) ($item['search_terms'] ?? [])),
            ...array_values((array) ($item['safe_facts'] ?? [])),
        ])->filter()->implode(' ');

        return Str::contains(Str::lower($haystack), Str::lower($filters->search));
    }

    private function urgency(
        int $ageSeconds,
        ?CarbonImmutable $dueAt,
        CarbonImmutable $now,
        ?string $hint,
        string $priority,
        string $severity,
        array $reasons,
    ): string {
        if ($dueAt !== null && $dueAt->lessThanOrEqualTo($now)) {
            return 'urgent';
        }

        if ($hint === 'urgent'
            || in_array($priority, ['p0', 'p1'], true)
            || in_array($severity, ['critical'], true)
            || array_intersect($reasons, ['overdue', 'escalated', 'failed_delivery', 'critical_gap']) !== []
            || $ageSeconds >= (int) config('operations.attention.overdue_after_seconds', 259200)) {
            return 'urgent';
        }

        if ($hint === 'attention'
            || $priority === 'p2'
            || $severity === 'high'
            || array_intersect($reasons, ['due_soon', 'blocked', 'aged', 'stale_source']) !== []
            || ($dueAt !== null && $dueAt->lessThanOrEqualTo($now->addHours(6)))
            || $ageSeconds >= (int) config('operations.attention.attention_after_seconds', 86400)) {
            return 'attention';
        }

        return 'routine';
    }

    /** @param  array<string, mixed>  $item */
    private function attentionReasons(array $item): array
    {
        $reasons = array_values(array_map('strval', (array) ($item['attention_reasons'] ?? [])));

        if ((bool) ($item['is_overdue'] ?? false)) {
            $reasons[] = 'overdue';
        } elseif ((bool) ($item['is_due_soon'] ?? false)) {
            $reasons[] = 'due_soon';
        }

        if ((bool) ($item['blocked'] ?? false)) {
            $reasons[] = 'blocked';
        }

        $assigneeId = $item['coordination']['assignee']['id'] ?? $item['assignee']['id'] ?? null;

        if ((bool) ($item['requires_staff_action'] ?? true)
            && ($item['next_actor'] ?? 'staff') === 'staff'
            && $assigneeId === null) {
            $reasons[] = 'unassigned_staff';
        }

        $hasNativeAttention = array_intersect($reasons, [
            'overdue', 'due_soon', 'blocked', 'failed_delivery', 'critical_gap', 'escalated',
        ]) !== [];

        if (! $hasNativeAttention
            && (bool) ($item['requires_staff_action'] ?? true)
            && ($item['next_actor'] ?? 'staff') === 'staff'
            && (int) ($item['age_seconds'] ?? 0) >= (int) config('operations.attention.attention_after_seconds', 86400)) {
            $reasons[] = 'aged';
        }

        return array_values(array_unique($reasons));
    }

    private function withinRange(mixed $value, ?string $from, ?string $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }

        if (! is_string($value) || $value === '') {
            return false;
        }

        $date = CarbonImmutable::parse($value);

        return ($from === null || $date->greaterThanOrEqualTo(CarbonImmutable::parse($from)->startOfDay()))
            && ($to === null || $date->lessThanOrEqualTo(CarbonImmutable::parse($to)->endOfDay()));
    }

    /** @param  array<string, mixed>  $left @param  array<string, mixed>  $right */
    private function compare(array $left, array $right, StaffWorkQueueFilterSet $filters): int
    {
        if ($filters->sort === 'operational_priority') {
            $comparison = $this->priorityTuple($left) <=> $this->priorityTuple($right);

            return $filters->direction === 'asc' ? -$comparison : $comparison;
        }

        $leftValue = $this->sortValue($left, $filters->sort);
        $rightValue = $this->sortValue($right, $filters->sort);
        $comparison = $leftValue <=> $rightValue;

        if ($comparison === 0) {
            $comparison = (string) ($left['work_key'] ?? $left['key'] ?? '')
                <=> (string) ($right['work_key'] ?? $right['key'] ?? '');
        }

        return $filters->direction === 'desc' ? -$comparison : $comparison;
    }

    /** @param  array<string, mixed>  $item @return list<int|string> */
    private function priorityTuple(array $item): array
    {
        $reasons = (array) ($item['attention_reasons'] ?? []);
        $priorityRanks = ['p0' => 0, 'p1' => 1, 'p2' => 2, 'p3' => 3];
        $severityRanks = ['critical' => 0, 'high' => 1, 'moderate' => 2, 'low' => 3, 'unknown' => 4];
        $due = filled($item['due_at'] ?? null)
            ? CarbonImmutable::parse((string) $item['due_at'])->getTimestamp()
            : PHP_INT_MAX;

        return [
            in_array('escalated', $reasons, true) ? 0 : 1,
            (bool) ($item['is_overdue'] ?? false) ? 0 : 1,
            $priorityRanks[$item['priority'] ?? 'p3'] ?? 3,
            $severityRanks[$item['severity'] ?? 'unknown'] ?? 4,
            (bool) ($item['blocked'] ?? false) || in_array('failed_delivery', $reasons, true) ? 0 : 1,
            $due,
            CarbonImmutable::parse((string) ($item['entered_queue_at'] ?? $item['created_at']))->getTimestamp(),
            (string) ($item['work_key'] ?? $item['key'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $item */
    private function sortValue(array $item, string $sort): int
    {
        return match ($sort) {
            'due' => filled($item['due_at'] ?? null)
                ? CarbonImmutable::parse((string) $item['due_at'])->getTimestamp()
                : PHP_INT_MAX,
            'changed' => CarbonImmutable::parse((string) ($item['source_updated_at'] ?? $item['created_at']))->getTimestamp(),
            default => (int) ($item['age_seconds'] ?? 0),
        };
    }
}
