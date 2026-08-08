<?php

namespace App\Jobs;

use App\Services\Alerts\AlertScheduledDeliveryDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchScheduledAlertBatchesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 55;

    public function handle(AlertScheduledDeliveryDispatcher $dispatcher): void
    {
        $dispatcher->dispatchDue();
    }

    public function uniqueId(): string
    {
        return 'scheduled-alert-deliveries';
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Scheduled alert delivery dispatch failed.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
