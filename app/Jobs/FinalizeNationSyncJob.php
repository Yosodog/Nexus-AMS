<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class FinalizeNationSyncJob implements ShouldQueue
{
    use Queueable;

    public string $batchId;

    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    public function handle(): void
    {
        $batch = Bus::findBatch($this->batchId);

        if ($batch?->cancelled()) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} was cancelled.");
            SettingService::setLastNationSyncBatchId($this->batchId);
            return;
        }

        $cutoff = now()->subDays(30);

        $staleNationCount = Nation::where('updated_at', '<', $cutoff)->count();

        Nation::where('updated_at', '<', $cutoff)->delete();
        NationResources::whereHas('nation', fn($q) => $q->where('updated_at', '<', $cutoff))->delete();
        NationMilitary::whereHas('nation', fn($q) => $q->where('updated_at', '<', $cutoff))->delete();
        City::whereHas('nation', fn($q) => $q->where('updated_at', '<', $cutoff))->delete();

        Log::info("🧹 FinalizeNationSyncJob: Soft-deleted {$staleNationCount} nations not updated since {$cutoff->toDateTimeString()}.");

        SettingService::setLastNationSyncBatchId($this->batchId);
    }
}