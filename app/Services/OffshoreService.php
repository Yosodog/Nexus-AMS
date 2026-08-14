<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OffshoreService
{
    private const CACHE_FRESH_MINUTES = 360;

    public function __construct(private readonly AllianceMembershipService $allianceMembershipService) {}

    /**
     * @return Collection<int, mixed>
     */
    public function all(bool $includeDisabled = false): Collection
    {
        $query = Offshore::query()
            ->with('guardrails')
            ->orderByDesc('enabled')
            ->orderBy('priority');

        if (! $includeDisabled) {
            $query->where('enabled', true);
        }

        return $query->get();
    }

    public function find(int $id): ?Offshore
    {
        return Offshore::with('guardrails')->find($id);
    }

    public function primary(bool $includeDisabled = false): ?Offshore
    {
        $query = Offshore::query()
            ->with('guardrails')
            ->orderByDesc('enabled')
            ->orderBy('priority');

        if (! $includeDisabled) {
            $query->where('enabled', true);
        }

        return $query->first();
    }

    public function create(array $attributes, ?array $guardrails = null): Offshore
    {
        $offshore = new Offshore($attributes);
        $offshore->save();

        $this->syncGuardrails($offshore, $guardrails);
        $this->clearCaches($offshore);
        $this->refreshBalances($offshore, true);

        return $offshore->fresh('guardrails');
    }

    public function update(Offshore $offshore, array $attributes, ?array $guardrails = null): Offshore
    {
        $offshore->fill($attributes);
        $offshore->save();

        $this->syncGuardrails($offshore, $guardrails);
        $this->clearCaches($offshore);

        return $offshore->fresh('guardrails');
    }

    public function delete(Offshore $offshore): void
    {
        $offshore->delete();
        $this->clearCaches($offshore);
    }

    public function guardrailFor(Offshore $offshore, string $resource): ?OffshoreGuardrail
    {
        return $offshore->guardrailFor($resource);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getBalances(Offshore $offshore, bool $force = false): array
    {
        $cacheKey = $this->balancesCacheKey($offshore);
        $snapshot = $this->normalizeSnapshot(Cache::get($cacheKey));

        if (! $force && $this->snapshotIsFresh($snapshot)) {
            return $snapshot['balances'];
        }

        return $this->refreshBalances($offshore, $force);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function refreshBalances(Offshore $offshore, bool $force = false): array
    {
        $cacheKey = $this->balancesCacheKey($offshore);

        if ($force) {
            $offshore->refresh();
        }

        $balances = $this->fetchLiveBalances($offshore);

        if ($balances === []) {
            Log::warning('Offshore balance refresh returned no usable data; preserving the last cached snapshot.', [
                'offshore_id' => $offshore->id,
                'alliance_id' => $offshore->alliance_id,
            ]);

            return [];
        }

        Cache::forever($cacheKey, $this->buildSnapshot($balances));

        Log::info('Offshore balances refreshed', [
            'offshore_id' => $offshore->id,
            'alliance_id' => $offshore->alliance_id,
            'force' => $force,
        ]);

        return $balances;
    }

    /**
     * Retrieve the cached balances alongside their timestamp for dashboards.
     */
    public function getCachedSnapshot(Offshore $offshore): array
    {
        return $this->normalizeSnapshot(Cache::get($this->balancesCacheKey($offshore)));
    }

    public function clearCaches(Offshore $offshore): void
    {
        Cache::forget($this->balancesCacheKey($offshore));
        // Keep the alliance membership cache in sync so permission checks stay accurate.
        $this->allianceMembershipService->refresh();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function fetchLiveBalances(Offshore $offshore): array
    {
        $parameters = [];

        $apiKey = $offshore->api_key_decrypted;
        $mutationKey = $offshore->mutation_key_decrypted;

        if ($apiKey) {
            $parameters['apiKey'] = $apiKey;
        }

        if ($mutationKey) {
            $parameters['mutationKey'] = $mutationKey;
        }

        /** @var QueryService $client */
        $client = App::make(QueryService::class, $parameters);

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $offshore->alliance_id)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        try {
            $response = $client->sendQuery($builder);
        } catch (ConnectionException|PWQueryFailedException $exception) {
            Log::warning('Failed to fetch offshore balances', [
                'offshore_id' => $offshore->id,
                'alliance_id' => $offshore->alliance_id,
                'message' => $exception->getMessage(),
            ]);

            return [];
        } catch (Throwable $exception) {
            Log::error('Unexpected error while fetching offshore balances', [
                'offshore_id' => $offshore->id,
                'alliance_id' => $offshore->alliance_id,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $result = (array) ($response->{0} ?? []);

        if ($result === [] || ! array_key_exists('money', $result)) {
            Log::warning('Offshore balance query returned an incomplete payload.', [
                'offshore_id' => $offshore->id,
                'alliance_id' => $offshore->alliance_id,
            ]);

            return [];
        }

        $resources = PWHelperService::resources();

        return collect($resources)
            ->mapWithKeys(fn (string $resource) => [
                $resource => (float) Arr::get($result, $resource, 0),
            ])
            ->all();
    }

    protected function syncGuardrails(Offshore $offshore, ?array $guardrails): void
    {
        if (is_null($guardrails)) {
            return;
        }

        $guardrailsCollection = collect($guardrails)
            ->filter(fn ($guardrail) => is_array($guardrail))
            ->mapWithKeys(function (array $guardrail) {
                $resource = $guardrail['resource'] ?? null;

                if (! $resource) {
                    return [];
                }

                return [
                    $resource => [
                        'minimum_amount' => (float) ($guardrail['minimum_amount'] ?? 0),
                    ],
                ];
            });

        $existingResources = $offshore->guardrails()->pluck('resource');

        foreach ($guardrailsCollection as $resource => $payload) {
            $offshore->guardrails()->updateOrCreate(
                ['resource' => $resource],
                ['minimum_amount' => $payload['minimum_amount']]
            );
        }

        $resourcesToDelete = $existingResources->diff($guardrailsCollection->keys());

        if ($resourcesToDelete->isNotEmpty()) {
            $offshore->guardrails()->whereIn('resource', $resourcesToDelete)->delete();
        }
    }

    protected function balancesCacheKey(Offshore $offshore): string
    {
        return sprintf('offshores:%d:balances', $offshore->id);
    }

    /**
     * @param  array<string, float>|mixed  $snapshot
     * @return array{balances: array<string, float>, cached_at: Carbon|null}
     */
    protected function normalizeSnapshot(mixed $snapshot): array
    {
        if (is_array($snapshot) && array_key_exists('balances', $snapshot)) {
            $balances = array_map('floatval', (array) ($snapshot['balances'] ?? []));
            $cachedAt = $snapshot['cached_at'] ?? null;

            if ($cachedAt instanceof Carbon) {
                return [
                    'balances' => $balances,
                    'cached_at' => $cachedAt,
                ];
            }

            return [
                'balances' => $balances,
                'cached_at' => $this->normalizeCachedAt($cachedAt),
            ];
        }

        if (is_array($snapshot)) {
            return [
                'balances' => array_map('floatval', $snapshot),
                'cached_at' => null,
            ];
        }

        return [
            'balances' => [],
            'cached_at' => null,
        ];
    }

    protected function normalizeCachedAt(mixed $cachedAt): ?Carbon
    {
        if ($cachedAt instanceof Carbon) {
            return $cachedAt;
        }

        if ($cachedAt instanceof \DateTimeInterface) {
            return Carbon::instance($cachedAt);
        }

        if (is_string($cachedAt) || is_int($cachedAt) || is_float($cachedAt) || $cachedAt === null) {
            return $cachedAt !== null && $cachedAt !== '' ? Carbon::parse($cachedAt) : null;
        }

        return null;
    }

    /**
     * Build a snapshot payload for caching.
     */
    protected function buildSnapshot(array $balances): array
    {
        return [
            'balances' => $balances,
            'cached_at' => now(),
        ];
    }

    /**
     * @param  array{balances: array<string, float>, cached_at: Carbon|null}  $snapshot
     */
    protected function snapshotIsFresh(array $snapshot): bool
    {
        return $snapshot['cached_at'] instanceof Carbon
            && $snapshot['cached_at']->gte(now()->subMinutes(self::CACHE_FRESH_MINUTES));
    }
}
