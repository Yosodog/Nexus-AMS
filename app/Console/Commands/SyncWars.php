<?php

namespace App\Console\Commands;

use App\Jobs\SyncWarsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use Illuminate\Console\Command;

class SyncWars extends Command
{
    protected $signature = 'sync:wars';
    protected $description = 'Fetch and update all wars for our alliance from Politics & War API';

    public function handle(): void
    {
        $this->info('Queuing war sync jobs...');

        $perPage = 1000;
        $page = 1;
        $lastPage = 1;

        do {
            SyncWarsJob::dispatch($page, $perPage);
            $this->info("Queued war sync for page {$page}.");

            if ($page === 1) {
                $client = new QueryService();

                $builder = (new GraphQLQueryBuilder())
                    ->setRootField("wars")
                    ->addArgument([
                        'first' => $perPage,
                        'active' => false,
                        'alliance_id' => (int)env("PW_ALLIANCE_ID"),
                    ])
                    ->addNestedField("data", fn(GraphQLQueryBuilder $builder) => $builder->addFields(SelectionSetHelper::warSet()))
                    ->withPaginationInfo();

                $pagination = $client->getPaginationInfo($builder);
                $lastPage = $pagination['lastPage'] ?? 1;
            }

            $page++;
        } while ($page <= $lastPage);

        $this->info('All war sync jobs queued!');
    }
}
