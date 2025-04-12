<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\WarQueryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWarsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public function __construct(public int $page, public int $perPage)
    {
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        echo "doing page $this->page \n";
        try {
            $wars = WarQueryService::getMultipleWars([
                'page' => $this->page,
                'active' => false,
                'alliance_id' => (int)env("PW_ALLIANCE_ID"),
            ], $this->perPage, pagination: true, handlePagination: false);

            foreach ($wars as $war) {
                War::updateFromAPI($war);
            }

            unset($wars);
            gc_collect_cycles();
        } catch (Throwable $e) {
            Log::error("Failed to sync wars: {$e->getMessage()}");
        }
    }
}
