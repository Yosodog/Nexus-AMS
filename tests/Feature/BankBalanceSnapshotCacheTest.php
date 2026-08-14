<?php

namespace Tests\Feature;

use App\Jobs\RefreshBankBalanceSnapshots;
use App\Models\Offshore;
use App\Services\AllianceMembershipService;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\QueryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BankBalanceSnapshotCacheTest extends TestCase
{
    public function test_main_bank_dashboard_snapshot_does_not_fetch_live_data_on_a_cache_miss(): void
    {
        Cache::forget('offshores:main:balances');

        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldNotReceive('sendQuery');
        $this->app->instance(QueryService::class, $queryService);

        $snapshot = $this->mainBankService()->getCachedSnapshot();

        $this->assertSame([], $snapshot['balances']);
        $this->assertNull($snapshot['cached_at']);
    }

    public function test_offshore_dashboard_snapshot_does_not_fetch_live_data_on_a_cache_miss(): void
    {
        $offshore = $this->offshore();
        Cache::forget('offshores:'.$offshore->id.':balances');

        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldNotReceive('sendQuery');
        $this->app->instance(QueryService::class, $queryService);

        $snapshot = $this->offshoreService()->getCachedSnapshot($offshore);

        $this->assertSame([], $snapshot['balances']);
        $this->assertNull($snapshot['cached_at']);
    }

    public function test_failed_main_bank_refresh_preserves_the_last_good_snapshot(): void
    {
        $cachedAt = Carbon::parse('2026-08-13 12:00:00');
        Cache::put('offshores:main:balances', [
            'balances' => ['money' => 125_000_000.0],
            'cached_at' => $cachedAt,
        ]);

        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldReceive('sendQuery')
            ->once()
            ->andThrow(new ConnectionException('Politics & War is unavailable.'));
        $this->app->instance(QueryService::class, $queryService);

        $this->assertSame([], $this->mainBankService()->refreshBalances());

        $this->assertSame(125_000_000.0, Cache::get('offshores:main:balances')['balances']['money']);
        $this->assertTrue($cachedAt->equalTo(Cache::get('offshores:main:balances')['cached_at']));
    }

    public function test_failed_offshore_refresh_preserves_the_last_good_snapshot(): void
    {
        $offshore = $this->offshore();
        $cacheKey = 'offshores:'.$offshore->id.':balances';
        $cachedAt = Carbon::parse('2026-08-13 12:00:00');
        Cache::put($cacheKey, [
            'balances' => ['money' => 250_000_000.0],
            'cached_at' => $cachedAt,
        ]);

        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldReceive('sendQuery')
            ->once()
            ->andThrow(new ConnectionException('Politics & War is unavailable.'));
        $this->app->instance(QueryService::class, $queryService);

        $this->assertSame([], $this->offshoreService()->refreshBalances($offshore));

        $this->assertSame(250_000_000.0, Cache::get($cacheKey)['balances']['money']);
        $this->assertTrue($cachedAt->equalTo(Cache::get($cacheKey)['cached_at']));
    }

    public function test_refresh_job_updates_the_main_bank_and_every_enabled_offshore(): void
    {
        $firstOffshore = $this->offshore();
        $secondOffshore = $this->offshore();
        $secondOffshore->forceFill(['id' => 18]);

        $mainBank = Mockery::mock(MainBankService::class);
        $mainBank->shouldReceive('refreshBalances')->once()->andReturn(['money' => 1.0]);

        $offshores = Mockery::mock(OffshoreService::class);
        $offshores->shouldReceive('all')->once()->andReturn(collect([$firstOffshore, $secondOffshore]));
        $offshores->shouldReceive('refreshBalances')->once()->with($firstOffshore)->andReturn(['money' => 2.0]);
        $offshores->shouldReceive('refreshBalances')->once()->with($secondOffshore)->andReturn(['money' => 3.0]);

        $job = new RefreshBankBalanceSnapshots;

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $job->handle($mainBank, $offshores);
    }

    private function mainBankService(): MainBankService
    {
        $membership = Mockery::mock(AllianceMembershipService::class);
        $membership->shouldReceive('getPrimaryAllianceId')->andReturn(9001);

        return new MainBankService($membership);
    }

    private function offshoreService(): OffshoreService
    {
        return new OffshoreService(Mockery::mock(AllianceMembershipService::class));
    }

    private function offshore(): Offshore
    {
        $offshore = new Offshore;
        $offshore->forceFill([
            'id' => 17,
            'alliance_id' => 9002,
        ]);

        return $offshore;
    }
}
