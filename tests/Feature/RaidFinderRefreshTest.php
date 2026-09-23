<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Jobs\RefreshRaidFinder;
use App\Models\Nation;
use App\Models\User;
use App\Services\RaidFinderCache;
use App\Services\RaidFinderService;
use App\Services\RuntimeCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Telescope\Telescope;
use Mockery;
use Tests\TestCase;

class RaidFinderRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_queued_refresh_publishes_partial_results_then_a_complete_snapshot(): void
    {
        $nation = $this->prepareMember();
        $target = Nation::factory()->create([
            'alliance_id' => null, 'score' => $nation->score, 'color' => 'blue',
            'beige_turns' => 0, 'vacation_mode_turns' => 0,
        ]);
        $url = route('api.raid-finder.show', ['nation_id' => $nation->id]);
        $this->getJson($url)->assertStatus(202)->assertHeader('X-Nexus-Async-State', 'refreshing');
        $this->getJson($url)->assertStatus(202)->assertHeader('Retry-After', '2');
        Queue::assertPushed(RefreshRaidFinder::class, 1);
        $job = Queue::pushed(RefreshRaidFinder::class)->first();
        $cache = app(RaidFinderCache::class);
        $finder = Mockery::mock(RaidFinderService::class);
        $finder->shouldReceive('findTargets')->once()->andReturnUsing(function (int $nationId, callable $progress) use ($cache, $target) {
            $this->assertFalse(Telescope::isRecording());
            $rows = collect([collect(['nation' => ['id' => $target->id], 'value' => 100])]);
            $progress($rows);
            $partial = $cache->snapshot($nationId);
            $this->assertFalse($cache->isFresh($partial));
            $this->assertSame(100, $partial['targets'][0]['value']);
            Nation::query()->findOrFail($nationId)->increment('score');

            return $rows;
        });

        Telescope::startRecording();
        $job->handle($finder, $cache, app(RuntimeCapabilities::class));
        $this->assertTrue(Telescope::isRecording());
        Telescope::stopRecording();

        $this->assertTrue($cache->isFresh($cache->snapshot($nation->id)));
        $this->assertNull($job->queue);
        $this->getJson($url)->assertOk()->assertHeader('X-Nexus-Async-State', 'success')->assertJsonPath('0.value', 100);
        Queue::assertPushed(RefreshRaidFinder::class, 1);

        Cache::store(config('raids.intelligence_cache_store'))->forever('raid-intelligence:revision', 'changed-during-scan');
        $this->getJson($url)->assertOk()->assertHeader('X-Nexus-Data-Stale', 'false');
        Queue::assertPushed(RefreshRaidFinder::class, 1);
        $this->travel(301)->seconds();
        $this->getJson($url)->assertOk()->assertHeader('X-Nexus-Async-State', 'refreshing');
        Queue::assertPushed(RefreshRaidFinder::class, 2);
    }

    public function test_polling_fits_the_rate_limit_and_does_not_duplicate_evaluation(): void
    {
        $nation = $this->prepareMember();
        $url = route('api.raid-finder.show', ['nation_id' => $nation->id]);
        for ($request = 0; $request < 60; $request++) {
            $this->getJson($url)->assertStatus(202);
        }
        Queue::assertPushed(RefreshRaidFinder::class, 1);
        $this->getJson($url)->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('source', 'nexus')
            ->assertJsonPath('state', 'rate_limited')
            ->assertJsonPath('message', 'Raid Finder is receiving too many requests. Please wait before retrying.');
    }

    public function test_polling_does_not_restart_completed_or_missing_jobs(): void
    {
        $nation = $this->prepareMember();
        $url = route('api.raid-finder.show', ['nation_id' => $nation->id]);
        $this->getJson($url.'?poll=1')->assertStatus(202);
        Queue::assertNotPushed(RefreshRaidFinder::class);
        $cache = app(RaidFinderCache::class);
        $cache->store($nation->id, []);
        $this->travel(301)->seconds();
        Cache::store(config('raids.intelligence_cache_store'))->forever('raid-intelligence:revision', 'newer-world-state');
        $this->getJson($url.'?poll=1&after=older-snapshot')->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'success');
        Queue::assertNotPushed(RefreshRaidFinder::class);
    }

    public function test_polling_reports_an_orphaned_partial_snapshot_as_failed(): void
    {
        $nation = $this->prepareMember();
        app(RaidFinderCache::class)->store($nation->id, [], complete: false);

        $this->getJson(route('api.raid-finder.show', ['nation_id' => $nation->id]).'?poll=1')
            ->assertOk()
            ->assertHeader('X-Nexus-Async-State', 'temporary_failure')
            ->assertHeader('Retry-After', '30');
        Queue::assertNotPushed(RefreshRaidFinder::class);
    }

    public function test_failed_background_evaluation_releases_the_lock_and_exposes_a_retryable_failure(): void
    {
        $nation = $this->prepareMember();
        $url = route('api.raid-finder.show', ['nation_id' => $nation->id]);
        $this->getJson($url)->assertStatus(202);
        Queue::pushed(RefreshRaidFinder::class)->first()->failed(new \RuntimeException('Recorded evaluation failure'));

        $this->getJson($url)->assertStatus(503)->assertJsonPath('state', 'temporary_failure')->assertHeader('Retry-After', '30');
        $lock = Cache::lock(app(RaidFinderCache::class)->lockKey($nation->id), 1);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    private function prepareMember(): Nation
    {
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        config(['queue.default' => 'database']);
        Queue::fake();
        $nation = Nation::factory()->create(['alliance_id' => 777, 'alliance_position' => 'MEMBER']);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        $this->actingAs($user)->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class, EnsureUserIsVerified::class]);

        return $nation;
    }
}
