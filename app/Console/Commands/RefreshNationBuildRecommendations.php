<?php

namespace App\Console\Commands;

use App\Services\NationBuildRecommendationService;
use Illuminate\Console\Command;

class RefreshNationBuildRecommendations extends Command
{
    protected $signature = 'build-recommendations:refresh';

    protected $description = 'Refresh stored city build recommendations for eligible alliance nations';

    public function handle(NationBuildRecommendationService $recommendationService): int
    {
        $count = $recommendationService->refreshAllianceRecommendations();

        $this->info('Refreshed build recommendations for '.$count.' nations.');

        return self::SUCCESS;
    }
}
