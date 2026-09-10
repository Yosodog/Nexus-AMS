<?php

namespace App\Services;

use App\DataTransferObjects\OffshoreUpdateResult;
use App\Exceptions\PWQueryFailedException;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OffshoreService
{
    private const CACHE_FRESH_MINUTES = 360;

    public function __construct(
        private readonly AllianceMembershipService $allianceMembershipService,
        private readonly ?DirectDepositService $directDepositService = null,
    ) {}

    /**
     * @return Collection<int, mixed>
     */
    public function all(bool $includeDisabled = false): Collection
    {
        $query = Offshore::query()
            ->with('guardrails')
            ->withCount([
                'directDepositEnrollments',
                'directDepositEnrollments as pending_direct_deposit_enrollments_count' => fn ($query) => $query
                    ->whereNotNull('disenrollment_requested_at'),
            ])
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
        if (array_key_exists('enabled', $attributes) && ! $attributes['enabled']) {
            $attributes['direct_deposit_enabled'] = false;
        }

        $offshore = DB::transaction(function () use ($attributes, $guardrails): Offshore {
            $offshore = new Offshore($attributes);
            $offshore->save();

            if ($offshore->direct_deposit_enabled) {
                $this->validateDirectDepositConfiguration($offshore);
            }

            $this->syncGuardrails($offshore, $guardrails);

            return $offshore->fresh('guardrails');
        });

        $this->clearCaches($offshore);
        $this->refreshBalances($offshore, true);

        return $offshore;
    }

    public function update(Offshore $offshore, array $attributes, ?array $guardrails = null): OffshoreUpdateResult
    {
        $result = DB::transaction(function () use ($offshore, $attributes, $guardrails): OffshoreUpdateResult {
            $lockedOffshore = Offshore::query()->whereKey($offshore->id)->lockForUpdate()->firstOrFail();
            $allianceIdChanged = array_key_exists('alliance_id', $attributes)
                && (int) $attributes['alliance_id'] !== (int) $lockedOffshore->alliance_id;
            $nextEnabled = array_key_exists('enabled', $attributes)
                ? (bool) $attributes['enabled']
                : $lockedOffshore->enabled;
            $nextDirectDepositEnabled = array_key_exists('direct_deposit_enabled', $attributes)
                ? (bool) $attributes['direct_deposit_enabled']
                : $lockedOffshore->direct_deposit_enabled;
            $offshoreDisabled = $lockedOffshore->enabled && ! $nextEnabled;
            $directDepositDisabled = $lockedOffshore->direct_deposit_enabled && ! $nextDirectDepositEnabled;
            $mustDisenroll = $allianceIdChanged || $offshoreDisabled || $directDepositDisabled;
            $enrollmentCount = $lockedOffshore->directDepositEnrollments()->count();
            $credentialsChanged = array_key_exists('api_key', $attributes)
                || array_key_exists('mutation_key', $attributes);

            if ($mustDisenroll && $enrollmentCount > 0 && $credentialsChanged) {
                throw ValidationException::withMessages([
                    'api_key' => 'Keep the current offshore credentials until all Direct Deposit disenrollments complete.',
                ]);
            }

            $taxIdChanged = (array_key_exists('direct_deposit_tax_id', $attributes)
                    && (int) $attributes['direct_deposit_tax_id'] !== (int) $lockedOffshore->direct_deposit_tax_id)
                || (array_key_exists('direct_deposit_fallback_tax_id', $attributes)
                    && (int) $attributes['direct_deposit_fallback_tax_id'] !== (int) $lockedOffshore->direct_deposit_fallback_tax_id);

            if ($taxIdChanged && $nextDirectDepositEnabled && $enrollmentCount > 0 && ! $mustDisenroll) {
                throw ValidationException::withMessages([
                    'direct_deposit_tax_id' => 'Disable Direct Deposit before changing its tax ID while members are enrolled.',
                ]);
            }

            if ($allianceIdChanged) {
                $attributes['direct_deposit_enabled'] = false;
                $attributes['direct_deposit_tax_id'] = null;
                $attributes['direct_deposit_fallback_tax_id'] = null;
            } elseif (! $nextEnabled) {
                $attributes['direct_deposit_enabled'] = false;
            }

            $lockedOffshore->fill($attributes);

            if ($lockedOffshore->direct_deposit_enabled) {
                $this->validateDirectDepositConfiguration($lockedOffshore);
            }

            $lockedOffshore->save();

            $queuedDisenrollments = $mustDisenroll
                ? $this->resolveDirectDepositService()->requestDisenrollmentsForOffshore($lockedOffshore)
                : 0;

            $this->syncGuardrails($lockedOffshore, $guardrails);

            return new OffshoreUpdateResult(
                offshore: $lockedOffshore->fresh('guardrails'),
                allianceIdChanged: $allianceIdChanged,
                directDepositDisabled: $mustDisenroll,
                queuedDisenrollments: $queuedDisenrollments,
            );
        });

        $this->clearCaches($result->offshore);

        return $result;
    }

    public function delete(Offshore $offshore): bool
    {
        $deleted = DB::transaction(function () use ($offshore): bool {
            $lockedOffshore = Offshore::query()->whereKey($offshore->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffshore->directDepositEnrollments()->exists()) {
                $lockedOffshore->forceFill(['direct_deposit_enabled' => false])->save();
                $this->resolveDirectDepositService()->requestDisenrollmentsForOffshore($lockedOffshore);

                return false;
            }

            $lockedOffshore->delete();

            return true;
        });

        $this->clearCaches($offshore);

        return $deleted;
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

    private function resolveDirectDepositService(): DirectDepositService
    {
        return $this->directDepositService ?? app(DirectDepositService::class);
    }

    private function validateDirectDepositConfiguration(Offshore $offshore): void
    {
        $taxId = (int) $offshore->direct_deposit_tax_id;
        $fallbackTaxId = (int) $offshore->direct_deposit_fallback_tax_id;

        if ($taxId <= 0 || $fallbackTaxId <= 0) {
            throw ValidationException::withMessages([
                'direct_deposit_tax_id' => 'Both Direct Deposit tax IDs are required before enabling Direct Deposit.',
            ]);
        }

        if ($taxId === $fallbackTaxId) {
            throw ValidationException::withMessages([
                'direct_deposit_fallback_tax_id' => 'The Direct Deposit and fallback tax IDs must be different.',
            ]);
        }

        if (! $offshore->api_key_decrypted || ! $offshore->mutation_key_decrypted) {
            throw ValidationException::withMessages([
                'mutation_key' => 'Usable offshore API and mutation credentials are required before enabling Direct Deposit.',
            ]);
        }
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
