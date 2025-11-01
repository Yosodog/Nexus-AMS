<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeAllianceSyncJob implements ShouldQueue
{
    use Queueable;

    public string $batchId;

    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    /**
     * Handles the finalization process for the Alliance synchronization job.
     *
     * This method checks if the associated batch job has been cancelled before proceeding.
     * It removes stale Alliance records that have not been updated in the past 30 days
     * and logs the count of deleted records. Finally, it updates the last processed batch ID
     * for Alliance synchronization.
     */
    public function handle(): void
    {
        $batch = Bus::findBatch($this->batchId);

        if (! $batch) {
            Log::warning("FinalizeAllianceSyncJob skipped — batch {$this->batchId} could not be found.");
            Cache::forget("sync_batch:{$this->batchId}:alliances_processed");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        if ($batch->cancelled()) {
            Log::warning("FinalizeAllianceSyncJob skipped — batch {$this->batchId} was cancelled.");
            Cache::forget("sync_batch:{$this->batchId}:alliances_processed");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        if ($batch->failedJobs > 0) {
            Log::warning("FinalizeAllianceSyncJob skipped — batch {$this->batchId} had {$batch->failedJobs} failure(s).");
            Cache::forget("sync_batch:{$this->batchId}:alliances_processed");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        $processedCount = Cache::pull("sync_batch:{$this->batchId}:alliances_processed", 0);

        if ($processedCount === 0) {
            Log::warning("FinalizeAllianceSyncJob skipped — no alliances were recorded as processed for batch {$this->batchId}.");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        $cutoff = now()->subDays(30);

        $staleCount = Alliance::where('updated_at', '<', $cutoff)->count();
        $totalCount = Alliance::count();

        if ($totalCount > 0 && $staleCount >= $totalCount) {
            Log::error("FinalizeAllianceSyncJob aborted — stale alliance count ({$staleCount}) matched total alliances ({$totalCount}).");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        Alliance::where('updated_at', '<', $cutoff)->delete();

        Log::info("🧹 FinalizeAllianceSyncJob: Soft-deleted {$staleCount} alliances not updated since {$cutoff->toDateTimeString()} (processed {$processedCount}).");

        SettingService::setLastAllianceSyncBatchId($this->batchId);
    }
}
