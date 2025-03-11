<?php

namespace App\Console\Commands;

use App\Jobs\SyncAlliancesJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use Illuminate\Console\Command;

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
        $lastPage = 1;

        do {
            // Dispatch a job for each page
            SyncAlliancesJob::dispatch($page, $perPage);

            $this->info("Queued batch for page {$page}.");

            // Simulate an initial request to check the last page
            if ($page == 1) {
                $client = new QueryService();
                $builder = (new GraphQLQueryBuilder())
                    ->setRootField("alliances")
                    ->addArgument('first', $perPage)
                    ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                        $builder->addFields(SelectionSetHelper::allianceSet());
                    })->withPaginationInfo();

                $response = $client->getPaginationInfo($builder);

                $lastPage = $response['lastPage'] ?? 1;
            }

            $page++;
        } while ($page <= $lastPage);

        $this->info("All alliance sync jobs have been queued.");
    }
}
