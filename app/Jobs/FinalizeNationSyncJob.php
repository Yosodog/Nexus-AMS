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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeNationSyncJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var string
     */
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
        $batch = Bus::findBatch($this->batchId);

        if ($batch?->cancelled()) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} was cancelled.");
            Cache::forget("sync_batch:{$this->batchId}:pages");
            SettingService::setLastNationSyncBatchId($this->batchId);
            return;
        }

        $keys = Cache::get("sync_batch:{$this->batchId}:pages", []);
        $allNationIds = [];

        foreach ($keys as $page) {
            $ids = Cache::pull("sync_batch:{$this->batchId}:{$page}", []);
            $allNationIds = array_merge($allNationIds, $ids);
        }

        $allNationIds = array_unique($allNationIds);

        if (empty($allNationIds)) {
            Log::warning("❌ FinalizeNationSyncJob aborted: no nation IDs were collected for batch {$this->batchId}");

            Cache::forget("sync_batch:{$this->batchId}:pages");
            SettingService::setLastNationSyncBatchId($this->batchId);

            return;
        }

        Nation::whereNotIn('id', $allNationIds)->delete();
        NationResources::whereNotIn('nation_id', $allNationIds)->delete();
        NationMilitary::whereNotIn('nation_id', $allNationIds)->delete();
        City::whereNotIn('nation_id', $allNationIds)->delete();

        Cache::forget("sync_batch:{$this->batchId}:pages");
        SettingService::setLastNationSyncBatchId($this->batchId);
    }
}
