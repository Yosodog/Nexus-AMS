<?php

namespace App\Services\Backups;

use App\Enums\BackupRunStatus;
use App\Enums\BackupRunTrigger;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\SettingService;
use App\Support\BackupSchedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Tracks backup runs. Only one run may be queued or running at a time: the
 * friendly checks here are backed by the unique `pending_key` index, and the
 * cache lock below is what the backup process holds while it works.
 */
class BackupRunService
{
    public const LOCK_NAME = 'nexus:backups:execute';

    public const LOCK_SECONDS = 6 * 60 * 60;

    public const OVERDUE_GRACE_MINUTES = 90;

    public function lock(): Lock
    {
        return Cache::lock(self::LOCK_NAME, self::LOCK_SECONDS);
    }

    public function requestManual(User $user): BackupRun
    {
        $this->reapInterruptedRuns();

        if (BackupRun::query()->active()->exists()) {
            throw ValidationException::withMessages([
                'backup_run' => 'A backup is already queued or running.',
            ]);
        }

        try {
            return BackupRun::query()->create([
                'trigger' => BackupRunTrigger::Manual,
                'status' => BackupRunStatus::Queued,
                'pending_key' => 1,
                'requested_by_user_id' => $user->getKey(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'backup_run' => 'A backup is already queued or running.',
                ]);
            }

            throw $exception;
        }
    }

    public function hasQueuedRun(): bool
    {
        return BackupRun::query()->withStatus(BackupRunStatus::Queued)->exists();
    }

    /**
     * Claim the run to execute. Call only while holding lock(). A queued manual
     * request is always picked up first; a scheduled trigger creates a new run.
     */
    public function start(BackupRunTrigger $trigger): ?BackupRun
    {
        $this->failRunningRuns('The previous backup stopped before it finished.');

        $queued = BackupRun::query()->withStatus(BackupRunStatus::Queued)->oldest('id')->first();

        if ($queued !== null) {
            $queued->update(['status' => BackupRunStatus::Running, 'started_at' => now()]);

            return $queued;
        }

        if ($trigger === BackupRunTrigger::Manual) {
            return null;
        }

        try {
            return BackupRun::query()->create([
                'trigger' => BackupRunTrigger::Scheduled,
                'status' => BackupRunStatus::Running,
                'pending_key' => 1,
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param  array{succeeded: bool, path: ?string, size: ?int, error: ?string}  $result
     */
    public function finish(BackupRun $run, array $result): void
    {
        $run->update([
            'status' => $result['succeeded'] ? BackupRunStatus::Succeeded : BackupRunStatus::Failed,
            'pending_key' => null,
            'finished_at' => now(),
            'backup_path' => $result['path'],
            'backup_size_bytes' => $result['size'],
            'error_message' => $result['error'],
        ]);
    }

    /**
     * Fail runs left "running" by a process that died. Safe only when no backup holds the lock.
     */
    public function reapInterruptedRuns(): int
    {
        if (! BackupRun::query()->withStatus(BackupRunStatus::Running)->exists()) {
            return 0;
        }

        $lock = $this->lock();

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->failRunningRuns('The backup stopped before it finished.');
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{
     *     state: 'healthy'|'overdue'|'failing'|'never'|'disabled',
     *     enabled: bool,
     *     schedule: string,
     *     nextRunAt: ?Carbon,
     *     lastSuccess: ?BackupRun,
     *     lastFailure: ?BackupRun,
     *     active: ?BackupRun,
     *     recent: Collection<int, BackupRun>
     * }
     */
    public function health(): array
    {
        $this->reapInterruptedRuns();

        $enabled = SettingService::isBackupsEnabled();
        $schedule = BackupSchedule::expression();
        $lastSuccess = BackupRun::query()->withStatus(BackupRunStatus::Succeeded)->latest('finished_at')->latest('id')->first();
        $lastFailure = BackupRun::query()->withStatus(BackupRunStatus::Failed)->latest('finished_at')->latest('id')->first();
        $failingNow = $lastFailure !== null
            && ($lastSuccess === null || $lastFailure->finished_at?->greaterThan($lastSuccess->finished_at));

        $state = match (true) {
            $failingNow => 'failing',
            ! $enabled => 'disabled',
            $lastSuccess === null => 'never',
            $this->isOverdue($schedule, $lastSuccess) => 'overdue',
            default => 'healthy',
        };

        return [
            'state' => $state,
            'enabled' => $enabled,
            'schedule' => $schedule,
            'nextRunAt' => $enabled ? BackupSchedule::nextRunAt($schedule) : null,
            'lastSuccess' => $lastSuccess,
            'lastFailure' => $lastFailure,
            'active' => BackupRun::query()->active()->with('requestedBy')->latest('id')->first(),
            'recent' => BackupRun::query()->with('requestedBy')->latest('id')->limit(10)->get(),
        ];
    }

    private function isOverdue(string $schedule, BackupRun $lastSuccess): bool
    {
        $previousDue = BackupSchedule::previousRunAt($schedule);

        return $lastSuccess->finished_at?->lessThan($previousDue)
            && now()->greaterThan($previousDue->copy()->addMinutes(self::OVERDUE_GRACE_MINUTES));
    }

    private function failRunningRuns(string $message): int
    {
        return BackupRun::query()
            ->withStatus(BackupRunStatus::Running)
            ->update([
                'status' => BackupRunStatus::Failed->value,
                'pending_key' => null,
                'finished_at' => now(),
                'error_message' => $message,
                'updated_at' => now(),
            ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000';
    }
}
