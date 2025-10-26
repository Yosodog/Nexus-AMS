<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OffshoreService
{
    private const CACHE_TTL_MINUTES = 30;

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

    public function create(array $attributes, ?array $guardrails = null): Offshore
    {
        $offshore = new Offshore($attributes);
        $offshore->save();

        $this->syncGuardrails($offshore, $guardrails);
        $this->clearCaches($offshore);

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

    public function getBalances(Offshore $offshore, bool $force = false): array
    {
        $cacheKey = $this->balancesCacheKey($offshore);

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($offshore) {
            return $this->fetchLiveBalances($offshore);
        });
    }

    public function refreshBalances(Offshore $offshore, bool $force = false): array
    {
        $cacheKey = $this->balancesCacheKey($offshore);

        Cache::forget($cacheKey);

        if ($force) {
            $offshore->refresh();
        }

        $balances = $this->fetchLiveBalances($offshore);

        Cache::put($cacheKey, $balances, now()->addMinutes(self::CACHE_TTL_MINUTES));

        Log::info('Offshore balances refreshed', [
            'offshore_id' => $offshore->id,
            'alliance_id' => $offshore->alliance_id,
            'force' => $force,
        ]);

        return $balances;
    }

    public function clearCaches(Offshore $offshore): void
    {
        Cache::forget($this->balancesCacheKey($offshore));
    }

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

        $builder = (new GraphQLQueryBuilder())
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

        $result = (array)($response->{0} ?? []);
        $resources = PWHelperService::resources(includeCredits: true);

        return collect($resources)
            ->mapWithKeys(fn(string $resource) => [
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
            ->filter(fn($guardrail) => is_array($guardrail))
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
}
