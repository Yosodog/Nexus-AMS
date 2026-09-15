<?php

namespace App\Services;

use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RaidFinderCache
{
    public const FRESH_FOR_SECONDS = 300;

    public const STALE_FOR_SECONDS = 21600;

    private const POLICY_VERSION_KEY = 'raid-finder:policy-version';

    public function key(int $nationId): string
    {
        $nation = Nation::query()->with(['military', 'resources'])->find($nationId);
        $fingerprint = hash('sha256', json_encode([
            $nation?->score, $nation?->war_policy, $nation?->military?->getAttributes(),
            $nation?->resources?->getAttributes(),
            RaidNationObservation::query()->max('id'),
            RaidAttackObservation::query()->max('updated_at'),
            Cache::store(config('raids.intelligence_cache_store'))->get('raid-intelligence:revision'),
            MarketPriceSnapshot::query()->max('id'),
            (int) config('raids.model_version', 1),
            'calculation-evidence-v1',
        ], JSON_THROW_ON_ERROR));

        return sprintf('raid-finder:profit:planning:v%d:%d:%s', $this->policyVersion(), $nationId, substr($fingerprint, 0, 20));
    }

    public function lockKey(int $nationId): string
    {
        return 'raid-finder:profit:'.$nationId.':refresh';
    }

    public function failureKey(int $nationId): string
    {
        return 'raid-finder:profit:'.$nationId.':failure';
    }

    public function completedKey(int $nationId): string
    {
        return 'raid-finder:profit:'.$nationId.':recently-completed';
    }

    /**
     * @return array{targets: list<array<string, mixed>>, updated_at: string}|null
     */
    public function snapshot(int $nationId): ?array
    {
        $key = $this->key($nationId);
        $snapshot = Cache::get($key) ?? Cache::get('raid-finder:profit:last:'.$nationId);

        if (
            ! is_array($snapshot)
            || ! isset($snapshot['targets'], $snapshot['updated_at'])
            || ! is_array($snapshot['targets'])
            || ! is_string($snapshot['updated_at'])
        ) {
            return null;
        }

        $result = [
            'revision' => $snapshot['revision'] ?? $key,
            'targets' => array_values($snapshot['targets']),
            'updated_at' => $snapshot['updated_at'],
            'revision_current' => ($snapshot['revision'] ?? $key) === $key,
        ];
        if (($snapshot['complete'] ?? true) === false) {
            $result['complete'] = false;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array{targets: list<array<string, mixed>>, updated_at: string}
     */
    public function store(int $nationId, array $targets, ?string $revision = null, bool $complete = true): array
    {
        $revision ??= $this->key($nationId);
        $snapshot = [
            'revision' => $revision,
            'targets' => array_values($targets),
            'updated_at' => now()->toIso8601String(),
            'revision_current' => true,
        ];
        if (! $complete) {
            $snapshot['complete'] = false;
        }

        Cache::put($revision, $snapshot, self::STALE_FOR_SECONDS);
        Cache::put('raid-finder:profit:last:'.$nationId, $snapshot, self::STALE_FOR_SECONDS);
        if ($complete) {
            Cache::put($this->completedKey($nationId), true, 30);
        }

        return $snapshot;
    }

    /**
     * @param  array{targets: list<array<string, mixed>>, updated_at: string}  $snapshot
     */
    public function isFresh(array $snapshot): bool
    {
        if (($snapshot['revision_current'] ?? true) === false || ($snapshot['complete'] ?? true) === false) {
            return false;
        }
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
