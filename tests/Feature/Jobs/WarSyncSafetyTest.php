<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FinalizeWarSyncJob;
use App\Jobs\SyncWarsJob;
use App\Models\War;
use App\Services\AllianceMembershipService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WarSyncSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_exceptions_are_propagated_to_the_batch(): void
    {
        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('getPrimaryAllianceId')
            ->once()
            ->andThrow(new RuntimeException('Alliance lookup failed.'));
        $this->app->instance(AllianceMembershipService::class, $membershipService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Alliance lookup failed.');

        (new SyncWarsJob(1, 1000))->handle();
    }

    public function test_empty_page_is_treated_as_a_failed_sync(): void
    {
        config(['services.pw.alliance_id' => 123]);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'wars' => [
                        'data' => [],
                        'paginatorInfo' => [
                            'perPage' => 1000,
                            'count' => 0,
                            'lastPage' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('War sync page 1 returned no records.');

        (new SyncWarsJob(1, 1000))->handle();
    }

    public function test_finalizer_skips_cleanup_when_expected_page_manifest_is_truncated(): void
    {
        $batchId = 'truncated-war-sync';
        $staleWar = $this->createActiveWar(9001, now()->subDays(6));
        $this->fakeCompletedBatch($batchId, totalJobs: 2);

        Cache::put("sync_batch:{$batchId}:pages", [1], now()->addHour());
        Cache::put("sync_batch:{$batchId}:1", [1001], now()->addHour());

        (new FinalizeWarSyncJob($batchId))->handle();

        $this->assertNull($staleWar->refresh()->end_date);
    }

    public function test_finalizer_skips_cleanup_when_an_expected_page_has_no_result(): void
    {
        $batchId = 'missing-war-sync-page';
        $staleWar = $this->createActiveWar(9002, now()->subDays(6));
        $this->fakeCompletedBatch($batchId, totalJobs: 2);

        Cache::put("sync_batch:{$batchId}:pages", [1, 2], now()->addHour());
        Cache::put("sync_batch:{$batchId}:1", [1001], now()->addHour());

        (new FinalizeWarSyncJob($batchId))->handle();

        $this->assertNull($staleWar->refresh()->end_date);
    }

    public function test_finalizer_ends_only_stale_wars_after_every_expected_page_completes(): void
    {
        $batchId = 'complete-war-sync';
        $seenWar = $this->createActiveWar(1001, now()->subDays(6));
        $staleWar = $this->createActiveWar(9003, now()->subDays(6));
        $recentWar = $this->createActiveWar(9004, now()->subDay());
        $this->fakeCompletedBatch($batchId, totalJobs: 2);

        Cache::put("sync_batch:{$batchId}:pages", [1, 2], now()->addHour());
        Cache::put("sync_batch:{$batchId}:1", [1001], now()->addHour());
        Cache::put("sync_batch:{$batchId}:2", [1002], now()->addHour());

        (new FinalizeWarSyncJob($batchId))->handle();

        $this->assertNull($seenWar->refresh()->end_date);
        $this->assertNotNull($staleWar->refresh()->end_date);
        $this->assertNull($recentWar->refresh()->end_date);
    }

    private function fakeCompletedBatch(string $batchId, int $totalJobs): void
    {
        $batch = new Batch(
            Mockery::mock(QueueFactory::class),
            Mockery::mock(BatchRepository::class),
            $batchId,
            'War Sync',
            $totalJobs,
            0,
            0,
            [],
            [],
            CarbonImmutable::now(),
        );

        Bus::shouldReceive('findBatch')
            ->once()
            ->with($batchId)
            ->andReturn($batch);
    }

    private function createActiveWar(int $id, DateTimeInterface $date): War
    {
        return War::query()->create([
            'id' => $id,
            'date' => $date,
            'end_date' => null,
            'reason' => 'War sync safety test',
            'turns_left' => 1,
            'att_id' => $id + 10_000,
            'def_id' => $id + 20_000,
        ]);
    }
}
