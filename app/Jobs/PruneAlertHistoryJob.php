<?php

namespace App\Jobs;

use App\Services\Alerts\AlertRetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneAlertHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function handle(AlertRetentionService $retention): void
    {
        $retention->prune();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Alert history pruning failed.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
