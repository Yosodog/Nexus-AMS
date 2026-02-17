<?php

namespace App\Jobs;

use App\Services\BeigeAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchBeigeTurnAlertsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $window)
    {
        $this->onQueue('sync');
    }

    public function handle(BeigeAlertService $beigeAlertService): void
    {
        $beigeAlertService->dispatchTurnWindowAlert($this->window);
    }
}
