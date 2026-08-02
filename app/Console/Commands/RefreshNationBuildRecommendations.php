<?php

namespace App\Console\Commands;

use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Services\NationBuildRecommendationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshNationBuildRecommendations extends Command
{
    protected $signature = 'build-recommendations:refresh';

    protected $description = 'Refresh stored city build recommendations for eligible alliance nations';

    public function handle(NationBuildRecommendationService $recommendationService): int
    {
        try {
            $prices = $recommendationService->getPriceSetForBatch();

            if ($prices->snapshotId === null) {
                throw new ProfitabilityPricingUnavailable(
                    'A completed-market price snapshot is required before queueing recommendations.'
                );
            }

            $radiationSnapshot = $recommendationService->getRadiationSnapshotForBatch();
            $nationIds = $recommendationService->eligibleNationIds();
            $recommendationService->assertBatchCalculationContextAvailable($nationIds, $radiationSnapshot);
            $recommendationService->pruneIneligibleRecommendations($nationIds);

            foreach ($nationIds as $nationId) {
                RefreshNationBuildRecommendationJob::dispatch(
                    $nationId,
                    $prices->snapshotId,
                    $radiationSnapshot->id
                );
            }

            $this->info('Queued build recommendations for '.count($nationIds).' nations.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Build recommendation batch dispatch failed', ['exception' => $exception]);
            $this->error('No build recommendations were queued. Existing recommendations remain active.');

            return self::FAILURE;
        }
    }
}
