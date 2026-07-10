<?php

namespace Tests\Feature;

use App\Livewire\Admin\AppSidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminPresentationAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_member_viewers_can_read_member_pages_without_account_management_access(): void
    {
        $admin = $this->createAdmin(['view-members']);

        $this->actingAs($admin)
            ->get(route('admin.members'))
            ->assertOk();
    }

    public function test_member_viewers_cannot_change_inactivity_settings(): void
    {
        $admin = $this->createAdmin(['view-members']);

        $this->actingAs($admin)
            ->post(route('admin.members.inactivity-settings'), [
                'inactivity_enabled' => true,
                'inactivity_threshold_hours' => 168,
                'inactivity_cooldown_hours' => 24,
                'inactivity_actions' => [],
            ])
            ->assertForbidden();
    }

    public function test_nel_reference_requires_diagnostic_permission(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.nel.docs'))
            ->assertForbidden();

        $diagnosticAdmin = $this->createAdmin(['view-diagnostic-info'], 930002);

        $this->actingAs($diagnosticAdmin)
            ->get(route('admin.nel.docs'))
            ->assertOk();
    }

    public function test_mmr_navigation_uses_the_mmr_permission(): void
    {
        $admin = $this->createAdmin(['view-mmr']);

        $this->actingAs($admin);

        Livewire::test(AppSidebar::class)
            ->assertSee('MMR')
            ->assertDontSee('Raids');
    }

    public function test_direct_deposit_routes_do_not_repeat_the_admin_prefix(): void
    {
        $this->assertSame(
            url('/admin/direct-deposit/settings'),
            route('admin.dd.settings'),
        );

        $this->assertStringNotContainsString('/admin/admin/', route('admin.dd.brackets.create'));
        $this->assertStringNotContainsString('/admin/admin/', route('admin.dd.brackets.update'));
        $this->assertStringNotContainsString('/admin/admin/', route('admin.dd.brackets.delete'));
    }

    public function test_main_bank_refresh_route_is_registered_once(): void
    {
        $matches = collect(Route::getRoutes())->filter(
            fn ($route): bool => $route->uri() === 'admin/offshores/main-bank/refresh'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertCount(1, $matches);
    }

    public function test_feature_managers_can_open_settings_without_diagnostic_access(): void
    {
        $accountManager = $this->createAdmin(['manage-accounts'], 930003);

        $this->actingAs($accountManager)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Auto Withdraw')
            ->assertSee('Account Inactivity Auto-Disable')
            ->assertDontSee('Data Synchronization')
            ->assertDontSee('Loan Payments')
            ->assertDontSee('Grant Approvals');

        $loanManager = $this->createAdmin(['manage-loans'], 930004);

        $this->actingAs($loanManager)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Loan Payments')
            ->assertDontSee('Pending Request Recovery');

        $grantManager = $this->createAdmin(['manage-grants'], 930005);

        $this->actingAs($grantManager)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Grant Approvals')
            ->assertDontSee('Homepage Messaging');
    }

    public function test_admin_without_settings_permissions_cannot_open_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertForbidden();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions = [], int $nationId = 930001): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1_000_000)]);

        return $permissions === [] ? $admin : $this->grantPermissions($admin, $permissions);
    }
}
