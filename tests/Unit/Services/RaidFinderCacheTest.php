<?php

namespace Tests\Unit\Services;

use App\Services\RaidFinderCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RaidFinderCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_policy_invalidation_versions_every_nation_cache_key(): void
    {
        $cache = app(RaidFinderCache::class);

        $this->assertSame('raid-finder:v1:4242', $cache->key(4242));
        $this->assertSame('raid-finder:v1:5151', $cache->key(5151));

        $cache->invalidatePolicy();

        $this->assertSame('raid-finder:v2:4242', $cache->key(4242));
        $this->assertSame('raid-finder:v2:5151', $cache->key(5151));

        $cache->invalidatePolicy();

        $this->assertSame('raid-finder:v3:4242', $cache->key(4242));
    }

    public function test_snapshot_tracks_freshness_without_discarding_stale_data_immediately(): void
    {
        $cache = app(RaidFinderCache::class);
        $snapshot = $cache->store(4242, [['value' => 123]]);

        $this->assertTrue($cache->isFresh($snapshot));
        $this->assertSame($snapshot, $cache->snapshot(4242));

        $this->travel(31)->minutes();

        $this->assertFalse($cache->isFresh($snapshot));
        $this->assertSame($snapshot, $cache->snapshot(4242));
    }
}
