<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Services\Economy\EconomyContextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('economy-context:refresh')]
#[Description('Refresh treasure and color revenue inputs for nation profitability')]
class RefreshEconomyContext extends Command
{
    public function handle(EconomyContextService $service): int
    {
        try {
            $count = $service->refresh();
            $summary = ['synchronized' => $count, 'skipped' => 0, 'failed' => 0];
            Log::info('Economy context refresh completed', $summary);
            $this->info(
                "Economy context refresh completed: {$summary['synchronized']} synchronized, "
                ."{$summary['skipped']} skipped, {$summary['failed']} failed."
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $summary = [
                'synchronized' => 0,
                'skipped' => 0,
                'failed' => Nation::query()->count(),
                'exception' => $exception,
            ];
            Log::error('Economy context refresh failed', $summary);
            $this->error(
                "Economy context refresh failed: 0 synchronized, 0 skipped, {$summary['failed']} failed."
            );

            return self::FAILURE;
        }
    }
}
