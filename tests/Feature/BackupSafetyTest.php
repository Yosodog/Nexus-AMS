<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $backupCommand = $commands->first(fn (string $command): bool => str_contains($command, 'backup:run'));
        $backupEvent = $events->first(fn (Event $event): bool => is_string($event->command)
            && str_contains($event->command, 'backup:run'));

        $this->assertIsString($backupCommand);
        $this->assertStringNotContainsString('--only-to-disk', $backupCommand);
        $this->assertNotNull($backupEvent);
        $this->assertSame('30 1,7,13,19 * * *', $backupEvent->expression);
        $this->assertSame('UTC', $backupEvent->timezone);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'backup:monitor')));
    }
}
