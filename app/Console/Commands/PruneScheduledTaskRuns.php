<?php

namespace App\Console\Commands;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('scheduler-lifecycle:prune {--pretend : Report matching runs without deleting them}')]
#[Description('Prune scheduled task lifecycle runs using the configured retention windows')]
class PruneScheduledTaskRuns extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $batchSize = $this->positiveConfig('batch_size', 1000);
        $slowThresholdMs = $this->positiveConfig('slow_threshold_ms', 60000);

        $counts = [
            'routine successes' => $this->prune(
                ScheduledTaskRun::query()
                    ->where('status', ScheduledTaskRunStatus::Success->value)
                    ->where(function (Builder $query) use ($slowThresholdMs): void {
                        $query->whereNull('duration_ms')
                            ->orWhere('duration_ms', '<', $slowThresholdMs);
                    })
                    ->where('finished_at', '<', now()->subDays(
                        $this->positiveConfig('routine_success_days', 14),
                    )),
                $pretend,
                $batchSize,
            ),
            'slow successes' => $this->prune(
                ScheduledTaskRun::query()
                    ->where('status', ScheduledTaskRunStatus::Success->value)
                    ->where('duration_ms', '>=', $slowThresholdMs)
                    ->where('finished_at', '<', now()->subDays(
                        $this->positiveConfig('slow_success_days', 90),
                    )),
                $pretend,
                $batchSize,
            ),
            'failures' => $this->prune(
                $this->terminalQuery(
                    ScheduledTaskRunStatus::Failure,
                    $this->positiveConfig('failure_days', 90),
                ),
                $pretend,
                $batchSize,
            ),
            'skips' => $this->prune(
                $this->terminalQuery(
                    ScheduledTaskRunStatus::Skipped,
                    $this->positiveConfig('skipped_days', 14),
                ),
                $pretend,
                $batchSize,
            ),
            'overlaps' => $this->prune(
                $this->terminalQuery(
                    ScheduledTaskRunStatus::Overlap,
                    $this->positiveConfig('overlap_days', 30),
                ),
                $pretend,
                $batchSize,
            ),
            'abandoned running records' => $this->prune(
                ScheduledTaskRun::query()
                    ->where('status', ScheduledTaskRunStatus::Running->value)
                    ->where('created_at', '<', now()->subDays(
                        $this->positiveConfig('running_days', 90),
                    )),
                $pretend,
                $batchSize,
            ),
        ];

        $total = array_sum($counts);
        $action = $pretend ? 'would prune' : 'pruned';

        $this->components->info("{$total} scheduled task lifecycle runs {$action}.");

        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ScheduledTaskRun>
     */
    private function terminalQuery(ScheduledTaskRunStatus $status, int $retentionDays): Builder
    {
        return ScheduledTaskRun::query()
            ->where('status', $status->value)
            ->where('finished_at', '<', now()->subDays($retentionDays));
    }

    /**
     * @param  Builder<ScheduledTaskRun>  $query
     */
    private function prune(Builder $query, bool $pretend, int $batchSize): int
    {
        if ($pretend) {
            return (clone $query)->count();
        }

        $deleted = 0;

        (clone $query)
            ->select('id')
            ->chunkById($batchSize, function ($runs) use (&$deleted): void {
                $ids = $runs->modelKeys();
                $deleted += ScheduledTaskRun::query()->whereKey($ids)->delete();
            });

        return $deleted;
    }

    private function positiveConfig(string $key, int $default): int
    {
        return max(1, (int) config("scheduler_lifecycle.retention.{$key}", $default));
    }
}
