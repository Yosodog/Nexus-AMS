<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeNationSyncJob;
use App\Jobs\SyncNationsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SyncNationsRolling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:nations:rolling {--scope=highscore : Choose "highscore" or "all"}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a rolling nation sync over 23 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scope = $this->option('scope') ?? 'highscore';
        if (! in_array($scope, ['highscore', 'all'], true)) {
            $this->error('Invalid scope provided. Use "highscore" or "all".');

            return self::FAILURE;
        }

        $minScore = $scope === 'highscore' ? 500 : null;
        $perPage = 100;
        $lastPage = $this->determineLastPage($perPage, $minScore);

        $windowSeconds = 23 * 3600;
        $stepSeconds = max(60, intdiv($windowSeconds, max($lastPage, 1)));

        $jobs = [];
        for ($page = 1; $page <= $lastPage; $page++) {
            $delaySeconds = $stepSeconds * ($page - 1);
            $jobs[] = (new SyncNationsJob($page, $perPage, $minScore))
                ->delay(now()->addSeconds($delaySeconds));
        }

        $batchName = sprintf(
            'Rolling Nation Sync (%s) - %s',
            $scope,
            Carbon::now()->toDateTimeString()
        );

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->then(fn (Batch $batch) => FinalizeNationSyncJob::dispatch($batch->id))
            ->allowFailures()
            ->onQueue('sync')
            ->dispatch();

        SettingService::setLastNationSyncBatchId($batch->id);

        $this->info("Queued {$lastPage} rolling nation sync jobs using {$scope} scope.");

        return self::SUCCESS;
    }

    private function determineLastPage(int $perPage, ?int $minScore): int
    {
        $client = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('first', $perPage)
            ->withPaginationInfo();

        if ($minScore !== null) {
            $builder->addArgument('min_score', $minScore);
        }

        // Grab a minimal selection set to satisfy the API while focusing on pagination data.
        $builder->addNestedField('data', fn ($b) => $b->addFields(['id']));

        $response = $client->getPaginationInfo($builder);

        return max(1, (int) ($response['lastPage'] ?? 1));
    }
}
