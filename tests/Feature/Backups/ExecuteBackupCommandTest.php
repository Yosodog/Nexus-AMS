<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupRunStatus;
use App\Enums\BackupRunTrigger;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupRunService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExecuteBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_trigger_records_a_successful_backup(): void
    {
        $this->runnerReturns(['succeeded' => true, 'path' => 'Nexus/2026-11-26-01-30-00.zip', 'size' => 121_000_000, 'error' => null]);

        $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertSuccessful();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunTrigger::Scheduled, $run->trigger);
        $this->assertSame(BackupRunStatus::Succeeded, $run->status);
        $this->assertNull($run->pending_key);
        $this->assertSame('Nexus/2026-11-26-01-30-00.zip', $run->backup_path);
        $this->assertSame(121_000_000, $run->backup_size_bytes);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_manual_trigger_does_nothing_without_a_queued_request(): void
    {
        $this->mock(BackupRunner::class)->shouldNotReceive('run');

        $this->artisan('backups:execute', ['--trigger' => 'manual'])->assertSuccessful();

        $this->assertSame(0, BackupRun::query()->count());
    }

    public function test_manual_trigger_runs_the_queued_request(): void
    {
        $queued = BackupRun::factory()->queued()->create();
        $this->runnerReturns(['succeeded' => true, 'path' => 'Nexus/manual.zip', 'size' => 10, 'error' => null]);

        $this->artisan('backups:execute', ['--trigger' => 'manual'])->assertSuccessful();

        $queued->refresh();
        $this->assertSame(BackupRunTrigger::Manual, $queued->trigger);
        $this->assertSame(BackupRunStatus::Succeeded, $queued->status);
        $this->assertNull($queued->pending_key);
    }

    public function test_scheduled_run_picks_up_a_waiting_manual_request_instead_of_starting_another(): void
    {
        $queued = BackupRun::factory()->queued()->create();
        $this->runnerReturns(['succeeded' => true, 'path' => 'Nexus/x.zip', 'size' => 10, 'error' => null]);

        $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertSuccessful();

        $this->assertSame(1, BackupRun::query()->count());
        $this->assertSame(BackupRunStatus::Succeeded, $queued->refresh()->status);
    }

    public function test_failed_backup_is_recorded_with_its_error(): void
    {
        $this->runnerReturns(['succeeded' => false, 'path' => null, 'size' => null, 'error' => 'mysqldump: Got error: 1045 Access denied']);

        $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertFailed();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunStatus::Failed, $run->status);
        $this->assertNull($run->pending_key);
        $this->assertSame('mysqldump: Got error: 1045 Access denied', $run->error_message);
    }

    public function test_an_exception_from_the_runner_is_recorded_as_a_failure(): void
    {
        $this->mock(BackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('Disk full'));
        });

        $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertFailed();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunStatus::Failed, $run->status);
        $this->assertSame('Disk full', $run->error_message);
        $this->assertNull($run->pending_key);
    }

    public function test_nothing_runs_while_another_backup_holds_the_lock(): void
    {
        $this->mock(BackupRunner::class)->shouldNotReceive('run');
        $lock = app(BackupRunService::class)->lock();
        $this->assertTrue($lock->get());

        try {
            $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, BackupRun::query()->count());
    }

    public function test_a_run_left_running_by_a_dead_process_is_failed_before_the_next_one(): void
    {
        $orphan = BackupRun::factory()->running()->create();
        $this->runnerReturns(['succeeded' => true, 'path' => 'Nexus/x.zip', 'size' => 10, 'error' => null]);

        $this->artisan('backups:execute', ['--trigger' => 'scheduled'])->assertSuccessful();

        $orphan->refresh();
        $this->assertSame(BackupRunStatus::Failed, $orphan->status);
        $this->assertNull($orphan->pending_key);
        $this->assertSame(2, BackupRun::query()->count());
    }

    public function test_invalid_trigger_is_rejected(): void
    {
        $this->artisan('backups:execute', ['--trigger' => 'hourly'])->assertExitCode(2);
    }

    public function test_requesting_a_run_clears_an_interrupted_run_when_no_backup_holds_the_lock(): void
    {
        $orphan = BackupRun::factory()->running()->create();

        $run = app(BackupRunService::class)->requestManual(User::factory()->create());

        $this->assertSame(BackupRunStatus::Failed, $orphan->refresh()->status);
        $this->assertSame(BackupRunStatus::Queued, $run->status);
    }

    public function test_health_reports_never_healthy_failing_and_overdue(): void
    {
        SettingService::setBackupsEnabled(true);
        config(['backup.schedule.cron' => '30 1 * * *']);
        $runs = app(BackupRunService::class);

        $this->travelTo(now('UTC')->setTime(12, 0));
        $this->assertSame('never', $runs->health()['state']);

        $success = BackupRun::factory()->create(['finished_at' => now()->setTime(1, 33)]);
        $this->assertSame('healthy', $runs->health()['state']);

        $this->travelTo(now()->addDay());
        $this->assertSame('overdue', $runs->health()['state']);

        BackupRun::factory()->failed('S3 access denied')->create(['finished_at' => now()->setTime(1, 31)]);
        $health = $runs->health();
        $this->assertSame('failing', $health['state']);
        $this->assertSame('S3 access denied', $health['lastFailure']->error_message);
        $this->assertTrue($health['lastSuccess']->is($success));

        SettingService::setBackupsEnabled(false);
        BackupRun::factory()->create(['finished_at' => now()]);
        $this->assertSame('disabled', $runs->health()['state']);
        $this->assertNull($runs->health()['nextRunAt']);
    }

    /**
     * @param  array{succeeded: bool, path: ?string, size: ?int, error: ?string}  $result
     */
    private function runnerReturns(array $result): void
    {
        $this->mock(BackupRunner::class, function (MockInterface $mock) use ($result): void {
            $mock->shouldReceive('run')->once()->andReturn($result);
        });
    }
}
