<?php

namespace App\Console\Commands;

use App\Enums\BackupRunTrigger;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupRunService;
use App\Services\Backups\BackupRuntimeConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExecuteBackup extends Command
{
    protected $signature = 'backups:execute
        {--trigger=scheduled : "scheduled" starts a backup; "manual" only runs a queued on-demand request}';

    protected $description = 'Run one backup and record it, holding the shared backup lock';

    public function handle(BackupRunService $runs, BackupRunner $runner, BackupRuntimeConfig $runtimeConfig): int
    {
        $trigger = BackupRunTrigger::tryFrom((string) $this->option('trigger'));

        if ($trigger === null) {
            $this->error('--trigger must be "scheduled" or "manual".');

            return self::INVALID;
        }

        $lock = $runs->lock();

        if (! $lock->get()) {
            $this->info('Another backup is already running.');

            return self::SUCCESS;
        }

        try {
            $run = $runs->start($trigger);

            if ($run === null) {
                $this->info('No backup to run.');

                return self::SUCCESS;
            }

            try {
                $runtimeConfig->apply();
                $result = $runner->run();
            } catch (Throwable $exception) {
                Log::error('Backup run threw an exception.', [
                    'backup_run_id' => $run->getKey(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $result = ['succeeded' => false, 'path' => null, 'size' => null, 'error' => Str::limit($exception->getMessage(), 2000)];
            }

            $runs->finish($run, $result);

            if (! $result['succeeded']) {
                Log::warning('Backup failed.', ['backup_run_id' => $run->getKey(), 'error' => $result['error']]);
                $this->error('Backup failed: '.$result['error']);

                return self::FAILURE;
            }

            $this->info('Backup completed: '.($result['path'] ?? 'path unknown'));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
