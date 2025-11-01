<?php

namespace App\Observers;

use App\Models\Offshore;
use App\Services\OffshoreService;
use Illuminate\Support\Facades\Log;

class OffshoreObserver
{
    public function __construct(private readonly OffshoreService $offshoreService) {}

    public function created(Offshore $offshore): void
    {
        $this->offshoreService->clearCaches($offshore);
        $this->log('created', $offshore);
    }

    public function updated(Offshore $offshore): void
    {
        $this->offshoreService->clearCaches($offshore);
        $this->log('updated', $offshore);
    }

    public function deleted(Offshore $offshore): void
    {
        $this->offshoreService->clearCaches($offshore);
        $this->log('deleted', $offshore);
    }

    public function restored(Offshore $offshore): void
    {
        $this->offshoreService->clearCaches($offshore);
        $this->log('restored', $offshore);
    }

    public function forceDeleted(Offshore $offshore): void
    {
        $this->offshoreService->clearCaches($offshore);
        $this->log('force-deleted', $offshore);
    }

    protected function log(string $action, Offshore $offshore): void
    {
        Log::info("Offshore {$action}", [
            'offshore_id' => $offshore->id,
            'name' => $offshore->name,
            'alliance_id' => $offshore->alliance_id,
            'enabled' => $offshore->enabled,
            'priority' => $offshore->priority,
        ]);
    }
}
