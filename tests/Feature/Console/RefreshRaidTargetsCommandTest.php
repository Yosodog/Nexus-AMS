<?php

namespace Tests\Feature\Console;

use App\Jobs\RefreshRaidIntelligence;
use App\Models\Nation;
use App\Services\RaidIntelligenceDemand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshRaidTargetsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_refresh_does_not_fill_from_the_global_cursor(): void
    {
        Queue::fake();
        Cache::flush();
        $nations = Nation::factory()->count(4)->create();
        app(RaidIntelligenceDemand::class)->prioritize([$nations[3]->id]);

        $this->artisan('raids:refresh-intelligence', ['--limit' => 4])
            ->expectsOutputToContain('Queued 1 nations.')
            ->assertSuccessful();

        Queue::assertPushed(RefreshRaidIntelligence::class, 1);
        Queue::assertPushed(RefreshRaidIntelligence::class, function (RefreshRaidIntelligence $job) use ($nations): bool {
            return $job->nationIds === [(int) $nations[3]->id];
        });
    }

    public function test_background_refresh_advances_the_global_cursor_without_priority_demand(): void
    {
        Queue::fake();
        Cache::flush();
        $nations = Nation::factory()->count(4)->create();
        app(RaidIntelligenceDemand::class)->prioritize([$nations[3]->id]);

        $this->artisan('raids:refresh-intelligence', ['--background' => true, '--limit' => 3])
            ->expectsOutputToContain('Queued 3 nations.')
            ->assertSuccessful();

        Queue::assertPushed(RefreshRaidIntelligence::class, fn (RefreshRaidIntelligence $job): bool => $job->nationIds === $nations->take(3)->pluck('id')->all());
        $this->assertSame([(int) $nations[3]->id], app(RaidIntelligenceDemand::class)->take(10));
    }

    public function test_retry_window_replaces_the_low_attempt_limit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
        config(['raids.retry_until_minutes' => 20]);
        $job = new RefreshRaidIntelligence([2, 1]);

        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertTrue($job->retryUntil()->equalTo(now()->addMinutes(20)));
    }
}
