<?php

namespace App\Console\Commands;

use App\Services\GrowthCircleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DistributeGrowthCirclesCommand extends Command
{
    protected $signature = 'growth-circles:distribute {--cycle-date= : Override the cycle date (YYYY-MM-DD UTC). Defaults to today UTC.}';

    protected $description = 'Run the daily Growth Circles distribution: credit each enrolled member their resource shortfalls.';

    public function handle(GrowthCircleService $service): int
    {
        $cycleDate = $this->option('cycle-date') ?: Carbon::now('UTC')->toDateString();
        $counts = $service->runDailyDistribution($cycleDate);

        $message = sprintf(
            'Growth Circles distribution complete for %s: %d distributed, %d skipped, %d failed.',
            $cycleDate,
            $counts['distributed'],
            $counts['skipped'],
            $counts['failed'],
        );
        $this->info($message);

        Log::info('Growth Circles distribution completed', [
            'cycle_date' => $cycleDate,
            ...$counts,
        ]);

        return self::SUCCESS;
    }
}
