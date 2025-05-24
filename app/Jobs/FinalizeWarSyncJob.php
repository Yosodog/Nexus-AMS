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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeWarSyncJob implements ShouldQueue
{
    use Batchable, Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param string $batchId
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
        $keys = Cache::get("sync_batch:{$this->batchId}:pages", []);
        $allWarIds = [];

        foreach ($keys as $page) {
            $ids = Cache::pull("sync_batch:{$this->batchId}:{$page}", []);
            $allWarIds = array_merge($allWarIds, $ids);
        }

        $allWarIds = array_unique($allWarIds);

        if (empty($allWarIds)) {
            Log::warning("❌ FinalizeWarSyncJob aborted: no war IDs were collected for batch {$this->batchId}");
            Cache::forget("sync_batch:{$this->batchId}:pages");
            SettingService::setLastWarSyncBatchId($this->batchId);
            return;
        }

        $now = now();
        $cutoff = $now->copy()->subDays(5);

        $updatedCount = War::whereNull('end_date')
            ->whereNotIn('id', $allWarIds)
            ->where('start_date', '<=', $cutoff)
            ->update(['end_date' => $now]);

        Log::info("✅ FinalizeWarSyncJob: Marked {$updatedCount} stale wars as ended (batch {$this->batchId}).");

        Cache::forget("sync_batch:{$this->batchId}:pages");
        SettingService::setLastWarSyncBatchId($this->batchId);
    }
}
