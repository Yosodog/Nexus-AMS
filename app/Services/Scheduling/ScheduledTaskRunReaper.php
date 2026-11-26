<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Closes lifecycle rows whose finish was never recorded and clears durations
 * that no run of the task could plausibly have taken.
 *
 * Each task gets a bound of `interval_multiple` times its schedule interval,
 * clamped to `minimum_minutes`..`maximum_minutes`; tasks that are no longer
 * scheduled use `maximum_minutes`. A row still `running` past that bound
 * becomes `abandoned` with no finish time or duration. A success or failure
 * whose duration exceeds the bound keeps its status and finish time (the
 * finish really happened) but its duration becomes unknown: such durations
 * were produced by finishes that closed an older run's row. Both passes are
 * idempotent and chunked, and touch at most `max_rows_per_pass` rows each.
 */
class ScheduledTaskRunReaper
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduledTaskIdentifier $identifier,
    ) {}

    /**
     * @return array{abandoned: int, repaired: int}
     */
    public function reap(bool $pretend = false, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $boundsByMutexHash = $this->boundsByMutexHash($now);

        return [
            'abandoned' => $this->abandonStuckRuns($boundsByMutexHash, $now, $pretend),
            'repaired' => $this->clearImplausibleDurations($boundsByMutexHash, $pretend),
        ];
    }

    /**
     * @param  array<string, int>  $boundsByMutexHash
     */
    private function abandonStuckRuns(
        array $boundsByMutexHash,
        CarbonImmutable $now,
        bool $pretend,
    ): int {
        $candidateHashes = $this->runningQuery($now->subMinutes($this->minimumMinutes()))
            ->distinct()
            ->pluck('task_mutex_hash');

        $remaining = $this->maxRowsPerPass();
        $touched = 0;

        foreach ($candidateHashes as $mutexHash) {
            if ($remaining <= 0) {
                break;
            }

            $cutoff = $now->subMinutes($this->boundFor($mutexHash, $boundsByMutexHash));
            $query = $this->runningQuery($cutoff)->where('task_mutex_hash', $mutexHash);

            $count = $this->applyInChunks(
                $query,
                $remaining,
                $pretend,
                fn (array $ids): int => ScheduledTaskRun::query()
                    ->whereKey($ids)
                    ->where('status', ScheduledTaskRunStatus::Running->value)
                    ->update([
                        'status' => ScheduledTaskRunStatus::Abandoned->value,
                        'finished_at' => null,
                        'duration_ms' => null,
                        'updated_at' => $now,
                    ]),
            );

            $touched += $count;
            $remaining -= $count;
        }

        return $touched;
    }

    /**
     * @param  array<string, int>  $boundsByMutexHash
     */
    private function clearImplausibleDurations(array $boundsByMutexHash, bool $pretend): int
    {
        $candidateHashes = $this->completedQuery($this->minimumMinutes())
            ->distinct()
            ->pluck('task_mutex_hash');

        $remaining = $this->maxRowsPerPass();
        $touched = 0;

        foreach ($candidateHashes as $mutexHash) {
            if ($remaining <= 0) {
                break;
            }

            $boundMinutes = $this->boundFor($mutexHash, $boundsByMutexHash);
            $query = $this->completedQuery($boundMinutes)->where('task_mutex_hash', $mutexHash);

            $count = $this->applyInChunks(
                $query,
                $remaining,
                $pretend,
                fn (array $ids): int => ScheduledTaskRun::query()
                    ->whereKey($ids)
                    ->where('duration_ms', '>', $boundMinutes * 60_000)
                    ->update(['duration_ms' => null]),
            );

            $touched += $count;
            $remaining -= $count;
        }

        return $touched;
    }

    /**
     * @return Builder<ScheduledTaskRun>
     */
    private function runningQuery(CarbonImmutable $startedBefore): Builder
    {
        return ScheduledTaskRun::query()
            ->where('status', ScheduledTaskRunStatus::Running->value)
            ->where(function (Builder $query) use ($startedBefore): void {
                $query->where('started_at', '<', $startedBefore)
                    ->orWhere(function (Builder $query) use ($startedBefore): void {
                        $query->whereNull('started_at')
                            ->where('created_at', '<', $startedBefore);
                    });
            });
    }

    /**
     * @return Builder<ScheduledTaskRun>
     */
    private function completedQuery(int $longerThanMinutes): Builder
    {
        return ScheduledTaskRun::query()
            ->whereIn('status', [
                ScheduledTaskRunStatus::Success->value,
                ScheduledTaskRunStatus::Failure->value,
            ])
            ->where('duration_ms', '>', $longerThanMinutes * 60_000);
    }

    /**
     * @param  Builder<ScheduledTaskRun>  $query
     * @param  callable(array<int, int>): int  $apply
     */
    private function applyInChunks(Builder $query, int $limit, bool $pretend, callable $apply): int
    {
        if ($pretend) {
            return min($limit, (clone $query)->count());
        }

        $touched = 0;
        $batchSize = min($this->batchSize(), $limit);

        (clone $query)
            ->select('id')
            ->chunkById($batchSize, function (Collection $runs) use (&$touched, $limit, $apply): bool {
                $ids = $runs->modelKeys();
                $ids = array_slice($ids, 0, $limit - $touched);
                $touched += $apply($ids);

                return $touched < $limit;
            });

        return $touched;
    }

    /**
     * @param  array<string, int>  $boundsByMutexHash
     */
    private function boundFor(string $mutexHash, array $boundsByMutexHash): int
    {
        return $boundsByMutexHash[$mutexHash] ?? $this->maximumMinutes();
    }

    /**
     * @return array<string, int>
     */
    private function boundsByMutexHash(CarbonImmutable $now): array
    {
        $bounds = [];

        foreach ($this->schedule->events() as $event) {
            try {
                $mutexHash = $this->identifier->mutexHash($event);
            } catch (Throwable) {
                continue;
            }

            $bound = $this->boundForEvent($event, $now);
            $bounds[$mutexHash] = max($bounds[$mutexHash] ?? 0, $bound);
        }

        return $bounds;
    }

    private function boundForEvent(ScheduledEvent $event, CarbonImmutable $now): int
    {
        $intervalMinutes = $this->intervalMinutes($event, $now);

        if ($intervalMinutes === null) {
            return $this->maximumMinutes();
        }

        $bound = (int) ceil($intervalMinutes * $this->intervalMultiple());

        return min($this->maximumMinutes(), max($this->minimumMinutes(), $bound));
    }

    private function intervalMinutes(ScheduledEvent $event, CarbonImmutable $now): ?float
    {
        if ($event->isRepeatable()) {
            return $event->repeatSeconds / 60;
        }

        try {
            $timezone = $event->timezone instanceof DateTimeZone
                ? $event->timezone->getName()
                : $event->timezone;
            $cron = new CronExpression($event->expression);
            $next = $cron->getNextRunDate($now, 0, false, $timezone);
            $following = $cron->getNextRunDate($now, 1, false, $timezone);

            $minutes = ($following->getTimestamp() - $next->getTimestamp()) / 60;

            return $minutes > 0 ? $minutes : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function intervalMultiple(): int
    {
        return $this->positiveConfig('interval_multiple', 10);
    }

    private function minimumMinutes(): int
    {
        return $this->positiveConfig('minimum_minutes', 60);
    }

    private function maximumMinutes(): int
    {
        return max($this->minimumMinutes(), $this->positiveConfig('maximum_minutes', 1440));
    }

    private function maxRowsPerPass(): int
    {
        return $this->positiveConfig('max_rows_per_pass', 10000);
    }

    private function batchSize(): int
    {
        return max(1, (int) config('scheduler_lifecycle.retention.batch_size', 1000));
    }

    private function positiveConfig(string $key, int $default): int
    {
        return max(1, (int) config("scheduler_lifecycle.abandonment.{$key}", $default));
    }
}
