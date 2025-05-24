<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\WarQueryService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWarsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, Batchable;

    public function __construct(public int $page, public int $perPage)
    {
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncWarsJob for page {$this->page} was cancelled.");
            return;
        }

        try {
            $wars = WarQueryService::getMultipleWars([
                'page' => $this->page,
                'active' => false,
                'alliance_id' => (int)env("PW_ALLIANCE_ID"),
            ], $this->perPage, pagination: true, handlePagination: false);

            $ids = [];

            foreach ($wars as $war) {
                War::updateFromAPI($war);
                $ids[] = $war->id;
            }

            Cache::put("sync_batch:{$this->batchId}:{$this->page}", $ids, now()->addHours(1));

            unset($wars, $ids);
            gc_collect_cycles();
        } catch (Throwable $e) {
            Log::error("Failed to sync wars: {$e->getMessage()}");
        }
    }
}
