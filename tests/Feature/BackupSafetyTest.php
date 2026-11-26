<?php

namespace Tests\Feature;

use App\Console\NexusScheduleRegistrar;
use App\Models\Setting;
use App\Services\SettingService;
use App\Support\BackupSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BackupSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_does_not_enable_backups_implicitly(): void
    {
        $this->assertFalse(SettingService::isBackupsEnabled());
        $this->assertSame('0', (string) Setting::query()->where('key', 'backups_enabled')->value('value'));
    }

    public function test_backup_archive_verification_defaults_to_enabled(): void
    {
        $this->assertTrue((bool) config('backup.backup.verify_backup'));
    }

    public function test_mysql_backup_dump_does_not_lock_innodb_tables(): void
    {
        $this->assertTrue((bool) config('database.connections.mysql.dump.useSingleTransaction'));
        $this->assertTrue((bool) config('database.connections.mysql.dump.skipLockTables'));
    }

    public function test_backup_schedule_uses_configured_destinations_and_monitors_health(): void
    {
        $events = collect(app(Schedule::class)->events());
        $commands = $events->pluck('command')->filter()->values();

        $backupCommand = $commands->first(fn (string $command): bool => str_contains($command, 'backups:execute --trigger=scheduled'));
        $backupEvent = $events->first(fn (Event $event): bool => is_string($event->command)
            && str_contains($event->command, 'backups:execute --trigger=scheduled'));

        $this->assertIsString($backupCommand);
        $this->assertStringNotContainsString('--only-to-disk', $backupCommand);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'backups:execute --trigger=manual')));
        $this->assertNotNull($backupEvent);
        $this->assertSame('30 1,7,13,19 * * *', $backupEvent->expression);
        $this->assertSame('UTC', $backupEvent->timezone);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'backup:monitor')));
    }

    public function test_backup_schedule_defaults_to_every_six_hours_when_unset(): void
    {
        config(['backup.schedule.cron' => null]);

        $this->assertSame(BackupSchedule::DEFAULT_CRON, BackupSchedule::expression());
        $this->assertSame('30 1,7,13,19 * * *', $this->registeredBackupRunEvent()->expression);
    }

    public function test_backup_schedule_uses_the_configured_cron_expression(): void
    {
        config(['backup.schedule.cron' => ' 30 1 * * * ']);

        $event = $this->registeredBackupRunEvent();

        $this->assertSame('30 1 * * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
    }

    public function test_blank_backup_schedule_falls_back_to_the_default_without_warning(): void
    {
        Log::spy();
        config(['backup.schedule.cron' => '   ']);

        $this->assertSame(BackupSchedule::DEFAULT_CRON, $this->registeredBackupRunEvent()->expression);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_invalid_backup_schedule_falls_back_to_the_default_and_logs_a_warning(): void
    {
        Log::spy();
        config(['backup.schedule.cron' => 'every day at noon']);

        $event = $this->registeredBackupRunEvent();

        $this->assertSame(BackupSchedule::DEFAULT_CRON, $event->expression);
        $this->assertIsBool($event->isDue($this->app));
        Log::shouldHaveReceived('warning')
            ->with('Invalid backup schedule cron expression; using the default.', [
                'configured' => 'every day at noon',
                'default' => BackupSchedule::DEFAULT_CRON,
            ])
            ->once();
    }

    private function registeredBackupRunEvent(): Event
    {
        $schedule = new Schedule;
        $this->app->make(NexusScheduleRegistrar::class)->register($schedule);

        $event = collect($schedule->events())->first(fn (Event $event): bool => is_string($event->command)
            && str_contains($event->command, 'backups:execute --trigger=scheduled'));

        $this->assertNotNull($event);

        return $event;
    }
}
