<?php

namespace App\Services\Discord;

use App\Models\User;
use App\Services\StaffWorkQueue\OperationsCoordinationReconciler;
use App\Services\StaffWorkQueue\OperationsCoordinationService;
use App\Services\StaffWorkQueue\OperationsReadStore;
use App\Services\StaffWorkQueue\StaffWorkQueueFilterSet;
use App\Services\StaffWorkQueue\StaffWorkQueueQuery;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class DiscordOperationsReadProvider
{
    public function __construct(
        private OperationsReadStore $readStore,
        private StaffWorkQueueQuery $query,
        private OperationsCoordinationReconciler $reconciler,
        private OperationsCoordinationService $coordination,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginate(User $actor, array $input): array
    {
        $this->authorizeActor($actor);
        $projection = $this->readStore->forUser($actor);

        if ($projection['types'] === []) {
            throw new AuthorizationException('This actor cannot view any Operations sources.');
        }

        $projection['items'] = $this->reconciler->overlay($projection['items'], $actor);
        $filters = StaffWorkQueueFilterSet::fromArray($input, array_keys($projection['types']));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(
            max(1, (int) ($input['per_page'] ?? config('operations.pagination.discord_default', 25))),
            (int) config('operations.pagination.maximum', 100),
        );
        $paginator = $this->query->paginate(
            items: $projection['items'],
            filters: $filters,
            page: $page,
            perPage: $perPage,
            queryParameters: $filters->toArray(),
        );

        return [
            'items' => array_map(
                fn (array $item): array => $this->withCapabilities($actor, $item),
                array_values($paginator->items()),
            ),
            'meta' => [
                'provider' => 'nexus_operations',
                'projection_schema_version' => (int) ($projection['schema'] ?? 2),
                'complete' => (bool) $projection['complete'],
                'generated_at' => $projection['generated_at'],
                'authorized_total' => (int) $projection['total'],
                'authorized_sources' => $projection['types'],
                'sources' => $this->sourceMetadata($projection),
                'unavailable_sources' => $this->unavailableSources($projection),
                'filters' => $filters->toArray(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(User $actor, string $workKey): ?array
    {
        $this->authorizeActor($actor);
        $item = $this->readStore->findForUser($actor, $workKey);

        if ($item === null) {
            return null;
        }

        $item = $this->reconciler->overlay([$item], $actor)[0];
        $paginator = $this->query->paginate(
            items: [$item],
            filters: new StaffWorkQueueFilterSet,
            page: 1,
            perPage: 1,
            queryParameters: [],
        );

        $item = $paginator->items()[0] ?? null;

        return is_array($item) ? $this->withCapabilities($actor, $item) : null;
    }

    /** @param  array<string, mixed>  $projection @return array<string, array<string, mixed>> */
    private function sourceMetadata(array $projection): array
    {
        return collect($projection['types'])
            ->mapWithKeys(function (string $label, string $type) use ($projection): array {
                $source = (array) ($projection['sources'][$type] ?? []);

                return [$type => [
                    'label' => $label,
                    'team' => $source['team_key'] ?? null,
                    'item_count' => array_key_exists($type, $projection['counts'])
                        ? (int) $projection['counts'][$type]
                        : null,
                    'freshness' => $source['freshness'] ?? 'unknown',
                    'complete' => (bool) ($source['complete'] ?? false),
                    'truncated' => (bool) ($source['truncated'] ?? false),
                    'unavailable' => isset($projection['failures'][$type]),
                    'projected_at' => $source['projected_at'] ?? null,
                    'source_observed_at' => $source['source_observed_at'] ?? null,
                    'upstream_observed_at' => $source['upstream_observed_at'] ?? null,
                    'stale_after' => $source['stale_after'] ?? null,
                    'warnings' => array_values((array) ($source['warnings'] ?? [])),
                ]];
            })
            ->all();
    }

    /** @param  array<string, mixed>  $projection @return list<array{type: string, label: string}> */
    private function unavailableSources(array $projection): array
    {
        return collect($projection['failures'])
            ->map(fn (array $failure, string $type): array => [
                'type' => $type,
                'label' => (string) ($failure['label'] ?? $projection['types'][$type] ?? $type),
            ])
            ->values()
            ->all();
    }

    private function authorizeActor(User $actor): void
    {
        if (! $actor->is_admin || $actor->disabled || $actor->verified_at === null) {
            throw new AuthorizationException('Discord Operations requires an active Nexus administrator.');
        }
    }

    /** @param  array<string, mixed>  $item @return array<string, mixed> */
    private function withCapabilities(User $actor, array $item): array
    {
        $item['operations_capabilities'] = $this->coordination->capabilities($actor, $item);

        return $item;
    }
}
