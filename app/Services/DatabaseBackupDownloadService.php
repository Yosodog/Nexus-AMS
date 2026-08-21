<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;
use Throwable;

class DatabaseBackupDownloadService
{
    private const DIRECTORY = 'on-demand-database-backups';

    private const DISK = 'local';

    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function create(): string
    {
        $filename = $this->filename();
        $relativePath = self::DIRECTORY.'/'.$filename;
        $disk = $this->filesystems->disk(self::DISK);

        try {
            $backupJob = BackupJobFactory::createFromConfig($this->downloadConfiguration())
                ->dontBackupFilesystem()
                ->disableSignals()
                ->setFilename($filename);

            $backupJob->run();
        } catch (Throwable $exception) {
            $disk->delete($relativePath);

            throw $exception;
        }

        if (! $disk->exists($relativePath)) {
            throw new RuntimeException('The database backup archive was not created.');
        }

        $absolutePath = $disk->path($relativePath);

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            $disk->delete($relativePath);

            throw new RuntimeException('The database backup archive is not readable.');
        }

        return $absolutePath;
    }

    private function downloadConfiguration(): Config
    {
        $configuration = config('backup');

        if (! is_array($configuration)) {
            throw new RuntimeException('Database backups are not configured.');
        }

        $configuration['backup']['name'] = self::DIRECTORY;
        $configuration['backup']['destination']['disks'] = [self::DISK];
        $configuration['backup']['destination']['continue_on_failure'] = false;

        return Config::fromArray($configuration);
    }

    private function filename(): string
    {
        $application = Str::slug((string) config('app.name')) ?: 'nexus-ams';

        return sprintf(
            '%s-database-%s-%s.zip',
            $application,
            now('UTC')->format('Y-m-d-His'),
            Str::lower((string) Str::ulid()),
        );
    }
}
