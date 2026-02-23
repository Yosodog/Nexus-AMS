<?php

use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

$defaultBackupDisks = env('AWS_BUCKET') ? ['s3'] : ['local'];
$configuredBackupDisks = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DESTINATION_DISKS', implode(',', $defaultBackupDisks))))));
$backupDisks = $configuredBackupDisks === [] ? $defaultBackupDisks : $configuredBackupDisks;

return [
    'backup' => [
        'name' => env('APP_NAME', 'laravel-backup'),
        'source' => [
            'files' => [
                'include' => [
                    base_path(),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],
        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => $backupDisks,
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
        'verify_backup' => false,
        'tries' => 1,
        'retry_delay' => 0,
    ],
    'notifications' => [
        'notifications' => [],
        'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,
    ],
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => $backupDisks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 102400,
            ],
        ],
    ],
    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 3,
            'keep_daily_backups_for_days' => 7,
            'keep_weekly_backups_for_weeks' => 4,
            'keep_monthly_backups_for_months' => 2,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 102400,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
];
