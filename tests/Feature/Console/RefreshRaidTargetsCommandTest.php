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

    public function test_it_queues_one_job_for_the_full_scheduled_target_set(): void
    {
        Queue::fake();
        Cache::flush();
        $nations = Nation::factory()->count(4)->create();
        app(RaidIntelligenceDemand::class)->prioritize([$nations[3]->id]);

        $this->artisan('raids:refresh-intelligence', ['--limit' => 4])
            ->expectsOutputToContain('Queued 4 nations.')
            ->assertSuccessful();

        Queue::assertPushed(RefreshRaidIntelligence::class, 1);
        Queue::assertPushed(RefreshRaidIntelligence::class, function (RefreshRaidIntelligence $job) use ($nations): bool {
            return $job->nationIds === $nations->pluck('id')->all();
        });
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
