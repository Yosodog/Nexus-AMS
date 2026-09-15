<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class RaidIntelligenceDemand
{
    /** @param list<int> $nationIds */
    public function prioritize(array $nationIds): void
    {
        $cache = Cache::store(config('raids.intelligence_cache_store'));
        $cache->lock('raid-intelligence:priority:lock', 10)->block(2, function () use ($cache, $nationIds): void {
            $pending = array_merge((array) $cache->get('raid-intelligence:priority', []), $nationIds);
            $pending = array_values(array_unique(array_filter(array_map('intval', $pending), fn (int $id): bool => $id > 0)));
            $cache->put('raid-intelligence:priority', array_slice($pending, 0, 500), 3600);
        });
    }

    /** @return list<int> */
    public function take(int $limit): array
    {
        $cache = Cache::store(config('raids.intelligence_cache_store'));

        return $cache->lock('raid-intelligence:priority:lock', 10)->block(2, function () use ($cache, $limit): array {
            $pending = (array) $cache->get('raid-intelligence:priority', []);
            $selected = array_splice($pending, 0, max(0, $limit));
            $cache->put('raid-intelligence:priority', $pending, 3600);

            return $selected;
        });
    }
}
