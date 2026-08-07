<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RaidFinderCache
{
    public const FRESH_FOR_SECONDS = 1800;

    public const STALE_FOR_SECONDS = 21600;

    private const POLICY_VERSION_KEY = 'raid-finder:policy-version';

    public function key(int $nationId): string
    {
        return sprintf('raid-finder:v%d:%d', $this->policyVersion(), $nationId);
    }

    public function lockKey(int $nationId): string
    {
        return $this->key($nationId).':refresh';
    }

    /**
     * @return array{targets: list<array<string, mixed>>, updated_at: string}|null
     */
    public function snapshot(int $nationId): ?array
    {
        $snapshot = Cache::get($this->key($nationId));

        if (
            ! is_array($snapshot)
            || ! isset($snapshot['targets'], $snapshot['updated_at'])
            || ! is_array($snapshot['targets'])
            || ! is_string($snapshot['updated_at'])
        ) {
            return null;
        }

        return [
            'targets' => array_values($snapshot['targets']),
            'updated_at' => $snapshot['updated_at'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array{targets: list<array<string, mixed>>, updated_at: string}
     */
    public function store(int $nationId, array $targets): array
    {
        $snapshot = [
            'targets' => array_values($targets),
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::put($this->key($nationId), $snapshot, self::STALE_FOR_SECONDS);

        return $snapshot;
    }

    /**
     * @param  array{targets: list<array<string, mixed>>, updated_at: string}  $snapshot
     */
    public function isFresh(array $snapshot): bool
    {
        try {
            return CarbonImmutable::parse($snapshot['updated_at'])
                ->addSeconds(self::FRESH_FOR_SECONDS)
                ->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    public function invalidatePolicy(): void
    {
        if (Cache::add(self::POLICY_VERSION_KEY, 2, now()->addYears(10))) {
            return;
        }

        Cache::increment(self::POLICY_VERSION_KEY);
    }

    private function policyVersion(): int
    {
        return max((int) Cache::get(self::POLICY_VERSION_KEY, 1), 1);
    }
}
