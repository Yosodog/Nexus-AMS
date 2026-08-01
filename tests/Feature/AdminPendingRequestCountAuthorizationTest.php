<?php

namespace Tests\Feature;

use App\Livewire\Admin\AppSidebar;
use App\Models\User;
use App\Services\LoanService;
use App\Services\PendingRequestsService;
use App\Services\RebuildingService;
use App\Services\WarAidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminPendingRequestCountAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_admin_without_management_permission_does_not_see_pending_count(): void
    {
        $this->bindPendingRequestsService(['loans' => 7]);
        $admin = $this->createAdmin(['view-loans']);

        $this->actingAs($admin);

        Livewire::test(AppSidebar::class)
            ->assertSee('Loans')
            ->assertDontSee('7 pending')
            ->assertDontSeeHtml('aria-label="7 pending"');
    }

    public function test_admin_with_management_permission_sees_matching_pending_count(): void
    {
        $this->bindPendingRequestsService(['loans' => 7]);
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);

        $this->actingAs($admin);

        Livewire::test(AppSidebar::class)
            ->assertSee('Loans')
            ->assertSee('7 pending')
            ->assertSeeHtml('aria-label="7 pending"');
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function bindPendingRequestsService(array $counts): void
    {
        $service = Mockery::mock(PendingRequestsService::class, [
            $this->createMock(LoanService::class),
            $this->createMock(WarAidService::class),
            $this->createMock(RebuildingService::class),
        ])->makePartial();
        $service->shouldReceive('getRawCounts')->once()->andReturn($counts);

        $this->app->instance(PendingRequestsService::class, $service);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(450_000, 499_999)]);

        return $this->grantPermissions($admin, $permissions);
    }
}
