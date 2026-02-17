<?php

namespace App\Console\Commands;

use App\Services\RebuildingService;
use Illuminate\Console\Command;

class RefreshRebuildingEstimates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rebuilding:refresh-estimates {--cycle= : Optional rebuilding cycle ID override}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh rebuilding estimates for the active cycle';

    /**
     * Execute the console command.
     */
    public function __construct(private readonly RebuildingService $rebuildingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cycle = $this->option('cycle');
        $cycleId = is_numeric($cycle) ? (int) $cycle : null;

        $count = $this->rebuildingService->refreshCycleEstimates($cycleId);
        $resolvedCycle = $cycleId ?? $this->rebuildingService->getCurrentCycleId();

        $this->info("Rebuilding estimates refreshed for cycle {$resolvedCycle}. Records: {$count}");

        return self::SUCCESS;
    }
}
