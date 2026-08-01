<?php

namespace Tests\Feature;

use App\Models\Offshore;
use App\Models\User;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminAccountLiquidityAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_account_viewer_does_not_fetch_or_see_alliance_liquidity(): void
    {
        $mainBankService = Mockery::mock(MainBankService::class);
        $mainBankService->shouldNotReceive('getCachedSnapshot');
        $this->app->instance(MainBankService::class, $mainBankService);

        $offshoreService = Mockery::mock(OffshoreService::class);
        $offshoreService->shouldNotReceive('all');
        $offshoreService->shouldNotReceive('getCachedSnapshot');
        $this->app->instance(OffshoreService::class, $offshoreService);

        $admin = $this->createAdmin(['view-accounts']);

        $this->actingAs($admin)
            ->get(route('admin.accounts.dashboard'))
            ->assertOk()
            ->assertDontSee('Alliance Liquidity')
            ->assertDontSee('Alliance Holdings')
            ->assertDontSee('Resource Ownership')
            ->assertDontSee('$87,654,321.00', false)
            ->assertDontSee('$12,345,678.00', false);
    }

    public function test_offshore_or_financial_report_viewer_fetches_and_sees_alliance_liquidity(): void
    {
        $offshore = new Offshore;
        $offshore->forceFill(['id' => 91]);

        $mainBankService = Mockery::mock(MainBankService::class);
        $mainBankService->shouldReceive('getCachedSnapshot')->twice()->andReturn([
            'balances' => ['money' => 87_654_321],
            'cached_at' => now(),
        ]);
        $this->app->instance(MainBankService::class, $mainBankService);

        $offshoreService = Mockery::mock(OffshoreService::class);
        $offshoreService->shouldReceive('all')->twice()->andReturn(collect([$offshore]));
        $offshoreService->shouldReceive('getCachedSnapshot')->twice()->with($offshore)->andReturn([
            'balances' => ['money' => 12_345_678],
            'cached_at' => now(),
        ]);
        $this->app->instance(OffshoreService::class, $offshoreService);

        foreach (['view-offshores', 'view-financial-reports'] as $permission) {
            $admin = $this->createAdmin(['view-accounts', $permission]);

            $this->actingAs($admin)
                ->get(route('admin.accounts.dashboard'))
                ->assertOk()
                ->assertSee('Alliance Liquidity')
                ->assertSee('Alliance Holdings')
                ->assertSee('Resource Ownership')
                ->assertSee('$87,654,321.00', false)
                ->assertSee('$12,345,678.00', false);
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(700_000, 799_999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}
