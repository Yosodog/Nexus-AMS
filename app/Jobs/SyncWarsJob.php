<?php

namespace App\Jobs;

use App\Models\Wars;
use App\Services\WarQueryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWarsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $page, public int $perPage)
    {
    }

    public function handle(): void
    {
        try {
            $wars = WarQueryService::getMultipleWars([
                'page' => $this->page,
                'active' => false,
                'alliance_id' => (int)config('pw.alliance_id'),
            ], $this->perPage, pagination: true, handlePagination: false);

            foreach ($wars as $war) {
                Wars::updateFromAPI($war);
            }

            unset($wars);
            gc_collect_cycles();
        } catch (Throwable $e) {
            Log::error("Failed to sync wars: {$e->getMessage()}");
        }
    }
}
