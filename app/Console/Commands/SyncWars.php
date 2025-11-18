<?php

namespace App\Console\Commands;

use App\Exceptions\PWQueryFailedException;
use App\Jobs\FinalizeWarSyncJob;
use App\Jobs\SyncWarsJob;
use App\Services\AllianceMembershipService;
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
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function handle(): int
    {
        $this->info('Queuing war sync jobs...');

        $perPage = 1000;
        $jobs = [];
        $page = 1;

        /** @var AllianceMembershipService $membershipService */
        $membershipService = app(AllianceMembershipService::class);
        $primaryAllianceId = $membershipService->getPrimaryAllianceId();

        if ($primaryAllianceId === 0) {
            $this->error('Primary alliance ID is not configured.');

            return self::FAILURE;
        }

        $pagination = retry(
            3,
            function () use ($perPage, $primaryAllianceId) {
                $client = new QueryService;
                $builder = (new GraphQLQueryBuilder)
                    ->setRootField('wars')
                    ->addArgument([
                        'first' => $perPage,
                        'active' => false,
                        'alliance_id' => $primaryAllianceId,
                    ])
                    ->addNestedField('data', fn ($b) => $b->addFields(SelectionSetHelper::warSet()))
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
            ->name('War Sync - '.now()->toDateTimeString())
            ->then(fn ($batch) => FinalizeWarSyncJob::dispatch($batch->id))
            ->allowFailures()
            ->dispatch();

        Cache::put("sync_batch:{$batch->id}:pages", range(1, $lastPage), now()->addMinutes(60));

        SettingService::setLastWarSyncBatchId($batch->id);

        $this->info("✅ Queued all {$lastPage} war sync job(s)!");

        return self::SUCCESS;
    }
}
