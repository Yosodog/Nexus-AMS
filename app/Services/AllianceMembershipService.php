<?php

namespace App\Services;

use App\Models\Offshore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * Centralizes the definition of "our alliance" by combining the primary
 * alliance configured in the environment with any enabled offshores.
 *
 * This allows us to treat offshores as first-class members everywhere in the
 * application without scattering additional queries or environment lookups.
 */
class AllianceMembershipService
{
    private const CACHE_KEY = 'alliances:membership:ids';

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * Return all alliance IDs that should be considered part of our umbrella.
     *
     * @return Collection<int, int> A unique list of alliance IDs.
     */
    public function getAllianceIds(): Collection
    {
        $ids = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            return $this->resolveAllianceIds();
        });

        return collect($ids);
    }

    /**
     * Retrieve the configured primary alliance ID.
     */
    public function getPrimaryAllianceId(): int
    {
        return (int) env('PW_ALLIANCE_ID', 0);
    }

    /**
     * Determine if the provided alliance ID belongs to our membership group.
     */
    public function contains(?int $allianceId): bool
    {
        if ($allianceId === null) {
            return false;
        }

        return $this->getAllianceIds()->contains((int) $allianceId);
    }

    /**
     * Force a refresh of the cached membership list after offshores change.
     */
    public function refresh(): Collection
    {
        $ids = $this->resolveAllianceIds();

        $this->cache->forever(self::CACHE_KEY, $ids);

        return collect($ids);
    }

    /**
     * Clear the cached membership list so it can be lazily rebuilt.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Resolve the API credentials required to talk to the provided alliance.
     *
     * We centralize this lookup so any future changes to how we store
     * credentials (rotation, external vault, etc.) only have a single
     * integration point.
     *
     * @param int $allianceId
     * @return array{api_key: string|null, mutation_key: string|null}|null
     */
    public function getCredentialsForAlliance(int $allianceId): ?array
    {
        $primaryAllianceId = (int) env('PW_ALLIANCE_ID', 0);

        if ($allianceId === $primaryAllianceId) {
            $apiKey = env('PW_API_KEY');
            $mutationKey = env('PW_API_MUTATION_KEY');

            if ($apiKey === null) {
                return null;
            }

            return [
                'api_key' => $apiKey,
                'mutation_key' => $mutationKey ?: null,
            ];
        }

        $offshore = Offshore::query()
            ->enabled()
            ->where('alliance_id', $allianceId)
            ->first();

        if (! $offshore) {
            return null;
        }

        $apiKey = $offshore->api_key_decrypted;

        if (! $apiKey) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'mutation_key' => $offshore->mutation_key_decrypted,
        ];
    }

    /**
     * Build the membership list by merging the primary alliance with enabled offshores.
     *
     * @return array<int, int>
     */
    protected function resolveAllianceIds(): array
    {
        $primaryAllianceId = (int) env('PW_ALLIANCE_ID', 0);

        $offshoreIds = Offshore::query()
            ->enabled()
            ->pluck('alliance_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        return collect([$primaryAllianceId])
            ->merge($offshoreIds)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
