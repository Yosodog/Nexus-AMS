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
    private const CACHE_TTL_MINUTES = 30;

    private int $mainAllianceId;

    public function __construct(?int $mainAllianceId = null)
    {
        $resolvedAllianceId = $mainAllianceId !== null && $mainAllianceId > 0
            ? $mainAllianceId
            : (int) env('PW_ALLIANCE_ID', 0);

        $this->mainAllianceId = $resolvedAllianceId;
    }

    /**
     * @return array<string, float>
     */
    public function getBalances(bool $force = false): array
    {
        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        $snapshot = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn() => $this->buildSnapshot()
        );

        return $this->normalizeSnapshot($snapshot)['balances'];
    }

    /**
     * Retrieve cached balances with metadata for dashboards.
     *
     * @return array{balances: array<string, float>, cached_at: Carbon|null}
     */
    public function getCachedSnapshot(): array
    {
        $snapshot = Cache::get(self::CACHE_KEY);

        return $this->normalizeSnapshot($snapshot);
    }

    /**
     * Force refresh the main bank balances from the PW API.
     *
     * @return array<string, float>
     */
    public function refreshBalances(): array
    {
        $balances = $this->fetchLiveBalances();
        $snapshot = $this->buildSnapshot($balances);

        Cache::put(self::CACHE_KEY, $snapshot, now()->addMinutes(self::CACHE_TTL_MINUTES));

        Log::info('Main bank balances refreshed', [
            'alliance_id' => $this->mainAllianceId,
        ]);

        return $balances;
    }

    /**
     * @param array<string, float>|mixed $snapshot
     * @return array{balances: array<string, float>, cached_at: Carbon|null}
     */
    protected function normalizeSnapshot(mixed $snapshot): array
    {
        if (is_array($snapshot) && array_key_exists('balances', $snapshot)) {
            $balances = (array) ($snapshot['balances'] ?? []);
            $cachedAt = $snapshot['cached_at'] ?? null;

            if ($cachedAt instanceof Carbon) {
                return [
                    'balances' => $balances,
                    'cached_at' => $cachedAt,
                ];
            }

            return [
                'balances' => $balances,
                'cached_at' => $cachedAt ? Carbon::parse($cachedAt) : null,
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

    /**
     * Build a snapshot payload for caching.
     *
     * @param array<string, float>|null $balances
     * @return array{balances: array<string, float>, cached_at: Carbon}
     */
    protected function buildSnapshot(?array $balances = null): array
    {
        $balances ??= $this->fetchLiveBalances();

        return [
            'balances' => $balances,
            'cached_at' => now(),
        ];
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

        $builder = (new GraphQLQueryBuilder())
            ->setRootField('alliances')
            ->addArgument('id', $this->mainAllianceId)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(array_merge(SelectionSetHelper::allianceSet(), ['credits']));
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
        $resources = PWHelperService::resources(includeCredits: true);

        return collect($resources)
            ->mapWithKeys(fn(string $resource) => [
                $resource => (float) Arr::get($result, $resource, 0),
            ])
            ->all();
    }
}
