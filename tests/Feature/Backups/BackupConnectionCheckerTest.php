<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupDestination;
use App\Services\Backups\BackupConfiguration;
use App\Services\Backups\BackupConnectionChecker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupConnectionCheckerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/backup-checker-'.getmypid());
        File::ensureDirectoryExists($this->workspace.'/bin');
        File::put($this->workspace.'/bin/sqlite3', "#!/bin/sh\nexit 0\n");
        chmod($this->workspace.'/bin/sqlite3', 0755);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.dump.dump_binary_path' => $this->workspace.'/bin',
            'backup.backup.temporary_directory' => $this->workspace.'/temp',
            'filesystems.disks.local.root' => $this->workspace.'/local',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_local_destination_passes_when_directories_and_dump_tool_are_available(): void
    {
        $results = app(BackupConnectionChecker::class)->check($this->configuration(BackupDestination::Local));

        $this->assertSame(['Temporary directory', 'Database dump tool', 'Local storage'], array_column($results, 'check'));
        $this->assertSame([], BackupConnectionChecker::failures($results));
        $this->assertDirectoryExists($this->workspace.'/temp');
    }

    public function test_missing_dump_tool_is_reported(): void
    {
        config(['database.connections.sqlite.dump.dump_binary_path' => $this->workspace.'/missing']);

        $failures = BackupConnectionChecker::failures(
            app(BackupConnectionChecker::class)->check($this->configuration(BackupDestination::Local)),
        );

        $this->assertCount(1, $failures);
        $this->assertStringStartsWith('Database dump tool: sqlite3 was not found', $failures[0]);
    }

    public function test_unreachable_s3_endpoint_fails_the_bucket_check(): void
    {
        $results = app(BackupConnectionChecker::class)->check(new BackupConfiguration(
            destination: BackupDestination::S3,
            scheduleCron: '30 1 * * *',
            s3Bucket: 'nexus-test',
            s3Region: 'us-east-1',
            s3Endpoint: 'https://127.0.0.1:1',
            s3PathStyle: true,
            s3AccessKeyId: 'AKIATEST',
            s3SecretAccessKey: 'secret',
            archivePassword: 'correct horse battery staple',
        ));

        $s3 = collect($results)->firstWhere('check', 'S3 bucket');
        $this->assertIsArray($s3);
        $this->assertFalse($s3['passed']);
        $this->assertNotSame('', $s3['message']);
        $this->assertStringNotContainsString('secret', $s3['message']);
    }

    private function configuration(BackupDestination $destination): BackupConfiguration
    {
        return new BackupConfiguration(destination: $destination, scheduleCron: '30 1 * * *');
    }
}
