<?php

namespace App\Console\Commands;

use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Services\NationProfitabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshNationProfitabilitySnapshots extends Command
{
    protected $signature = 'profitability:refresh';

    protected $description = 'Refresh stored profitability snapshots for eligible alliance nations';

    public function handle(NationProfitabilityService $profitabilityService): int
    {
        try {
            $prices = $profitabilityService->getMarketPriceSet();

            if ($prices->snapshotId === null) {
                throw new ProfitabilityPricingUnavailable(
                    'A completed-market price snapshot is required before refreshing profitability.'
                );
            }

            $count = $profitabilityService->refreshAllianceSnapshots($prices->snapshotId);
            $this->info('Refreshed profitability snapshots for '.$count.' nations.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Profitability snapshot refresh failed', ['exception' => $exception]);
            $this->error('Profitability snapshots were not refreshed. Existing snapshots remain active.');

            return self::FAILURE;
        }
    }
}
