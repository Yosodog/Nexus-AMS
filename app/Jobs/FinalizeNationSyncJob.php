<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Services\SettingService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
        $mode = $this->determineMode($batch);

        if (! $batch) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} could not be found.");
            Cache::forget("sync_batch:{$this->batchId}:nations_processed");
            $this->recordBatchId($mode);

            return;
        }

        if ($batch->cancelled()) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} was cancelled.");
            Cache::forget("sync_batch:{$this->batchId}:nations_processed");
            $this->recordBatchId($mode);

            return;
        }

        if ($batch->failedJobs > 0) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} had {$batch->failedJobs} failure(s).");
            Cache::forget("sync_batch:{$this->batchId}:nations_processed");
            $this->recordBatchId($mode);

            return;
        }

        $processedCount = Cache::pull("sync_batch:{$this->batchId}:nations_processed", 0);

        if ($processedCount === 0) {
            Log::warning("FinalizeNationSyncJob skipped — no nations were recorded as processed for batch {$this->batchId}.");
            $this->recordBatchId($mode);

            return;
        }

        $cutoff = now()->subDays(30);

        $staleNationCount = Nation::where('updated_at', '<', $cutoff)->count();
        $totalNationCount = Nation::count();

        if ($totalNationCount > 0 && $staleNationCount >= $totalNationCount) {
            Log::error("FinalizeNationSyncJob aborted — stale nation count ({$staleNationCount}) matched total nations ({$totalNationCount}).");
            $this->recordBatchId($mode);

            return;
        }

        Nation::where('updated_at', '<', $cutoff)->delete();
        NationResources::whereHas('nation', fn ($q) => $q->where('updated_at', '<', $cutoff))->delete();
        NationMilitary::whereHas('nation', fn ($q) => $q->where('updated_at', '<', $cutoff))->delete();
        City::whereHas('nation', fn ($q) => $q->where('updated_at', '<', $cutoff))->delete();

        Log::info("🧹 FinalizeNationSyncJob: Soft-deleted {$staleNationCount} nations not updated since {$cutoff->toDateTimeString()} (processed {$processedCount}).");

        $this->recordBatchId($mode);
    }

    private function determineMode(?Batch $batch): string
    {
        $mode = $batch?->options['mode'] ?? null;

        if ($mode === 'rolling') {
            return 'rolling';
        }

        if ($mode === 'manual') {
            return 'manual';
        }

        if (SettingService::getLastRollingNationSyncBatchId() === $this->batchId) {
            return 'rolling';
        }

        return 'manual';
    }

    private function recordBatchId(string $mode): void
    {
        if ($mode === 'rolling') {
            SettingService::setLastRollingNationSyncBatchId($this->batchId);

            return;
        }

        SettingService::setLastManualNationSyncBatchId($this->batchId);
    }
}
