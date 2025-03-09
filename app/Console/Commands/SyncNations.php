<?php

namespace App\Console\Commands;

use App\Jobs\SyncNationsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use Illuminate\Console\Command;

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

        $perPage = 500;
        $page = 1;
        $lastPage = 1;

        do {
            // Dispatch a job for each page
            SyncNationsJob::dispatch($page, $perPage);

            $this->info("Queued batch for page {$page}.");

            // Simulate an initial request to check the last page
            if ($page == 1) {
                $client = new QueryService();
                $builder = (new GraphQLQueryBuilder())
                    ->setRootField("nations")
                    ->addArgument('first', $perPage)
                    ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                        $builder->addFields(SelectionSetHelper::nationSet());
                    })->withPaginationInfo();

                $response = $client->getPaginationInfo($builder);

                $lastPage = $response['lastPage'] ?? 1;
            }

            $page++;
        } while ($page <= $lastPage);

        $this->info("All nation sync jobs have been queued.");
    }
}
