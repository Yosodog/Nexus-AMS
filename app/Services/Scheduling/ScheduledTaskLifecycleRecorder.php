<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScheduledTaskLifecycleRecorder
{
    /** @var array<int, int> */
    private array $activeRuns = [];

    private ?string $hostname = null;

    public function __construct(
        private readonly ScheduledTaskIdentifier $identifier,
    ) {}

    public function recordStarting(ScheduledTaskStarting $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->safely($event->task, 'starting', function () use ($event): void {
            $observedAt = now();
            $run = ScheduledTaskRun::query()->create([
                'task_identifier' => $this->identifier->for($event->task),
                'task_mutex_hash' => $this->identifier->mutexHash($event->task),
                'status' => ScheduledTaskRunStatus::Running,
                'scheduled_at' => $this->scheduledAt($event->task, $observedAt),
                'started_at' => $observedAt,
                'hostname' => $this->hostname(),
                'correlation_id' => (string) Str::uuid(),
            ]);

            $this->activeRuns[$this->objectKey($event->task)] = $run->getKey();
        });
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($event->task->skippedBecauseOverlapping === true) {
            $this->finalize(
                task: $event->task,
                status: ScheduledTaskRunStatus::Overlap,
                durationMs: 0,
                clearStartedAt: true,
                clearExitCode: true,
                phase: 'overlap',
            );

            return;
        }

        if ($event->task->runInBackground) {
            unset($this->activeRuns[$this->objectKey($event->task)]);

            return;
        }

        if (($event->task->exitCode ?? 0) !== 0) {
            return;
        }

        $this->finalize(
            task: $event->task,
            status: ScheduledTaskRunStatus::Success,
            durationMs: $this->runtimeToMilliseconds($event->runtime),
            phase: 'finished',
        );
    }

    public function recordFailed(ScheduledTaskFailed $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->finalize(
            task: $event->task,
            status: ScheduledTaskRunStatus::Failure,
            exceptionClass: get_class($event->exception),
            phase: 'failed',
        );
    }

    public function recordSkipped(ScheduledTaskSkipped $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        unset($this->activeRuns[$this->objectKey($event->task)]);

        $this->safely($event->task, 'skipped', function () use ($event): void {
            $observedAt = now();

            ScheduledTaskRun::query()->create([
                'task_identifier' => $this->identifier->for($event->task),
                'task_mutex_hash' => $this->identifier->mutexHash($event->task),
                'status' => ScheduledTaskRunStatus::Skipped,
                'scheduled_at' => $this->scheduledAt($event->task, $observedAt),
                'started_at' => null,
                'finished_at' => $observedAt,
                'duration_ms' => null,
                'exit_code' => null,
                'hostname' => $this->hostname(),
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }

    public function recordBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $exitCode = (int) ($event->task->exitCode ?? 1);

        $this->finalize(
            task: $event->task,
            status: $exitCode === 0
                ? ScheduledTaskRunStatus::Success
                : ScheduledTaskRunStatus::Failure,
            phase: 'background-finished',
        );
    }

    private function finalize(
        ScheduledEvent $task,
        ScheduledTaskRunStatus $status,
        ?int $durationMs = null,
        ?string $exceptionClass = null,
        bool $clearStartedAt = false,
        bool $clearExitCode = false,
        string $phase = 'finished',
    ): void {
        $runId = $this->activeRuns[$this->objectKey($task)] ?? null;
        unset($this->activeRuns[$this->objectKey($task)]);

        $this->safely($task, $phase, function () use (
            $task,
            $status,
            $durationMs,
            $exceptionClass,
            $clearStartedAt,
            $clearExitCode,
            $runId,
        ): void {
            $finishedAt = now();
            $hostname = $this->hostname();
            $mutexHash = $this->identifier->mutexHash($task);
            $connection = (new ScheduledTaskRun)->getConnection();

            $connection->transaction(function () use (
                $task,
                $status,
                $durationMs,
                $exceptionClass,
                $clearStartedAt,
                $clearExitCode,
                $runId,
                $finishedAt,
                $hostname,
                $mutexHash,
            ): void {
                $exitCode = $clearExitCode ? null : $task->exitCode;
                $run = $runId === null
                    ? null
                    : ScheduledTaskRun::query()
                        ->whereKey($runId)
                        ->where('status', ScheduledTaskRunStatus::Running->value)
                        ->lockForUpdate()
                        ->first();

                $run ??= ScheduledTaskRun::query()
                    ->where('task_mutex_hash', $mutexHash)
                    ->where('hostname', $hostname)
                    ->where('status', ScheduledTaskRunStatus::Running->value)
                    ->oldest('started_at')
                    ->lockForUpdate()
                    ->first();

                if ($run === null) {
                    ScheduledTaskRun::query()->create([
                        'task_identifier' => $this->identifier->for($task),
                        'task_mutex_hash' => $mutexHash,
                        'status' => $status,
                        'scheduled_at' => $finishedAt,
                        'started_at' => null,
                        'finished_at' => $finishedAt,
                        'duration_ms' => $durationMs,
                        'exit_code' => $exitCode,
                        'hostname' => $hostname,
                        'correlation_id' => (string) Str::uuid(),
                        'exception_class' => $this->limitExceptionClass($exceptionClass),
                    ]);

                    return;
                }

                $startedAt = $clearStartedAt ? null : $run->started_at;
                $measuredDuration = $durationMs;

                if ($measuredDuration === null && $startedAt !== null) {
                    $measuredDuration = max(
                        0,
                        (int) round($startedAt->diffInMilliseconds($finishedAt)),
                    );
                }

                $run->forceFill([
                    'status' => $status,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $measuredDuration,
                    'exit_code' => $exitCode,
                    'exception_class' => $this->limitExceptionClass($exceptionClass),
                ])->save();
            });
        });
    }

    private function runtimeToMilliseconds(float $runtime): ?int
    {
        if (! is_finite($runtime) || $runtime < 0) {
            return null;
        }

        return max(0, (int) round($runtime * 1000));
    }

    private function scheduledAt(
        ScheduledEvent $task,
        CarbonInterface $observedAt,
    ): CarbonImmutable {
        $observedAt = CarbonImmutable::instance($observedAt);

        if ($task->isRepeatable()) {
            return $observedAt;
        }

        try {
            $timezone = $task->timezone instanceof DateTimeZone
                ? $task->timezone->getName()
                : $task->timezone;
            $scheduledAt = (new CronExpression($task->expression))
                ->getPreviousRunDate($observedAt, 0, true, $timezone);

            return CarbonImmutable::instance($scheduledAt)
                ->setTimezone($observedAt->getTimezone());
        } catch (Throwable) {
            return $observedAt;
        }
    }

    private function limitExceptionClass(?string $exceptionClass): ?string
    {
        if ($exceptionClass === null || $exceptionClass === '') {
            return null;
        }

        return Str::limit($exceptionClass, 255, '');
    }

    private function hostname(): string
    {
        if ($this->hostname !== null) {
            return $this->hostname;
        }

        $hostname = gethostname();
        $hostname = is_string($hostname) ? Str::squish($hostname) : '';

        return $this->hostname = Str::limit($hostname !== '' ? $hostname : 'unknown', 191, '');
    }

    private function objectKey(ScheduledEvent $task): int
    {
        return spl_object_id($task);
    }

    private function isEnabled(): bool
    {
        return (bool) config('scheduler_lifecycle.enabled', true);
    }

    private function safely(ScheduledEvent $task, string $phase, Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('Unable to record scheduled task lifecycle.', [
                'phase' => $phase,
                'task_identifier' => $this->safeIdentifier($task),
                'exception_class' => get_class($exception),
            ]);
        }
    }

    private function safeIdentifier(ScheduledEvent $task): string
    {
        try {
            return $this->identifier->for($task);
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
