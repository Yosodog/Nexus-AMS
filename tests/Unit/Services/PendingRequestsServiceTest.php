<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\LoanService;
use App\Services\PendingRequestsService;
use App\Services\RebuildingService;
use App\Services\WarAidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\FeatureTestCase;

class PendingRequestsServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pending_requests.permissions', [
            'grants' => 'manage-grants',
            'loans' => 'manage-loans',
            'war_aid' => 'manage-war-aid',
        ]);
    }

    public function test_get_counts_for_user_filters_using_gate_permissions(): void
    {
        $user = User::factory()->make(['is_admin' => false]);
        $gate = new class
        {
            public function allows(?string $ability): bool
            {
                return $ability === 'manage-loans';
            }
        };

        Gate::shouldReceive('forUser')->once()->with($user)->andReturn($gate);

        $service = \Mockery::mock(PendingRequestsService::class, [
            $this->createMock(LoanService::class),
            $this->createMock(WarAidService::class),
            $this->createMock(RebuildingService::class),
        ])->makePartial();
        $service->shouldReceive('getRawCounts')->once()->andReturn([
            'grants' => 2,
            'loans' => 3,
            'war_aid' => 4,
        ]);

        $counts = $service->getCountsForUser($user);

        $this->assertSame(['loans' => 3], $counts['counts']);
        $this->assertSame(3, $counts['total']);
    }

    public function test_get_counts_for_admin_returns_all_counts(): void
    {
        $user = User::factory()->make(['is_admin' => true]);
        Gate::shouldReceive('forUser')->once()->andReturn(new class
        {
            public function allows(?string $ability): bool
            {
                return false;
            }
        });

        $service = \Mockery::mock(PendingRequestsService::class, [
            $this->createMock(LoanService::class),
            $this->createMock(WarAidService::class),
            $this->createMock(RebuildingService::class),
        ])->makePartial();
        $service->shouldReceive('getRawCounts')->once()->andReturn([
            'grants' => 2,
            'loans' => 3,
            'war_aid' => 4,
        ]);

        $counts = $service->getCountsForUser($user);

        $this->assertSame(['grants' => 2, 'loans' => 3, 'war_aid' => 4], $counts['counts']);
        $this->assertSame(9, $counts['total']);
    }

    public function test_get_raw_counts_uses_cache_key_and_flush_cache_clears_it(): void
    {
        $loanService = $this->createMock(LoanService::class);
        $loanService->expects($this->once())->method('countPending')->willReturn(5);
        $warAidService = $this->createMock(WarAidService::class);
        $warAidService->expects($this->once())->method('countPending')->willReturn(6);
        $rebuildingService = $this->createMock(RebuildingService::class);
        $rebuildingService->expects($this->once())->method('countPending')->willReturn(7);

        Cache::forget(PendingRequestsService::CACHE_KEY);
        $service = new PendingRequestsService($loanService, $warAidService, $rebuildingService);

        $first = $service->getRawCounts();
        $second = $service->getRawCounts();
        $service->flushCache();

        $this->assertSame($first, $second);
        $this->assertNull(Cache::get(PendingRequestsService::CACHE_KEY));
    }
}
