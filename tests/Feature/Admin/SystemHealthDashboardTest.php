<?php

namespace Tests\Feature\Admin;

use App\Models\TaxImportCheckpoint;
use App\Models\User;
use App\Services\PWHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class SystemHealthDashboardTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.pw.alliance_id', 777);
        Cache::put(PWHealthService::CACHE_KEY_STATUS, true, 600);
        Cache::put(PWHealthService::CACHE_KEY_CHECKED_AT, now()->toIso8601String(), 600);
    }

    public function test_diagnostic_admin_can_see_system_health_and_tax_import_times(): void
    {
        TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 1234,
            'last_attempted_at' => now()->subMinutes(10),
            'last_succeeded_at' => now()->subMinutes(10),
            'last_imported_at' => now()->subHour(),
        ]);
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->get(route('admin.settings.system-health'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('Tax records')
            ->assertSee('Last tax record imported')
            ->assertSee('Scheduler &amp; P&amp;W API', false);
    }

    public function test_non_diagnostic_settings_admin_does_not_receive_health_details(): void
    {
        $admin = $this->createAdmin(['manage-accounts'], 940002);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertDontSee('System Health')
            ->assertDontSee('Scheduler &amp; P&amp;W API', false);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions, int $nationId = 940001): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1_000_000)]);

        return $this->grantPermissions($admin, $permissions);
    }
}
