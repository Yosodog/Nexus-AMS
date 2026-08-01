<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class InactiveUserAutoDisableSecurityTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_missing_auto_disable_setting_fails_safe_without_enabling_it(): void
    {
        Setting::query()
            ->where('key', 'user_inactivity_auto_disable_enabled')
            ->delete();

        $this->assertFalse(SettingService::isUserInactivityAutoDisableEnabled());
        $this->assertDatabaseMissing('settings', [
            'key' => 'user_inactivity_auto_disable_enabled',
        ]);
    }

    public function test_finance_admin_cannot_change_user_auto_disable_settings(): void
    {
        $financeAdmin = $this->adminWithPermissions(910001, ['manage-accounts']);

        $this->actingAs($financeAdmin)
            ->post(route('admin.settings.account-inactivity-auto-disable'), [
                'user_inactivity_auto_disable_enabled' => true,
                'user_inactivity_auto_disable_days' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', [
            'key' => 'user_inactivity_auto_disable_enabled',
        ]);
    }

    public function test_user_manager_can_change_user_auto_disable_settings(): void
    {
        $userManager = $this->adminWithPermissions(910002, ['edit-users']);

        $this->actingAs($userManager)
            ->post(route('admin.settings.account-inactivity-auto-disable'), [
                'user_inactivity_auto_disable_enabled' => true,
                'user_inactivity_auto_disable_days' => 120,
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertTrue(SettingService::isUserInactivityAutoDisableEnabled());
        $this->assertSame(120, SettingService::getUserInactivityAutoDisableDays());
    }

    public function test_command_disables_ordinary_inactive_users_but_preserves_protected_admins(): void
    {
        SettingService::setUserInactivityAutoDisableEnabled(true);
        SettingService::setUserInactivityAutoDisableDays(90);

        $inactiveAt = now()->subDays(120);
        $protectedAdmin = User::factory()->admin()->create([
            'last_active_at' => $inactiveAt,
            'created_at' => $inactiveAt,
        ]);
        $protectedRole = Role::factory()->create([
            'name' => 'default admin',
            'protected' => true,
        ]);
        $protectedAdmin->roles()->attach($protectedRole);
        $unprotectedAdmin = User::factory()->admin()->create([
            'last_active_at' => $inactiveAt,
            'created_at' => $inactiveAt,
        ]);

        $inactiveUser = User::factory()->create([
            'last_active_at' => $inactiveAt,
            'created_at' => $inactiveAt,
        ]);
        $activeUser = User::factory()->create([
            'last_active_at' => now()->subDay(),
        ]);

        $this->artisan('users:disable-inactive')->assertSuccessful();

        $this->assertFalse($protectedAdmin->fresh()->disabled);
        $this->assertFalse($unprotectedAdmin->fresh()->disabled);
        $this->assertTrue($inactiveUser->fresh()->disabled);
        $this->assertFalse($activeUser->fresh()->disabled);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function adminWithPermissions(int $nationId, array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1_000_000)]);

        return $this->grantPermissions($admin, $permissions);
    }
}
