<?php

namespace App\Console\Commands;

use App\Exceptions\PWQueryFailedException;
use App\Jobs\SyncWarsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use function retry;

class SyncWars extends Command
{
    protected $signature = 'sync:wars';
    protected $description = 'Fetch and update all wars for our alliance from Politics & War API';

    /**
     * @return void
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function handle(): void
    {
        $this->info('Queuing war sync jobs...');

        $perPage = 1000;

        // Fetch pagination info up front
        $pagination = retry(
            3,
            function () use ($perPage) {
                $client = new QueryService();
                $builder = (new GraphQLQueryBuilder())
                    ->setRootField("wars")
                    ->addArgument([
                        'first' => $perPage,
                        'active' => false,
                        'alliance_id' => (int)env("PW_ALLIANCE_ID"),
                    ])
                    ->addNestedField(
                        "data",
                        fn(GraphQLQueryBuilder $builder) => $builder->addFields(SelectionSetHelper::warSet())
                    )
                    ->withPaginationInfo();

                return $client->getPaginationInfo($builder);
            },
            1000 // milliseconds delay between retries
        );

        $lastPage = $pagination['lastPage'] ?? 1;

        for ($page = 1; $page <= $lastPage; $page++) {
            SyncWarsJob::dispatch($page, $perPage);
            $this->info("Queued war sync for page {$page}.");
        }

        $this->info("✅ Queued all {$lastPage} war sync job(s)!");
    }
}
