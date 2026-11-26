<?php

namespace App\Console\Commands;

use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Signature('milcom:prune-history {--pretend : Report matching rows without deleting them}')]
#[Description('Prune old Milcom recommendation runs and readiness snapshots that nothing references any more')]
class PruneMilcomHistory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $batchSize = max(1, (int) config('milcom.retention.batch_size', 500));
        $runCutoff = now()->subDays(
            max(1, (int) config('milcom.retention.recommendation_run_days', 60)),
        );
        $snapshotCutoff = now()->subDays(
            max(1, (int) config('milcom.retention.readiness_snapshot_days', 14)),
        );

        $counts = [
            'recommendation runs' => $this->pruneRuns($runCutoff, $pretend, $batchSize),
            'readiness snapshots' => $this->pruneSnapshots($snapshotCutoff, $pretend, $batchSize),
        ];

        $total = array_sum($counts);
        $action = $pretend ? 'would prune' : 'pruned';

        $this->components->info("{$total} Milcom history rows {$action}.");

        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * Finished runs of finished operations, past retention. Snapshots and
     * per-objective recommendations of a deleted run cascade with it.
     */
    private function pruneRuns(CarbonInterface $cutoff, bool $pretend, int $batchSize): int
    {
        $query = $this->excludeProtectedRuns(
            MilcomRecommendationRun::query()
                ->whereIn('status', [
                    RecommendationRunStatus::Succeeded->value,
                    RecommendationRunStatus::Failed->value,
                    RecommendationRunStatus::Superseded->value,
                ])
                ->where('created_at', '<', $cutoff)
                ->whereIn('operation_id', MilcomOperation::query()
                    ->select('id')
                    ->whereIn('status', [
                        OperationStatus::Completed->value,
                        OperationStatus::Archived->value,
                    ])),
            'id',
        );

        $query->whereNotIn('id', $this->newestSnapshotPerNation('recommendation_run_id'));

        return $this->deleteInChunks($query, MilcomRecommendationRun::class, 'id', $pretend, $batchSize);
    }

    /**
     * Snapshots past retention that no current view reads.
     */
    private function pruneSnapshots(CarbonInterface $cutoff, bool $pretend, int $batchSize): int
    {
        $query = $this->excludeProtectedRuns(
            MilcomReadinessSnapshot::query()->where('fetched_at', '<', $cutoff),
            'recommendation_run_id',
        )->whereNotIn('id', $this->newestSnapshotPerNation('id'));

        return $this->deleteInChunks($query, MilcomReadinessSnapshot::class, 'id', $pretend, $batchSize);
    }

    /**
     * The snapshot the Discord readiness lookup returns for each nation: the
     * newest by fetched_at, with no age limit. Rows tied on fetched_at are all kept.
     *
     * @return Builder<MilcomReadinessSnapshot>
     */
    private function newestSnapshotPerNation(string $column): Builder
    {
        return MilcomReadinessSnapshot::query()
            ->from('milcom_readiness_snapshots as newest')
            ->joinSub(
                MilcomReadinessSnapshot::query()
                    ->selectRaw('nation_id, MAX(fetched_at) as max_fetched_at')
                    ->groupBy('nation_id'),
                'latest',
                fn ($join) => $join
                    ->on('latest.nation_id', '=', 'newest.nation_id')
                    ->on('latest.max_fetched_at', '=', 'newest.fetched_at'),
            )
            ->select("newest.{$column}");
    }

    /**
     * Keep every run that something still points at or displays: the run an
     * objective names as its latest, the newest run of each objective and of
     * each operation, runs holding an objective's newest recommendation, runs
     * assignments were made from, anything still queued or running, and the run
     * holding the newest snapshot of any nation (see newestSnapshotPerNation()).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function excludeProtectedRuns(Builder $query, string $runColumn): Builder
    {
        return $query
            ->whereNotIn($runColumn, MilcomObjective::query()
                ->select('latest_recommendation_run_id')
                ->whereNotNull('latest_recommendation_run_id'))
            ->whereNotIn($runColumn, MilcomAssignment::query()
                ->select('recommendation_run_id')
                ->whereNotNull('recommendation_run_id'))
            ->whereNotIn($runColumn, MilcomRecommendationRun::query()
                ->selectRaw('MAX(id)')
                ->whereNotNull('objective_id')
                ->groupBy('objective_id'))
            ->whereNotIn($runColumn, MilcomRecommendationRun::query()
                ->selectRaw('MAX(id)')
                ->whereNull('objective_id')
                ->where('status', RecommendationRunStatus::Succeeded->value)
                ->groupBy('operation_id'))
            ->whereNotIn($runColumn, MilcomObjectiveRecommendation::query()
                ->select('recommendation_run_id')
                ->whereIn('id', MilcomObjectiveRecommendation::query()
                    ->selectRaw('MAX(id)')
                    ->groupBy('objective_id')))
            ->whereNotIn($runColumn, MilcomRecommendationRun::query()
                ->select('id')
                ->whereIn('status', [
                    RecommendationRunStatus::Queued->value,
                    RecommendationRunStatus::Running->value,
                ]));
    }

    /**
     * @param  Builder<*>  $query
     * @param  class-string<Model>  $model
     */
    private function deleteInChunks(Builder $query, string $model, string $key, bool $pretend, int $batchSize): int
    {
        if ($pretend) {
            return (clone $query)->count();
        }

        $deleted = 0;
        $lastId = 0;

        do {
            $ids = (clone $query)
                ->where($key, '>', $lastId)
                ->orderBy($key)
                ->limit($batchSize)
                ->pluck($key);

            if ($ids->isEmpty()) {
                break;
            }

            $lastId = (int) $ids->last();
            $deleted += $model::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === $batchSize);

        return $deleted;
    }
}
