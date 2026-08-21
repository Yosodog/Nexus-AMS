<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseBackupDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PDO;
use RuntimeException;
use Spatie\Backup\Config\Config;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;
use ZipArchive;

class DatabaseBackupDownloadTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_authorized_admin_can_generate_and_download_a_database_backup(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('test-database-backup.zip', 'backup-contents');
        $archivePath = Storage::disk('local')->path('test-database-backup.zip');
        $admin = $this->createAdmin(['view-diagnostic-info', 'download-database-backups']);

        $this->mock(DatabaseBackupDownloadService::class, function (MockInterface $mock) use ($archivePath): void {
            $mock->shouldReceive('create')->once()->andReturn($archivePath);
        });

        $response = $this->actingAs($admin)
            ->post(route('admin.settings.backups.download'));

        $response
            ->assertOk()
            ->assertDownload('test-database-backup.zip')
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'category' => 'settings',
            'action' => 'database_backup_generated_for_download',
            'outcome' => 'success',
        ]);
    }

    public function test_download_requires_both_diagnostic_and_database_backup_permissions(): void
    {
        $this->mock(DatabaseBackupDownloadService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('create');
        });

        foreach ([
            ['view-diagnostic-info'],
            ['download-database-backups'],
        ] as $permissions) {
            $this->actingAs($this->createAdmin($permissions))
                ->post(route('admin.settings.backups.download'))
                ->assertForbidden();
        }
    }

    public function test_download_control_is_visible_only_with_the_dedicated_permission(): void
    {
        $this->actingAs($this->createAdmin(['view-diagnostic-info']))
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertDontSee('Download database backup');

        $this->actingAs($this->createAdmin([
            'view-diagnostic-info',
            'download-database-backups',
        ]))
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertSee('Download database backup');
    }

    public function test_backup_failure_returns_a_safe_error_without_a_partial_download(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info', 'download-database-backups']);

        $this->mock(DatabaseBackupDownloadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new RuntimeException('database-password-should-not-be-shown'));
        });

        $response = $this->actingAs($admin)
            ->post(route('admin.settings.backups.download'))
            ->assertRedirect(route('admin.settings.security-retention'))
            ->assertSessionHas('alert-type', 'error');

        $this->assertStringNotContainsString(
            'database-password-should-not-be-shown',
            (string) $response->getSession()->get('alert-message'),
        );

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'database_backup_generated_for_download',
        ]);
    }

    public function test_service_creates_a_database_only_archive_on_the_private_local_disk(): void
    {
        Storage::fake('local');
        $databasePath = Storage::disk('local')->path('backup-source.sqlite');
        touch($databasePath);
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec('CREATE TABLE backup_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $database->exec("INSERT INTO backup_probe (value) VALUES ('included')");
        $database = null;

        config([
            'database.connections.backup_download_test' => [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'backup.backup.source.databases' => ['backup_download_test'],
            'backup.backup.password' => null,
        ]);
        $this->app->forgetInstance(Config::class);

        $archivePath = app(DatabaseBackupDownloadService::class)->create();

        $this->assertFileExists($archivePath);
        $this->assertStringContainsString('/on-demand-database-backups/', $archivePath);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($archivePath));
        $this->assertSame(1, $archive->numFiles);

        $entryName = $archive->getNameIndex(0);
        $this->assertIsString($entryName);
        $this->assertStringEndsWith('.sql.gz', $entryName);

        $compressedDump = $archive->getFromIndex(0);
        $this->assertIsString($compressedDump);
        $databaseDump = gzdecode($compressedDump);
        $this->assertIsString($databaseDump);
        $this->assertStringContainsString('backup_probe', $databaseDump);
        $archive->close();
    }

    public function test_permission_migration_grants_database_backup_downloads_to_default_admin(): void
    {
        $defaultAdminRole = Role::query()->firstOrCreate(
            ['name' => 'default admin'],
            ['protected' => true],
        );
        $defaultAdminRole->permissions()
            ->where('permission', 'download-database-backups')
            ->delete();

        $migration = require database_path('migrations/2026_08_21_152442_grant_database_backup_download_permission_to_default_admin.php');
        $migration->up();

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $defaultAdminRole->id,
            'permission' => 'download-database-backups',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}
