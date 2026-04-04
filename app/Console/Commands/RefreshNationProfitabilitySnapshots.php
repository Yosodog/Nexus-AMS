<?php

namespace App\Console\Commands;

use App\Services\NationProfitabilityService;
use Illuminate\Console\Command;

class RefreshNationProfitabilitySnapshots extends Command
{
    protected $signature = 'profitability:refresh';

    protected $description = 'Refresh stored profitability snapshots for eligible alliance nations';

    public function handle(NationProfitabilityService $profitabilityService): int
    {
        $count = $profitabilityService->refreshAllianceSnapshots();

        $this->info('Refreshed profitability snapshots for '.$count.' nations.');

        return self::SUCCESS;
    }
}
