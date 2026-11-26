<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs spatie's backup:run in-process and reports what it produced.
 */
class BackupRunner
{
    /**
     * @return array{succeeded: bool, path: ?string, size: ?int, error: ?string}
     */
    public function run(): array
    {
        $successfulDisks = [];
        $failure = null;

        Event::listen(BackupWasSuccessful::class, function (BackupWasSuccessful $event) use (&$successfulDisks): void {
            $successfulDisks[] = [$event->diskName, $event->backupName];
        });
        Event::listen(BackupHasFailed::class, function (BackupHasFailed $event) use (&$failure): void {
            $failure = $event->exception->getMessage();
        });

        $output = new BufferedOutput;
        $exitCode = Artisan::call('backup:run', ['--config' => 'backup'], $output);

        if ($exitCode !== 0 || $failure !== null || $successfulDisks === []) {
            return [
                'succeeded' => false,
                'path' => null,
                'size' => null,
                'error' => Str::limit($failure ?? $this->lastErrorLine($output->fetch()) ?? 'backup:run exited with code '.$exitCode.'.', 2000),
            ];
        }

        [$diskName, $backupName] = $successfulDisks[0];
        $newest = $this->newestBackup($diskName, $backupName);

        return [
            'succeeded' => true,
            'path' => $newest['path'],
            'size' => $newest['size'],
            'error' => null,
        ];
    }

    /**
     * @return array{path: ?string, size: ?int}
     */
    private function newestBackup(string $diskName, string $backupName): array
    {
        try {
            $backup = BackupDestination::create($diskName, $backupName)->newestBackup();
        } catch (Throwable) {
            return ['path' => null, 'size' => null];
        }

        return [
            'path' => $backup?->path(),
            'size' => $backup?->sizeInBytes() !== null ? (int) $backup->sizeInBytes() : null,
        ];
    }

    private function lastErrorLine(string $output): ?string
    {
        $lines = collect(preg_split('/\R/', trim($output)) ?: [])->filter()->values();

        return $lines->last(fn (string $line): bool => Str::contains(Str::lower($line), ['fail', 'error', 'exception']))
            ?? $lines->last();
    }
}
