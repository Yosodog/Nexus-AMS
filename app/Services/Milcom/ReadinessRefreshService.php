<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\ReadinessRefreshResult;
use App\Models\Nation;
use App\Services\NationQueryService;
use App\Services\RuntimeCapabilities;
use App\Services\World\NationPrivateProjector;
use Carbon\CarbonImmutable;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Telescope\Telescope;
use RuntimeException;

class ReadinessRefreshService
{
    public function __construct(
        private readonly RuntimeCapabilities $runtimeCapabilities,
        private readonly NationPrivateProjector $nationPrivateProjector,
    ) {}

    /**
     * Refresh the exact nation pool from Politics & War before snapshotting.
     *
     * @param  list<int>  $nationIds
     */
    public function refresh(array $nationIds): ReadinessRefreshResult
    {
        $nationIds = array_values(array_unique(array_map('intval', $nationIds)));

        if ($nationIds === []) {
            throw new RuntimeException('Choose at least one nation to refresh.');
        }

        $refreshed = [];
        $nations = NationQueryService::getMultipleNationsByIdsConcurrently($nationIds);

        Pulse::ignore(function () use ($nations, &$refreshed): void {
            Telescope::withoutRecording(function () use ($nations, &$refreshed): void {
                $processed = 0;

                foreach ($nations as $graphQlNation) {
                    $nation = $this->runtimeCapabilities->writesPublicWorld()
                        ? Nation::updateFromAPI($graphQlNation)
                        : $this->nationPrivateProjector->project($graphQlNation);

                    if ($nation !== null) {
                        $refreshed[] = (int) $nation->id;
                    }

                    $processed++;

                    if ($processed % 100 === 0) {
                        gc_collect_cycles();
                    }
                }
            });
        });

        $missing = array_values(array_diff($nationIds, array_unique($refreshed)));

        return new ReadinessRefreshResult(
            fetchedAt: CarbonImmutable::now(),
            refreshedNationIds: $refreshed,
            missingNationIds: $missing,
        );
    }
}
