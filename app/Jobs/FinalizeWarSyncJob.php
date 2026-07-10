<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\SettingService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeWarSyncJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    /**
     * Execute the job.
     *
     * This finalizer sets `end_date` on wars that were not included in the current batch,
     * if they are older than 5 days and still marked as active (null `end_date`).
     */
    public function handle(): void
    {
        $batch = Bus::findBatch($this->batchId);

        if (! $batch) {
            Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} could not be found.");
            $this->flushBatchCache();
            SettingService::setLastWarSyncBatchId($this->batchId);

            return;
        }

        if ($batch->cancelled()) {
            Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} was cancelled.");
            $this->flushBatchCache();
            SettingService::setLastWarSyncBatchId($this->batchId);

            return;
        }

        if ($batch->failedJobs > 0) {
            Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} had {$batch->failedJobs} failure(s).");
            $this->flushBatchCache();
            SettingService::setLastWarSyncBatchId($this->batchId);

            return;
        }

        $expectedPages = Cache::get("sync_batch:{$this->batchId}:pages");
        $expectedJobCount = (int) $batch->totalJobs;

        if (
            $expectedJobCount < 1
            || ! is_array($expectedPages)
            || $expectedPages !== range(1, $expectedJobCount)
        ) {
            Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} did not have a complete expected-page manifest.");
            $this->flushBatchCache();
            SettingService::setLastWarSyncBatchId($this->batchId);

            return;
        }

        $allWarIds = [];

        foreach ($expectedPages as $page) {
            $ids = Cache::get("sync_batch:{$this->batchId}:{$page}");

            if (! is_array($ids) || $ids === []) {
                Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} was missing a completed result for page {$page}.");
                $this->flushBatchCache();
                SettingService::setLastWarSyncBatchId($this->batchId);

                return;
            }

            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    Log::warning("FinalizeWarSyncJob skipped — batch {$this->batchId} had an invalid war ID for page {$page}.");
                    $this->flushBatchCache();
                    SettingService::setLastWarSyncBatchId($this->batchId);

                    return;
                }
            }

            $allWarIds = array_merge($allWarIds, $ids);
        }

        $processedCount = count($allWarIds);
        $allWarIds = array_values(array_unique($allWarIds));

        $now = now();
        $cutoff = $now->copy()->subDays(5);

        $updatedCount = War::whereNull('end_date')
            ->whereNotIn('id', $allWarIds)
            ->where('date', '<=', $cutoff)
            ->update(['end_date' => $now]);

        Log::info("✅ FinalizeWarSyncJob: Marked {$updatedCount} stale wars as ended (batch {$this->batchId}, processed {$processedCount}).");

        $this->flushBatchCache();
        SettingService::setLastWarSyncBatchId($this->batchId);
    }

    private function flushBatchCache(): void
    {
        $pages = Cache::pull("sync_batch:{$this->batchId}:pages", []);

        foreach ($pages as $page) {
            Cache::forget("sync_batch:{$this->batchId}:{$page}");
        }

        Cache::forget("sync_batch:{$this->batchId}:wars_processed");
    }
}
