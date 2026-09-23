<?php

namespace Tests\Unit\Services;

use App\Models\Nation;
use App\Models\RaidNationObservation;
use App\Services\RaidFinderCache;
use App\Services\RaidIntelligenceDemand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RaidFinderCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_policy_invalidation_versions_every_nation_cache_key(): void
    {
        $cache = app(RaidFinderCache::class);

        $this->assertStringStartsWith('raid-finder:profit:planning:v1:4242:', $cache->key(4242));
        $this->assertStringStartsWith('raid-finder:profit:planning:v1:5151:', $cache->key(5151));

        $cache->invalidatePolicy();

        $this->assertStringStartsWith('raid-finder:profit:planning:v2:4242:', $cache->key(4242));
        $this->assertStringStartsWith('raid-finder:profit:planning:v2:5151:', $cache->key(5151));

        $cache->invalidatePolicy();

        $this->assertStringStartsWith('raid-finder:profit:planning:v3:4242:', $cache->key(4242));
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

    public function test_unrelated_intelligence_keeps_a_recent_snapshot_fresh(): void
    {
        $cache = app(RaidFinderCache::class);
        $cache->store(4242, [['value' => 123]]);
        RaidNationObservation::factory()->create(['nation_id' => 10]);
        $snapshot = $cache->snapshot(4242);
        $this->assertSame(123, $snapshot['targets'][0]['value']);
        $this->assertTrue($cache->isFresh($snapshot));
    }

    public function test_changed_attacker_supplies_invalidates_a_budgeted_approach(): void
    {
        $nation = Nation::factory()->create();
        $supplies = $nation->resources()->create(array_replace(
            array_fill_keys(['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'], 0),
            ['money' => 1000000, 'gasoline' => 100, 'munitions' => 100, 'credits' => 0],
        ));
        $cache = app(RaidFinderCache::class);
        $before = $cache->key($nation->id);

        $supplies->update(['munitions' => 1]);

        $this->assertNotSame($before, $cache->key($nation->id));
    }

    public function test_target_refresh_demand_merges_searches_and_preserves_unconsumed_ids(): void
    {
        $demand = app(RaidIntelligenceDemand::class);
        $demand->prioritize([10, 20]);
        $demand->prioritize([20, 30, -1]);

        $this->assertSame([10], $demand->take(1));
        $demand->prioritize([40]);
        $this->assertSame([20, 30, 40], $demand->take(100));
        $this->assertSame([], $demand->take(100));
    }

    public function test_public_revision_uses_the_five_minute_snapshot_freshness_window(): void
    {
        $cache = app(RaidFinderCache::class);
        $before = $cache->key(4242);
        Cache::store(config('raids.intelligence_cache_store'))->forever('raid-intelligence:revision', 'corrected-public-attack');

        $this->assertSame($before, $cache->key(4242));
    }
}
