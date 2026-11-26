<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupRunStatus;
use App\Enums\BackupRunTrigger;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backups\BackupConfiguration;
use App\Services\Backups\BackupConnectionChecker;
use App\Services\Backups\BackupSettings;
use App\Support\BackupSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery\MockInterface;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class BackupConfigurationSettingsTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.key' => null,
            'filesystems.disks.s3.secret' => null,
            'filesystems.disks.s3.bucket' => null,
            'backup.backup.password' => null,
            'backup.schedule.cron' => null,
        ]);
    }

    public function test_diagnostic_admin_sees_backup_health_and_configuration(): void
    {
        BackupRun::factory()->create(['backup_path' => 'Nexus/2026-11-26-01-30-00.zip']);

        $this->actingAs($this->diagnosticAdmin())
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertSee('Backup health')
            ->assertSee('Run backup now')
            ->assertSee('Save and check connection')
            ->assertSee('Nexus/2026-11-26-01-30-00.zip')
            ->assertSee(BackupSchedule::DEFAULT_CRON);
    }

    public function test_connection_check_results_are_shown_after_saving(): void
    {
        $this->actingAs($this->diagnosticAdmin())
            ->withSession(['backup-check-results' => [
                ['check' => 'S3 bucket', 'passed' => true, 'message' => 'Wrote, read, listed and deleted a test file in yoso-bknet/BK Net.'],
                ['check' => 'Database dump tool', 'passed' => false, 'message' => 'mysqldump was not found.'],
            ]])
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertSee('Wrote, read, listed and deleted a test file in yoso-bknet/BK Net.')
            ->assertSee('mysqldump was not found.');
    }

    public function test_saving_an_s3_configuration_checks_it_then_stores_secrets_encrypted(): void
    {
        $this->expectPassingCheck();
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.backups.configuration'), $this->s3Payload())
            ->assertRedirect(route('admin.settings.security-retention').'#backup-settings')
            ->assertSessionHasNoErrors();

        $storedSecret = (string) Setting::query()->where('key', BackupSettings::S3_SECRET_ACCESS_KEY)->value('value');
        $this->assertNotSame(self::SECRET, $storedSecret);
        $this->assertSame(self::SECRET, Crypt::decryptString($storedSecret));

        $effective = app(BackupSettings::class)->effective();
        $this->assertSame('yoso-backups', $effective->s3Bucket);
        $this->assertSame('nexus', $effective->s3Folder);
        $this->assertSame('30 1 * * *', BackupSchedule::expression());
        $this->assertSame([BackupConfiguration::S3_DISK], config('backup.backup.destination.disks'));
        $this->assertSame('yoso-backups', config('filesystems.disks.'.BackupConfiguration::S3_DISK.'.bucket'));
        $this->assertSame('nexus', config('filesystems.disks.'.BackupConfiguration::S3_DISK.'.root'));
        $this->assertSame('correct horse battery staple', config('backup.backup.password'));

        $audit = AuditLog::query()->where('action', 'backup_configuration_updated')->sole();
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit->toArray()));
        $this->assertStringNotContainsString('correct horse battery staple', json_encode($audit->toArray()));
    }

    public function test_a_failed_connection_check_saves_nothing_and_reports_why(): void
    {
        $this->mock(BackupConnectionChecker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('check')->once()->andReturn([
                ['check' => 'S3 bucket', 'passed' => false, 'message' => 'Access denied.'],
            ]);
        });

        $this->actingAs($this->diagnosticAdmin())
            ->post(route('admin.settings.backups.configuration'), $this->s3Payload())
            ->assertSessionHasErrors(['backup_check' => 'S3 bucket: Access denied.']);

        $this->assertFalse(app(BackupSettings::class)->hasStoredConfiguration());
        $this->assertNull(Setting::query()->where('key', BackupSettings::S3_SECRET_ACCESS_KEY)->value('value'));
    }

    public function test_secrets_are_not_flashed_back_into_the_session(): void
    {
        $this->actingAs($this->diagnosticAdmin())
            ->post(route('admin.settings.backups.configuration'), [...$this->s3Payload(), 's3_bucket' => 'yoso-bknet/bknet_backup_new'])
            ->assertSessionHasErrors('s3_bucket');

        $oldInput = (array) session('_old_input');
        $this->assertSame('yoso-bknet/bknet_backup_new', $oldInput['s3_bucket'] ?? null);
        $this->assertArrayNotHasKey('s3_secret_access_key', $oldInput);
        $this->assertArrayNotHasKey('archive_password', $oldInput);
    }

    public function test_s3_requires_keys_and_an_archive_password_before_any_check_runs(): void
    {
        $this->mock(BackupConnectionChecker::class)->shouldNotReceive('check');

        $this->actingAs($this->diagnosticAdmin())
            ->post(route('admin.settings.backups.configuration'), [
                ...$this->s3Payload(),
                's3_access_key_id' => '',
                's3_secret_access_key' => '',
                'archive_password' => '',
                'archive_password_confirmation' => '',
            ])
            ->assertSessionHasErrors(['s3_access_key_id', 's3_secret_access_key', 'archive_password']);
    }

    public function test_blank_secret_fields_keep_the_saved_secrets(): void
    {
        $this->expectPassingCheck(times: 2);
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->post(route('admin.settings.backups.configuration'), $this->s3Payload());
        $this->actingAs($admin)
            ->post(route('admin.settings.backups.configuration'), [
                ...$this->s3Payload(),
                's3_region' => 'eu-west-2',
                's3_access_key_id' => '',
                's3_secret_access_key' => '',
                'archive_password' => '',
                'archive_password_confirmation' => '',
            ])
            ->assertSessionHasNoErrors();

        $effective = app(BackupSettings::class)->effective();
        $this->assertSame('eu-west-2', $effective->s3Region);
        $this->assertSame(self::SECRET, $effective->s3SecretAccessKey);
        $this->assertSame('correct horse battery staple', $effective->archivePassword);
    }

    public function test_env_secrets_are_used_when_nothing_is_saved_in_admin(): void
    {
        config([
            'filesystems.disks.s3.key' => 'AKIAENVKEY',
            'filesystems.disks.s3.secret' => 'env-secret',
            'filesystems.disks.s3.bucket' => 'env-bucket',
            'backup.backup.password' => 'env archive password',
            'backup.backup.destination.disks' => ['s3'],
        ]);

        $settings = app(BackupSettings::class);
        $effective = $settings->effective();

        $this->assertFalse($settings->hasStoredConfiguration());
        $this->assertSame('env-bucket', $effective->s3Bucket);
        $this->assertSame('AKIAENVKEY', $effective->s3AccessKeyId);
        $this->assertTrue($effective->destination->usesS3());
        $this->assertSame('env', $settings->secretSources()[BackupSettings::S3_ACCESS_KEY_ID]);
        $this->assertSame('env', $settings->secretSources()[BackupSettings::ARCHIVE_PASSWORD]);
    }

    public function test_custom_schedule_must_be_a_valid_cron_expression(): void
    {
        $this->mock(BackupConnectionChecker::class)->shouldNotReceive('check');

        $this->actingAs($this->diagnosticAdmin())
            ->post(route('admin.settings.backups.configuration'), [
                ...$this->localPayload(),
                'schedule_preset' => 'custom',
                'schedule_cron' => 'every day at noon',
            ])
            ->assertSessionHasErrors('schedule_cron');
    }

    public function test_local_only_configuration_saves_a_custom_schedule(): void
    {
        $this->expectPassingCheck();

        $this->actingAs($this->diagnosticAdmin())
            ->post(route('admin.settings.backups.configuration'), [
                ...$this->localPayload(),
                'schedule_preset' => 'custom',
                'schedule_cron' => '15 3 * * 1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('15 3 * * 1', BackupSchedule::expression());
        $this->assertSame(['local'], config('backup.backup.destination.disks'));
    }

    public function test_run_now_queues_one_backup_and_refuses_a_second(): void
    {
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.backups.run'))
            ->assertRedirect(route('admin.settings.security-retention').'#backup-settings')
            ->assertSessionHasNoErrors();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunStatus::Queued, $run->status);
        $this->assertSame(BackupRunTrigger::Manual, $run->trigger);
        $this->assertSame(1, $run->pending_key);
        $this->assertTrue($run->requestedBy->is($admin));

        $this->actingAs($admin)
            ->post(route('admin.settings.backups.run'))
            ->assertSessionHasErrors('backup_run');

        $this->assertSame(1, BackupRun::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'backup_run_requested')->exists());
    }

    public function test_database_blocks_a_second_active_backup_run(): void
    {
        BackupRun::factory()->queued()->create();

        $this->expectException(QueryException::class);
        BackupRun::factory()->running()->create();
    }

    public function test_admins_without_diagnostic_permission_cannot_change_or_run_backups(): void
    {
        $userManager = $this->createVerifiedAdmin(['nation_id' => 971002]);
        $this->attachDiscordAccount($userManager, ['discord_id' => '1971002']);
        $this->grantPermissions($userManager, ['edit-users']);

        $this->actingAs($userManager)
            ->post(route('admin.settings.backups.configuration'), $this->localPayload())
            ->assertForbidden();
        $this->actingAs($userManager)
            ->post(route('admin.settings.backups.run'))
            ->assertForbidden();

        $this->assertSame(0, BackupRun::query()->count());
    }

    private function expectPassingCheck(int $times = 1): void
    {
        $this->mock(BackupConnectionChecker::class, function (MockInterface $mock) use ($times): void {
            $mock->shouldReceive('check')->times($times)->andReturn([
                ['check' => 'Temporary directory', 'passed' => true, 'message' => 'Writable.'],
                ['check' => 'S3 bucket', 'passed' => true, 'message' => 'Wrote, read, listed and deleted a test file.'],
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function s3Payload(): array
    {
        return [
            'schedule_preset' => 'daily',
            'schedule_cron' => '',
            'destination' => 's3',
            's3_bucket' => 'yoso-backups',
            's3_region' => 'us-east-1',
            's3_endpoint' => '',
            's3_path_style' => '0',
            's3_folder' => '/nexus/',
            's3_access_key_id' => 'AKIAEXAMPLE',
            's3_secret_access_key' => self::SECRET,
            'archive_password' => 'correct horse battery staple',
            'archive_password_confirmation' => 'correct horse battery staple',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localPayload(): array
    {
        return [
            'schedule_preset' => 'every_6_hours',
            'destination' => 'local',
            's3_bucket' => '',
            's3_region' => '',
        ];
    }

    private function diagnosticAdmin(): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => 971001]);
        $this->attachDiscordAccount($admin, ['discord_id' => '1971001']);

        return $this->grantPermissions($admin, ['view-diagnostic-info']);
    }
}
