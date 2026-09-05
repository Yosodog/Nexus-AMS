<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\RaidFinderCache;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RaidFinderSettingsTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_raid_view_exposes_the_activity_policy_controls(): void
    {
        SettingService::setRaidActivityCityThreshold(15);
        SettingService::setRaidMinimumInactiveTurns(18);
        $admin = $this->createAdmin(['view-raids']);

        $this->actingAs($admin)
            ->get(route('admin.raids.index'))
            ->assertOk()
            ->assertSee('Target activity')
            ->assertSee('name="raid_activity_city_threshold"', false)
            ->assertSee('value="15"', false)
            ->assertSee('name="raid_minimum_inactive_turns"', false)
            ->assertSee('value="18"', false);
    }

    public function test_manager_can_update_the_activity_policy_and_invalidate_cached_targets(): void
    {
        $admin = $this->createAdmin(['view-raids', 'manage-raids']);
        $cache = app(RaidFinderCache::class);

        $this->assertSame('raid-finder:v1:4242', $cache->key(4242));

        $this->actingAs($admin)
            ->post(route('admin.raids.top-cap.update'), [
                'top_cap' => 35,
                'raid_activity_city_threshold' => 12,
                'raid_minimum_inactive_turns' => 24,
            ])
            ->assertRedirect(route('admin.raids.index'));

        $this->assertSame(35, SettingService::getTopRaidable());
        $this->assertSame(12, SettingService::getRaidActivityCityThreshold());
        $this->assertSame(24, SettingService::getRaidMinimumInactiveTurns());
        $this->assertSame('raid-finder:v2:4242', $cache->key(4242));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'category' => 'settings',
            'action' => 'raid_finder_settings_updated',
            'outcome' => 'success',
        ]);
    }

    public function test_activity_policy_rejects_values_outside_the_supported_range(): void
    {
        $admin = $this->createAdmin(['view-raids', 'manage-raids']);

        $this->actingAs($admin)
            ->from(route('admin.raids.index'))
            ->post(route('admin.raids.top-cap.update'), [
                'top_cap' => 35,
                'raid_activity_city_threshold' => 1001,
                'raid_minimum_inactive_turns' => 4381,
            ])
            ->assertRedirect(route('admin.raids.index'))
            ->assertSessionHasErrors([
                'raid_activity_city_threshold',
                'raid_minimum_inactive_turns',
            ]);

        $this->assertDatabaseMissing('settings', ['key' => 'raid_activity_city_threshold']);
        $this->assertDatabaseMissing('settings', ['key' => 'raid_minimum_inactive_turns']);
    }

    public function test_legacy_top_cap_update_preserves_the_activity_policy(): void
    {
        SettingService::setRaidActivityCityThreshold(16);
        SettingService::setRaidMinimumInactiveTurns(30);
        $admin = $this->createAdmin(['view-raids', 'manage-raids']);

        $this->actingAs($admin)
            ->post(route('admin.raids.top-cap.update'), ['top_cap' => 25])
            ->assertRedirect(route('admin.raids.index'));

        $this->assertSame(25, SettingService::getTopRaidable());
        $this->assertSame(16, SettingService::getRaidActivityCityThreshold());
        $this->assertSame(30, SettingService::getRaidMinimumInactiveTurns());
    }

    public function test_viewer_cannot_update_the_activity_policy(): void
    {
        $admin = $this->createAdmin(['view-raids']);

        $this->actingAs($admin)
            ->post(route('admin.raids.top-cap.update'), [
                'top_cap' => 35,
                'raid_activity_city_threshold' => 12,
                'raid_minimum_inactive_turns' => 24,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'raid_activity_city_threshold']);
        $this->assertDatabaseMissing('settings', ['key' => 'raid_minimum_inactive_turns']);
    }

    /** @param array<int, string> $permissions */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}
