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
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScheduledTaskLifecycleRecorder
{
    /**
     * Hidden context key that carries a background run's identity from the
     * scheduler process to the separate `schedule:finish` process.
     */
    public const CONTEXT_KEY = 'scheduler_lifecycle.background_run';

    /** @var array<int, int> */
    private array $activeRuns = [];

    /**
     * The background run whose process is being launched right now, if any.
     *
     * @var array{task_mutex_hash: string, correlation_id: string}|null
     */
    private ?array $launchingBackgroundRun = null;

    private ?string $hostname = null;

    public function __construct(
        private readonly ScheduledTaskIdentifier $identifier,
    ) {}

    public function recordStarting(ScheduledTaskStarting $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->launchingBackgroundRun = null;

        $this->safely($event->task, 'starting', function () use ($event): void {
            $observedAt = now();
            $mutexHash = $this->identifier->mutexHash($event->task);
            $correlationId = (string) Str::uuid();
            $run = ScheduledTaskRun::query()->create([
                'task_identifier' => $this->identifier->for($event->task),
                'task_mutex_hash' => $mutexHash,
                'status' => ScheduledTaskRunStatus::Running,
                'scheduled_at' => $this->scheduledAt($event->task, $observedAt),
                'started_at' => $observedAt,
                'hostname' => $this->hostname(),
                'correlation_id' => $correlationId,
            ]);

            $this->activeRuns[$this->objectKey($event->task)] = $run->getKey();

            if ($event->task->runInBackground) {
                $this->launchingBackgroundRun = [
                    'task_mutex_hash' => $mutexHash,
                    'correlation_id' => $correlationId,
                ];
            }
        });
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        $this->launchingBackgroundRun = null;

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
        $this->launchingBackgroundRun = null;

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
            correlationId: $this->backgroundCorrelationId($event->task),
        );
    }

    /**
     * Shape the context that is about to be serialized for a child process
     * or a queued job.
     *
     * The scheduler spawns a background task with the dehydrated context in
     * `__LARAVEL_CONTEXT`, and the `schedule:finish` process that follows the
     * task inherits it. Only that launch carries the run identity; every other
     * dehydration (queued jobs, nested processes) has it removed so it does
     * not leak into queue payloads.
     */
    public function prepareContextForDehydration(ContextRepository $context): void
    {
        $context->forgetHidden(self::CONTEXT_KEY);

        if ($this->launchingBackgroundRun !== null) {
            $context->addHidden(self::CONTEXT_KEY, $this->launchingBackgroundRun);
        }
    }

    private function backgroundCorrelationId(ScheduledEvent $task): ?string
    {
        try {
            $carried = Context::getHidden(self::CONTEXT_KEY);
            $correlationId = is_array($carried) ? ($carried['correlation_id'] ?? null) : null;

            if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
                return null;
            }

            return ($carried['task_mutex_hash'] ?? null) === $this->identifier->mutexHash($task)
                ? $correlationId
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function finalize(
        ScheduledEvent $task,
        ScheduledTaskRunStatus $status,
        ?int $durationMs = null,
        ?string $exceptionClass = null,
        bool $clearStartedAt = false,
        bool $clearExitCode = false,
        string $phase = 'finished',
        ?string $correlationId = null,
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
            $correlationId,
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
                $correlationId,
                $finishedAt,
                $hostname,
                $mutexHash,
            ): void {
                $exitCode = $clearExitCode ? null : $task->exitCode;
                $run = $this->findRunToClose($runId, $correlationId, $mutexHash, $hostname);

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

                if ($run->status === ScheduledTaskRunStatus::Abandoned) {
                    $measuredDuration = null;
                } elseif ($measuredDuration === null && $startedAt !== null) {
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

    /**
     * Find the row this finish belongs to.
     *
     * A run identified by this process (foreground) or by the carried
     * correlation id (background) closes only its own row, including one the
     * reaper already marked abandoned. Without either, the newest open row on
     * this host is the closest match: a lost finish can then never cascade
     * onto older rows, and the computed duration can never exceed the time
     * since that row started.
     */
    private function findRunToClose(
        ?int $runId,
        ?string $correlationId,
        string $mutexHash,
        string $hostname,
    ): ?ScheduledTaskRun {
        $closable = [
            ScheduledTaskRunStatus::Running->value,
            ScheduledTaskRunStatus::Abandoned->value,
        ];

        if ($runId !== null) {
            return ScheduledTaskRun::query()
                ->whereKey($runId)
                ->whereIn('status', $closable)
                ->lockForUpdate()
                ->first();
        }

        if ($correlationId !== null) {
            return ScheduledTaskRun::query()
                ->where('correlation_id', $correlationId)
                ->where('task_mutex_hash', $mutexHash)
                ->whereIn('status', $closable)
                ->lockForUpdate()
                ->first();
        }

        return ScheduledTaskRun::query()
            ->where('task_mutex_hash', $mutexHash)
            ->where('hostname', $hostname)
            ->where('status', ScheduledTaskRunStatus::Running->value)
            ->latest('started_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();
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
