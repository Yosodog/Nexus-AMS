<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAlliancesJob implements ShouldQueue
{
    use Queueable;

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
        try {
            // Fetch all nations
            $alliances = AllianceQueryService::getMultipleAlliances(["page" => $this->page],
                $this->perPage,
                handlePagination: false);

            foreach ($alliances as $alliance) {
                Alliance::updateFromAPI($alliance, false);
            }

            // Without this memory cleanup, the queue worker will fail after ~8 jobs.
            unset($alliances);
            gc_collect_cycles();
        } catch (Exception $e) {
            Log::error("Failed to fetch alliances: " . $e->getMessage());
        }
    }
}
