<?php

namespace App\Jobs;

use App\Models\Nations;
use App\Services\NationQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncNationsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 99999999;

    public int $page;
    public int $perPage;

    /**
     * Create a new job instance.
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Fetch all nations
            $nations = NationQueryService::getMultipleNations(["page" => $this->page],
                $this->perPage,
                handlePagination: false);

            foreach ($nations as $nation) {
                Nations::updateFromAPI($nation);
            }

            // Without this memory cleanup, the queue worker will fail after ~8 jobs.
            unset($nations);
            gc_collect_cycles();
        } catch (Exception $e) {
            Log::error("Failed to fetch nations: " . $e->getMessage());
        }
    }
}
