<?php

namespace App\Services\StaffWorkQueue;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
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

        $sorted = $filters->direction === 'asc'
            ? $filtered->sortBy('age_seconds')
            : $filtered->sortByDesc('age_seconds');

        $page = max(1, $page);
        $perPage = min(max($perPage, 1), 100);

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            [
                'path' => route('admin.work-queue.index'),
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
        return ['unassigned' => 'Unassigned'] + collect($items)
            ->filter(fn (array $item): bool => filled($item['owner_key'] ?? null) && filled($item['owner_label'] ?? null))
            ->mapWithKeys(fn (array $item): array => [(string) $item['owner_key'] => (string) $item['owner_label']])
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

        $item['age_seconds'] = max(0, $createdAt->diffInSeconds($now, false));
        $item['urgency'] = $this->urgency(
            ageSeconds: (int) $item['age_seconds'],
            dueAt: $dueAt,
            now: $now,
            hint: is_string($item['urgency_hint'] ?? null) ? $item['urgency_hint'] : null,
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

        if ($filters->owner !== null) {
            if ($filters->owner === 'unassigned' && filled($item['owner_key'] ?? null)) {
                return false;
            }

            if ($filters->owner !== 'unassigned' && $item['owner_key'] !== $filters->owner) {
                return false;
            }
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
            $item['status_label'] ?? null,
            ...((array) ($item['search_terms'] ?? [])),
        ])->filter()->implode(' ');

        return Str::contains(Str::lower($haystack), Str::lower($filters->search));
    }

    private function urgency(
        int $ageSeconds,
        ?CarbonImmutable $dueAt,
        CarbonImmutable $now,
        ?string $hint,
    ): string {
        if ($dueAt !== null && $dueAt->lessThanOrEqualTo($now)) {
            return 'urgent';
        }

        if ($hint === 'urgent' || $ageSeconds >= 72 * 60 * 60) {
            return 'urgent';
        }

        if ($hint === 'attention'
            || ($dueAt !== null && $dueAt->lessThanOrEqualTo($now->addHours(6)))
            || $ageSeconds >= 24 * 60 * 60) {
            return 'attention';
        }

        return 'routine';
    }
}
