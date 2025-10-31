<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeAllianceSyncJob;
use App\Jobs\SyncAlliancesJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use App\Services\SettingService;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SyncAlliances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:alliances';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and update all alliances from Politics & War API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queuing alliance sync jobs...');

        $perPage = 500;
        $page = 1;
        $jobs = [];

        do {
            if ($page === 1) {
                $client = new QueryService();
                $builder = (new GraphQLQueryBuilder())
                    ->setRootField("alliances")
                    ->addArgument('first', $perPage)
                    ->addNestedField("data", fn($b) => $b->addFields(SelectionSetHelper::allianceSet()))
                    ->withPaginationInfo();

                $response = $client->getPaginationInfo($builder);
                $lastPage = $response['lastPage'] ?? 1;
            }

            $jobs[] = new SyncAlliancesJob($page, $perPage);
            $page++;
        } while ($page <= $lastPage);

        $batch = Bus::batch($jobs)
            ->name("Alliance Sync - " . now()->toDateTimeString())
            ->then(fn(Batch $batch) => FinalizeAllianceSyncJob::dispatch($batch->id))
            ->allowFailures()
            ->dispatch();

        SettingService::setLastAllianceSyncBatchId($batch->id);

        $this->info("Queued {$lastPage} alliance sync jobs in a batch.");
    }
}
