<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AppSidebar;
use App\Models\User;
use App\Services\Admin\AdminNavigationCatalog;
use App\Services\PendingRequestsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminSidebarNavigationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_catalog_assigns_each_destination_to_one_stable_navigation_area(): void
    {
        $admin = $this->createAdmin($this->allNavigationPermissions());
        $counts = [
            'applications' => 2,
            'city_grants' => 3,
            'grants' => 4,
            'loans' => 5,
            'withdrawals' => 6,
            'member_transfers' => 7,
            'war_aid' => 8,
            'rebuilding' => 9,
            'blockade_relief' => 10,
            'audit_remediation' => 11,
        ];

        $groups = collect(app(AdminNavigationCatalog::class)->groups($admin, $counts));

        $this->assertSame(
            ['workspace', 'economics', 'defense', 'internal-affairs', 'system'],
            $groups->pluck('id')->all(),
        );
        $this->assertSame(
            ['work-queue', 'overview'],
            collect($groups->firstWhere('id', 'workspace')['items'])->pluck('id')->all(),
        );
        $this->assertSame([
            'city-grants',
            'grant-programs',
            'growth-circles',
            'loans',
            'accounts',
            'taxes',
            'offshores',
            'finance-ledger',
            'payroll',
            'alliance-market',
            'lottery',
        ], collect($groups->firstWhere('id', 'economics')['items'])->pluck('id')->all());
        $this->assertSame(
            ['Programs & funding', 'Treasury', 'Commerce & rewards'],
            collect($groups->firstWhere('id', 'economics')['sections'])->pluck('label')->all(),
        );
        $this->assertSame(
            ['accounts', 'taxes', 'offshores', 'finance-ledger', 'payroll'],
            collect($groups->firstWhere('id', 'economics')['sections'][1]['items'])->pluck('id')->all(),
        );
        $this->assertSame(
            ['alliance-market', 'lottery'],
            collect($groups->firstWhere('id', 'economics')['sections'][2]['items'])->pluck('id')->all(),
        );
        $this->assertSame([
            'milcom',
            'wars',
            'spy-campaigns',
            'war-aid',
            'rebuilding',
            'raids',
            'beige-alerts',
            'mmr',
        ], collect($groups->firstWhere('id', 'defense')['items'])->pluck('id')->all());
        $this->assertSame(
            ['Operations', 'Member support', 'Targets & readiness'],
            collect($groups->firstWhere('id', 'defense')['sections'])->pluck('label')->all(),
        );
        $this->assertSame([
            'applications',
            'recruitment',
            'members',
            'cities',
            'audits',
        ], collect($groups->firstWhere('id', 'internal-affairs')['items'])->pluck('id')->all());
        $this->assertSame(
            ['Membership', 'Governance & compliance'],
            collect($groups->firstWhere('id', 'internal-affairs')['sections'])->pluck('label')->all(),
        );
        $this->assertSame([
            'users',
            'roles',
            'settings',
            'federation',
            'custom-pages',
            'audit-logs',
            'telescope',
            'pulse',
            'log-viewer',
        ], collect($groups->firstWhere('id', 'system')['items'])->pluck('id')->all());
        $this->assertSame('Administration', $groups->firstWhere('id', 'system')['label']);
        $this->assertSame(
            ['Access control', 'Configuration', 'Diagnostics'],
            collect($groups->firstWhere('id', 'system')['sections'])->pluck('label')->all(),
        );
        $this->assertSame(25, $groups->firstWhere('id', 'economics')['badge']);
        $this->assertSame(27, $groups->firstWhere('id', 'defense')['badge']);
        $this->assertSame(13, $groups->firstWhere('id', 'internal-affairs')['badge']);

        $items = $groups->flatMap(fn (array $group): array => $group['items']);
        $this->assertCount($items->count(), $items->pluck('id')->unique());
        $this->assertCount($items->count(), $items->pluck('route')->unique());
        $this->assertNotContains('grants-workspace', $items->pluck('id'));
        $this->assertNotContains('war-support', $items->pluck('id'));
        $this->assertNotContains('Foreign Affairs', $groups->pluck('label'));
    }

    public function test_catalog_hides_empty_departments_and_only_marks_the_active_department(): void
    {
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);
        $route = new Route(['GET'], 'admin/loans', fn () => null);
        $route->name('admin.loans');
        request()->setRouteResolver(fn (): Route => $route);

        $groups = collect(app(AdminNavigationCatalog::class)->groups($admin, ['loans' => 2]));

        $this->assertSame(['workspace', 'economics', 'system'], $groups->pluck('id')->all());
        $this->assertTrue($groups->firstWhere('id', 'economics')['active']);
        $this->assertFalse($groups->firstWhere('id', 'workspace')['active']);
        $this->assertSame(['loans'], collect($groups->firstWhere('id', 'economics')['items'])->pluck('id')->all());
        $this->assertSame(['Programs & funding'], collect($groups->firstWhere('id', 'economics')['sections'])->pluck('label')->all());
        $this->assertTrue($groups->firstWhere('id', 'economics')['sections'][0]['active']);

        $settingsRoute = new Route(['GET'], 'admin/settings/public-site', fn () => null);
        $settingsRoute->name('admin.settings.public-site');
        request()->setRouteResolver(fn (): Route => $settingsRoute);
        $settingsGroups = collect(app(AdminNavigationCatalog::class)->groups($admin));

        $this->assertTrue($settingsGroups->firstWhere('id', 'system')['active']);
    }

    public function test_sidebar_renders_department_categories_and_direct_pin_controls(): void
    {
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);
        $this->mock(PendingRequestsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCountsForUser')->once()->andReturn([
                'counts' => ['loans' => 2],
                'total' => 2,
                'complete' => true,
                'unavailable' => [],
            ]);
        });

        $this->actingAs($admin);

        Livewire::test(AppSidebar::class)
            ->assertSee('data-command-palette-open', false)
            ->assertSee('data-admin-navigation-id="work-queue"', false)
            ->assertSee('data-admin-department="economics"', false)
            ->assertSee('data-admin-department-count="2"', false)
            ->assertSee('aria-labelledby="admin-nav-department-economics"', false)
            ->assertSee('data-admin-navigation-section="economics.programs-funding"', false)
            ->assertSee('aria-controls="admin-nav-section-economics-programs-funding"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('data-admin-quick-access-template="loans"', false)
            ->assertSee('data-admin-pin-navigation="loans"', false)
            ->assertDontSee('data-admin-quick-access-template="members"', false)
            ->assertDontSee('Alliance departments')
            ->assertDontSee('Foreign Affairs');
    }

    public function test_sidebar_surfaces_incomplete_queue_projection_status(): void
    {
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);
        $this->mock(PendingRequestsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCountsForUser')->once()->andReturn([
                'counts' => [],
                'total' => 0,
                'complete' => false,
                'unavailable' => ['loans' => ['label' => 'Loans']],
            ]);
        });

        $this->actingAs($admin);

        Livewire::test(AppSidebar::class)
            ->assertSee('aria-label="Work queue, queue data incomplete"', false)
            ->assertSee('1 queue source unavailable.');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        return $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(800000, 899999)]),
            $permissions,
        );
    }

    /** @return list<string> */
    private function allNavigationPermissions(): array
    {
        return [
            'view-accounts',
            'manage-accounts',
            'view-applications',
            'manage-applications',
            'view-grants',
            'manage-grants',
            'view-city-grants',
            'manage-city-grants',
            'view-loans',
            'manage-loans',
            'view-members',
            'view-audits',
            'manage-audits',
            'view-recruitment',
            'view-growth-circles',
            'view-taxes',
            'view-offshores',
            'view-financial-reports',
            'view_payroll',
            'view-market',
            'view-lottery',
            'manage-war-room',
            'view-wars',
            'view-war-aid',
            'manage-war-aid',
            'view-rebuilding',
            'manage-rebuilding',
            'view-raids',
            'view-spies',
            'view-mmr',
            'view-users',
            'view-roles',
            'view-settings',
            'view-federation',
            'manage-custom-pages',
            'view-diagnostic-info',
            'view-application-logs',
        ];
    }
}
