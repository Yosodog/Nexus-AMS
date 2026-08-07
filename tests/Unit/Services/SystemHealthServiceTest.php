<?php

namespace Tests\Unit\Services;

use App\Models\Alliance;
use App\Models\ScheduledTaskRun;
use App\Models\TaxImportCheckpoint;
use App\Services\AllianceMembershipService;
use App\Services\PWHealthService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-05 12:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_snapshot_distinguishes_a_successful_tax_poll_from_a_new_tax_import(): void
    {
        $this->cacheHealthyHeartbeat();
        TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 1234,
            'last_attempted_at' => now()->subMinutes(20),
            'last_succeeded_at' => now()->subMinutes(20),
            'last_imported_at' => now()->subHours(4),
        ]);

        $snapshot = $this->serviceForAlliances([777])->snapshot();
        $taxCheck = $this->check($snapshot, 'taxes');

        $this->assertSame('healthy', $taxCheck['status']);
        $this->assertSame('Current', $taxCheck['status_label']);
        $this->assertSame('Last tax record imported', $taxCheck['secondary_label']);
        $this->assertTrue($taxCheck['secondary_at']->equalTo(now()->subHours(4)));
    }

    public function test_latest_tax_failure_is_critical_even_when_an_older_poll_succeeded(): void
    {
        $this->cacheHealthyHeartbeat();
        TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 1234,
            'last_attempted_at' => now()->subMinutes(2),
            'last_succeeded_at' => now()->subHour(),
            'last_failed_at' => now()->subMinutes(2),
            'last_error' => 'Upstream query failed.',
        ]);

        $snapshot = $this->serviceForAlliances([777])->snapshot();
        $taxCheck = $this->check($snapshot, 'taxes');

        $this->assertSame('critical', $taxCheck['status']);
        $this->assertSame('Import failed', $taxCheck['status_label']);
        $this->assertSame('critical', $snapshot['status']);
    }

    public function test_pipeline_freshness_uses_the_configured_warning_window(): void
    {
        $this->cacheHealthyHeartbeat();
        TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 1234,
            'last_attempted_at' => now()->subMinutes(20),
            'last_succeeded_at' => now()->subMinutes(20),
        ]);
        $alliance = Alliance::factory()->create();
        $alliance->timestamps = false;
        $alliance->forceFill(['updated_at' => now()->subHours(14)])->saveQuietly();

        $allianceCheck = $this->check($this->serviceForAlliances([777])->snapshot(), 'alliances');

        $this->assertSame('warning', $allianceCheck['status']);
        $this->assertSame('Behind', $allianceCheck['status_label']);
    }

    public function test_critical_scheduler_contracts_surface_current_overdue_and_missing_states(): void
    {
        config()->set('scheduler_lifecycle.freshness_contracts', [
            'artisan:taxes:collect' => [
                'label' => 'Tax collection task',
                'maximum_age_minutes' => 90,
            ],
            'artisan:audits:run' => [
                'label' => 'Audit task',
                'maximum_age_minutes' => 120,
            ],
            'artisan:sync:wars' => [
                'label' => 'War synchronization task',
                'maximum_age_minutes' => 90,
            ],
        ]);
        ScheduledTaskRun::factory()->create([
            'task_identifier' => 'artisan:taxes:collect',
            'finished_at' => now()->subMinutes(20),
        ]);
        ScheduledTaskRun::factory()->create([
            'task_identifier' => 'artisan:audits:run',
            'finished_at' => now()->subMinutes(121),
        ]);

        $snapshot = $this->serviceForAlliances([])->snapshot();

        $this->assertSame(
            'healthy',
            $this->check($snapshot, 'scheduler-artisan-taxes-collect')['status'],
        );
        $this->assertSame(
            'critical',
            $this->check($snapshot, 'scheduler-artisan-audits-run')['status'],
        );
        $this->assertSame(
            'unknown',
            $this->check($snapshot, 'scheduler-artisan-sync-wars')['status'],
        );
        $this->assertSame('critical', $snapshot['status']);
    }

    private function cacheHealthyHeartbeat(): void
    {
        Cache::put(PWHealthService::CACHE_KEY_STATUS, true, 600);
        Cache::put(PWHealthService::CACHE_KEY_CHECKED_AT, now()->subMinute()->toIso8601String(), 600);
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    private function serviceForAlliances(array $allianceIds): SystemHealthService
    {
        $membershipService = $this->mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('getAllianceIds')
            ->once()
            ->andReturn(collect($allianceIds));

        return new SystemHealthService($membershipService, app(PWHealthService::class));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function check(array $snapshot, string $key): array
    {
        $check = collect($snapshot['checks'])->firstWhere('key', $key);

        $this->assertIsArray($check);

        return $check;
    }
}
