<?php

namespace App\Console\Commands;

use App\Services\Economy\MarketTradeIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('market-prices:refresh')]
#[Description('Refresh side-specific market prices from completed global trades')]
class RefreshMarketPrices extends Command
{
    public function handle(MarketTradeIngestionService $service): int
    {
        try {
            $snapshot = $service->refresh();
            $this->info("Published market price snapshot {$snapshot->id}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Market price refresh failed', ['exception' => $exception]);
            $this->error('Market prices were not published. The last valid snapshot remains active.');

            return self::FAILURE;
        }
    }
}
