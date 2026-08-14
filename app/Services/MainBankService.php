<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MainBankService
{
    private const CACHE_KEY = 'offshores:main:balances';

    private const CACHE_FRESH_MINUTES = 360;

    private int $mainAllianceId;

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        ?int $mainAllianceId = null
    ) {
        $resolvedAllianceId = $mainAllianceId !== null && $mainAllianceId > 0
            ? $mainAllianceId
            : $this->membershipService->getPrimaryAllianceId();

        $this->mainAllianceId = $resolvedAllianceId;
    }

    /**
     * @return array<string, float>
     */
    public function getBalances(bool $force = false): array
    {
        $snapshot = $this->normalizeSnapshot(Cache::get(self::CACHE_KEY));

        if (! $force && $this->snapshotIsFresh($snapshot)) {
            return $snapshot['balances'];
        }

        return $this->refreshBalances();
    }

    /**
     * Retrieve cached balances with metadata for dashboards.
     *
     * @return array{balances: array<string, float>, cached_at: Carbon|null}
     */
    public function getCachedSnapshot(): array
    {
        return $this->normalizeSnapshot(Cache::get(self::CACHE_KEY));
    }

    /**
     * Force refresh the main bank balances from the PW API.
     *
     * @return array<string, float>
     */
    public function refreshBalances(): array
    {
        $balances = $this->fetchLiveBalances();

        if ($balances === []) {
            Log::warning('Main bank balance refresh returned no usable data; preserving the last cached snapshot.');

            return [];
        }

        Cache::forever(self::CACHE_KEY, $this->buildSnapshot($balances));

        Log::info('Main bank balances refreshed', [
            'alliance_id' => $this->mainAllianceId,
        ]);

        return $balances;
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
     *
     * @param  array<string, float>  $balances
     * @return array{balances: array<string, float>, cached_at: Carbon}
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

    /**
     * @return array<string, float>
     */
    protected function fetchLiveBalances(): array
    {
        if ($this->mainAllianceId <= 0) {
            Log::warning('Main alliance ID is not configured; cannot fetch main bank balances.');

            return [];
        }

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $this->mainAllianceId)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        try {
            /** @var QueryService $client */
            $client = App::make(QueryService::class);
            $response = $client->sendQuery($builder);
        } catch (ConnectionException|PWQueryFailedException $exception) {
            Log::error('Failed to read main alliance balances', [
                'alliance_id' => $this->mainAllianceId,
                'message' => $exception->getMessage(),
            ]);

            return [];
        } catch (Throwable $exception) {
            Log::error('Unexpected error fetching main alliance balances', [
                'alliance_id' => $this->mainAllianceId,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $result = (array) ($response->{0} ?? []);

        if ($result === [] || ! array_key_exists('money', $result)) {
            Log::warning('Main bank balance query returned an incomplete payload.', [
                'alliance_id' => $this->mainAllianceId,
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
}
