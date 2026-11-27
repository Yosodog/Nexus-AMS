<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshBuildRecommendationBatchJob;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyRules;
use App\Services\NationBuildRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class RefreshBuildRecommendationBatchJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-02 18:00:00 UTC');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function starting_a_batch_queues_one_job_for_every_nation_on_the_default_queue(): void
    {
        Queue::fake();

        RefreshBuildRecommendationBatchJob::start([1001, 1002, 1002, 1003], 42, 7);

        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);
        Queue::assertPushed(
            RefreshBuildRecommendationBatchJob::class,
            fn (RefreshBuildRecommendationBatchJob $job): bool => $job->nationIds === [1001, 1002, 1003]
                && $job->marketPriceSnapshotId === 42
                && $job->radiationSnapshotId === 7
                && $job->queue === 'default'
                && $job->batchToken === Cache::get(RefreshBuildRecommendationBatchJob::CURRENT_BATCH_CACHE_KEY)
        );
    }

    #[Test]
    public function starting_an_empty_batch_is_rejected(): void
    {
        Queue::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            RefreshBuildRecommendationBatchJob::start([], 42, 7);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    #[Test]
    public function each_job_refreshes_only_its_first_nation_and_queues_the_rest_behind_other_work(): void
    {
        Queue::fake();
        $service = $this->expectRefreshes([1001]);
        $job = $this->currentBatchJob([1001, 1002, 1003]);

        $job->handle($service);

        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);
        Queue::assertPushed(
            RefreshBuildRecommendationBatchJob::class,
            fn (RefreshBuildRecommendationBatchJob $next): bool => $next->nationIds === [1002, 1003]
                && $next->batchToken === $job->batchToken
                && $next->marketPriceSnapshotId === 42
                && $next->radiationSnapshotId === 7
                && $next->queue === 'default'
        );
    }

    #[Test]
    public function the_last_nation_ends_the_batch(): void
    {
        Queue::fake();
        $service = $this->expectRefreshes([1003]);

        $this->currentBatchJob([1003])->handle($service);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_retried_job_refreshes_again_but_queues_the_rest_only_once(): void
    {
        Queue::fake();
        $service = $this->expectRefreshes([1001, 1001]);
        $job = $this->currentBatchJob([1001, 1002]);

        $job->handle($service);
        $job->handle($service);

        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);
    }

    #[Test]
    public function a_superseded_batch_stops_without_refreshing(): void
    {
        Queue::fake();
        $service = $this->expectRefreshes([]);
        $job = $this->currentBatchJob([1001, 1002]);
        Cache::put(RefreshBuildRecommendationBatchJob::CURRENT_BATCH_CACHE_KEY, 'newer-batch', now()->addHour());

        $job->handle($service);
        $job->failed(new RuntimeException('Timed out.'));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_batch_keeps_running_when_its_marker_was_cleared_from_the_cache(): void
    {
        Queue::fake();
        $service = $this->expectRefreshes([1001]);
        $job = $this->currentBatchJob([1001, 1002]);
        Cache::forget(RefreshBuildRecommendationBatchJob::CURRENT_BATCH_CACHE_KEY);

        $job->handle($service);

        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);
    }

    #[Test]
    public function a_throwing_nation_is_retried_by_the_queue_before_the_batch_moves_on(): void
    {
        Queue::fake();
        $service = Mockery::mock(NationBuildRecommendationService::class);
        $service->shouldReceive('refreshStoredRecommendationForNationId')
            ->once()
            ->with(1001, 42, 7)
            ->andThrow(new RuntimeException('Database went away.'));
        $job = $this->currentBatchJob([1001, 1002]);

        try {
            $job->handle($service);
            $this->fail('The refresh failure should reach the queue so the job is retried.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database went away.', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_nation_that_used_all_attempts_does_not_stop_the_batch(): void
    {
        Queue::fake();
        Log::spy();
        $job = $this->currentBatchJob([1001, 1002, 1003]);

        $job->failed(new RuntimeException('Timed out.'));
        $job->failed(new RuntimeException('Timed out.'));

        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);
        Queue::assertPushed(
            RefreshBuildRecommendationBatchJob::class,
            fn (RefreshBuildRecommendationBatchJob $next): bool => $next->nationIds === [1002, 1003]
        );
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'continuing the batch')
                && $context['nation_id'] === 1001
                && $context['remaining_nations'] === 2);
    }

    #[Test]
    public function on_a_real_queue_a_failing_nation_is_skipped_and_the_others_still_refresh(): void
    {
        $refreshed = [];
        $service = Mockery::mock(NationBuildRecommendationService::class);
        $service->shouldReceive('refreshStoredRecommendationForNationId')
            ->times(3)
            ->andReturnUsing(function (int $nationId) use (&$refreshed): null {
                $refreshed[] = $nationId;

                if ($nationId === 1002) {
                    throw new RuntimeException('Optimizer failed.');
                }

                return null;
            });
        $this->app->instance(NationBuildRecommendationService::class, $service);

        try {
            RefreshBuildRecommendationBatchJob::start([1001, 1002, 1003], 42, 7);
        } catch (RuntimeException) {
            // The sync queue rethrows the failed nation after its failed() hook ran.
        }

        $this->assertSame([1001, 1002, 1003], $refreshed);
    }

    #[Test]
    public function a_batch_refreshes_every_eligible_nation_end_to_end(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $nations = collect(range(1, 3))->map(fn (int $index): Nation => $this->eligibleNationWithCity($index));
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = $this->worldSnapshot();

        RefreshBuildRecommendationBatchJob::start(
            $nations->pluck('id')->all(),
            $marketSnapshot->id,
            $worldSnapshot->id
        );

        foreach ($nations as $nation) {
            $recommendation = NationBuildRecommendation::query()->where('nation_id', $nation->id)->first();
            $this->assertNotNull($recommendation, "Nation {$nation->id} has no recommendation.");
            $this->assertSame($marketSnapshot->id, (int) $recommendation->market_price_snapshot_id);
            $this->assertSame($worldSnapshot->id, (int) $recommendation->radiation_snapshot_id);
        }
    }

    #[Test]
    public function a_unique_lock_left_by_a_killed_single_nation_job_does_not_block_the_batch(): void
    {
        Queue::fake();
        $lock = new UniqueLock(app(Repository::class));
        $this->assertTrue($lock->acquire(new RefreshNationBuildRecommendationJob(1001)));
        $service = $this->expectRefreshes([1001]);

        RefreshBuildRecommendationBatchJob::start([1001], 42, 7);
        Queue::assertPushed(RefreshBuildRecommendationBatchJob::class, 1);

        $this->currentBatchJob([1001])->handle($service);
        $this->assertNotInstanceOf(ShouldBeUnique::class, $this->currentBatchJob([1001]));
    }

    #[Test]
    public function a_write_interrupted_before_commit_leaves_the_previous_recommendation_untouched(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $nation = $this->eligibleNationWithCity(1);
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = $this->worldSnapshot();
        $service = app(NationBuildRecommendationService::class);
        $previous = $service->refreshStoredRecommendationForNationId($nation->id, $marketSnapshot->id, $worldSnapshot->id);
        $this->assertNotNull($previous);
        $before = NationBuildRecommendation::query()->findOrFail($previous->id)->getAttributes();
        $this->travel(10)->minutes();
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update "nation_build_recommendations"')) {
                $updates++;

                throw new RuntimeException('Worker stopped mid-write.');
            }
        });

        try {
            $service->refreshStoredRecommendationForNationId($nation->id, $marketSnapshot->id, $worldSnapshot->id);
            $this->fail('The interrupted write should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Worker stopped mid-write.', $exception->getMessage());
        }

        $this->assertGreaterThanOrEqual(1, $updates);
        $this->assertSame($before, NationBuildRecommendation::query()->findOrFail($previous->id)->getAttributes());
        $this->assertSame(1, NationBuildRecommendation::query()->where('nation_id', $nation->id)->count());
    }

    #[Test]
    public function job_timeouts_stay_below_the_queue_retry_after_with_explicit_retry_limits(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $single = new RefreshNationBuildRecommendationJob(1001, 42, 7);
        $batch = new RefreshBuildRecommendationBatchJob([1001], 42, 7, 'token');

        $this->assertLessThan($retryAfter, $single->timeout);
        $this->assertLessThan($retryAfter, $batch->timeout);
        $this->assertGreaterThanOrEqual(90, $single->timeout);
        $this->assertGreaterThanOrEqual(90, $batch->timeout);
        $this->assertSame(3, $single->tries);
        $this->assertSame([30, 120], $single->backoff);
        $this->assertSame(2, $batch->tries);
        $this->assertSame([30], $batch->backoff);
    }

    #[Test]
    public function single_nation_jobs_queued_by_the_previous_release_still_run(): void
    {
        $class = RefreshNationBuildRecommendationJob::class;
        $payload = sprintf(
            'O:%d:"%s":4:{s:8:"nationId";i:1001;s:21:"marketPriceSnapshotId";i:42;s:19:"radiationSnapshotId";i:7;s:5:"queue";s:7:"default";}',
            strlen($class),
            $class
        );
        $job = unserialize($payload);
        $service = $this->expectRefreshes([1001]);

        $this->assertInstanceOf(RefreshNationBuildRecommendationJob::class, $job);
        $job->handle($service);
    }

    /**
     * @param  list<int>  $nationIds
     */
    private function expectRefreshes(array $nationIds): NationBuildRecommendationService
    {
        $service = Mockery::mock(NationBuildRecommendationService::class);

        if ($nationIds === []) {
            $service->shouldNotReceive('refreshStoredRecommendationForNationId');

            return $service;
        }

        foreach (array_count_values($nationIds) as $nationId => $times) {
            $service->shouldReceive('refreshStoredRecommendationForNationId')
                ->times($times)
                ->with($nationId, 42, 7)
                ->andReturnNull();
        }

        return $service;
    }

    /**
     * @param  list<int>  $nationIds
     */
    private function currentBatchJob(array $nationIds): RefreshBuildRecommendationBatchJob
    {
        $batchToken = Cache::get(RefreshBuildRecommendationBatchJob::CURRENT_BATCH_CACHE_KEY) ?? 'current-batch';
        Cache::put(RefreshBuildRecommendationBatchJob::CURRENT_BATCH_CACHE_KEY, $batchToken, now()->addHour());

        return new RefreshBuildRecommendationBatchJob($nationIds, 42, 7, $batchToken);
    }

    private function eligibleNationWithCity(int $index): Nation
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
            'continent' => 'NA',
            'num_cities' => 1,
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now(),
        ]);
        City::query()->create([
            'nation_id' => $nation->id,
            'name' => "Build City {$index}",
            'date' => '2025-08-02',
            'nuke_date' => null,
            'infrastructure' => 100 + ($index * 50),
            'land' => 500,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'wind_power' => 1,
        ]);

        return $nation;
    }

    private function marketSnapshot(): MarketPriceSnapshot
    {
        $snapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'test completed-market prices',
            'window_started_at' => now()->subDays(7),
            'window_ended_at' => now(),
            'calculated_at' => now(),
        ]);
        $snapshot->items()->createMany(collect(EconomyRules::TRADE_RESOURCES)
            ->map(fn (string $resource): array => [
                'resource' => $resource,
                'acquisition_price' => 100,
                'liquidation_price' => 90,
            ])->all());

        return $snapshot;
    }

    private function worldSnapshot(): RadiationSnapshot
    {
        return RadiationSnapshot::query()->create([
            'snapshot_at' => now()->subHours(4),
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);
    }
}
