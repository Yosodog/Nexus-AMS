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
}
