<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeNationSyncJob;
use App\Jobs\SyncNationsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SyncNations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:nations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and update all nations from Politics & War API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queuing nation sync jobs...');
        $this->cancelRollingBatchIfActive();

        $perPage = 100;
        $page = 1;
        $jobs = [];

        do {
            $jobs[] = new SyncNationsJob($page, $perPage, null);

            if ($page === 1) {
                $client = new QueryService;
                $builder = (new GraphQLQueryBuilder)
                    ->setRootField('nations')
                    ->addArgument('first', $perPage)
                    ->addNestedField('data', fn ($b) => $b->addFields(SelectionSetHelper::nationSet()))
                    ->withPaginationInfo();

                $response = $client->getPaginationInfo($builder);
                $lastPage = $response['lastPage'] ?? 1;
            }

            $page++;
        } while ($page <= $lastPage);

        $batch = Bus::batch($jobs)
            ->name('Nation Sync - '.Carbon::now()->toDateTimeString())
            ->then(fn (Batch $batch) => FinalizeNationSyncJob::dispatch($batch->id))
            ->allowFailures()
            ->withOption('mode', 'manual')
            ->dispatch();

        SettingService::setLastManualNationSyncBatchId($batch->id);

        $this->info("Queued {$page} nation sync jobs in a batch.");
        $this->info('All nation sync jobs have been queued.');
    }

    private function cancelRollingBatchIfActive(): void
    {
        $rollingBatchId = SettingService::getLastRollingNationSyncBatchId();

        if (! $rollingBatchId) {
            return;
        }

        $rollingBatch = Bus::findBatch($rollingBatchId);

        if ($rollingBatch && ! $rollingBatch->finished() && ! $rollingBatch->cancelled()) {
            $rollingBatch->cancel();
            $this->warn("Cancelled rolling nation sync batch {$rollingBatch->id} before running manual sync.");
        }
    }
}
