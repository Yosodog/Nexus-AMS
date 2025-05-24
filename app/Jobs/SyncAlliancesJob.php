<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncAlliancesJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $page;
    public int $perPage;

    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Create a new job instance.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncAlliancesJob for page {$this->page} was cancelled.");
            return;
        }

        try {
            $alliances = AllianceQueryService::getMultipleAlliances([
                "page" => $this->page
            ], $this->perPage, handlePagination: false);

            $ids = [];

            foreach ($alliances as $alliance) {
                Alliance::updateFromAPI($alliance, false);
                $ids[] = $alliance->id;
            }

            // Cache IDs for finalization
            Cache::put("sync_batch:{$this->batchId}:{$this->page}", $ids, now()->addHours(1));

            unset($alliances, $ids);
            gc_collect_cycles();
        } catch (Exception $e) {
            Log::error("Failed to fetch alliances (page {$this->page}): " . $e->getMessage());
        }
    }
}
