<?php

namespace App\Services\Backups;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Spatie\Backup\Config\Config as SpatieBackupConfig;

/**
 * Pushes admin-saved backup settings into the runtime config that spatie/laravel-backup
 * reads, so backup:run, backup:monitor, backup:clean and backup:list all use them.
 */
class BackupRuntimeConfig
{
    public function __construct(private readonly BackupSettings $settings) {}

    public function apply(): void
    {
        if (! $this->settings->hasStoredConfiguration()) {
            return;
        }

        $configuration = $this->settings->effective();
        $disks = $configuration->diskNames();

        config([
            'filesystems.disks.'.BackupConfiguration::S3_DISK => $configuration->s3DiskConfig(),
            'backup.backup.destination.disks' => $disks,
            'backup.monitor_backups.0.disks' => $disks,
            'backup.backup.password' => $configuration->hasArchivePassword() ? $configuration->archivePassword : null,
        ]);

        Storage::forgetDisk(BackupConfiguration::S3_DISK);
        app()->forgetInstance(SpatieBackupConfig::class);
    }

    /**
     * Apply during console boot, when the database may not exist yet (fresh install, migrate).
     */
    public function applyIfDatabaseReady(): void
    {
        try {
            $this->apply();
        } catch (QueryException|PDOException) {
            // No settings table yet: the .env config stays in effect.
        }
    }
}
