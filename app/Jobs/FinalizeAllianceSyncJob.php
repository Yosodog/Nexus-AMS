<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeAllianceSyncJob implements ShouldQueue
{
    use Queueable;

    public string $batchId;

    /**
     * @param string $batchId
     */
    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $keys = Cache::get("sync_batch:{$this->batchId}:pages", []);
        $allAllianceIds = [];

        foreach ($keys as $page) {
            $ids = Cache::pull("sync_batch:{$this->batchId}:{$page}", []);
            $allAllianceIds = array_merge($allAllianceIds, $ids);
        }

        $allAllianceIds = array_unique($allAllianceIds);

        if (empty($allAllianceIds)) {
            Log::warning("❌ FinalizeAllianceSyncJob aborted: no alliance IDs were collected for batch {$this->batchId}");

            Cache::forget("sync_batch:{$this->batchId}:pages");
            SettingService::setLastAllianceSyncBatchId($this->batchId);

            return;
        }

        Alliance::whereNotIn('id', $allAllianceIds)->delete();

        Cache::forget("sync_batch:{$this->batchId}:pages");
        SettingService::setLastAllianceSyncBatchId($this->batchId);
    }
}
