<?php

namespace App\Console\Commands;

use App\Services\GrowthCircleService;
use App\Services\PWHealthService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class DistributeGrowthCircles extends Command
{
    protected $signature = 'growth-circles:distribute';

    protected $description = 'Distribute food and uranium to enrolled Growth Circle members, then run abuse detection.';

    public function handle(GrowthCircleService $service, PWHealthService $healthService): int
    {
        if (! SettingService::isGrowthCirclesEnabled()) {
            $this->info('Growth Circles is disabled. Skipping.');

            return self::SUCCESS;
        }

        if (! $healthService->isUp()) {
            $this->info('P&W API is down. Skipping Growth Circles distribution.');

            return self::SUCCESS;
        }

        $this->info('Starting Growth Circles distribution pass...');
        $summary = $service->distribute();
        $this->info("Distribution complete. Processed: {$summary['processed']}, Sent: {$summary['sent']}, Skipped: {$summary['skipped']}");

        $this->info('Starting abuse detection pass...');
        $service->runAbuseDetection();
        $this->info('Abuse detection complete.');

        return self::SUCCESS;
    }
}
