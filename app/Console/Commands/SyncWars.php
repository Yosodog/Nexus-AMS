<?php

namespace App\Console\Commands;

use App\Exceptions\PWQueryFailedException;
use App\Jobs\FinalizeWarSyncJob;
use App\Jobs\SyncWarsJob;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\SelectionSetHelper;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;

use Illuminate\Support\Facades\Cache;

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
        $jobs = [];
        $page = 1;

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
                    ->addNestedField("data", fn($b) => $b->addFields(SelectionSetHelper::warSet()))
                    ->withPaginationInfo();

                return $client->getPaginationInfo($builder);
            },
            1000
        );

        $lastPage = $pagination['lastPage'] ?? 1;

        for (; $page <= $lastPage; $page++) {
            $jobs[] = new SyncWarsJob($page, $perPage);
        }

        $batch = Bus::batch($jobs)
            ->name("War Sync - " . now()->toDateTimeString())
            ->then(fn($batch) => FinalizeWarSyncJob::dispatch($batch->id))
            ->allowFailures()
            ->dispatch();

        Cache::put("sync_batch:{$batch->id}:pages", range(1, $lastPage), now()->addMinutes(60));

        SettingService::setLastWarSyncBatchId($batch->id);

        $this->info("✅ Queued all {$lastPage} war sync job(s)!");
    }
}
