<?php

namespace App\Jobs;

use App\Enums\BlockadeReliefStatus;
use App\Models\BlockadeReliefRequest;
use App\Services\BlockadeRelief\BlockadeReliefService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileBlockadeReliefRequests implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(BlockadeReliefService $service): void
    {
        BlockadeReliefRequest::query()
            ->whereIn('status', [BlockadeReliefStatus::Pending->value, BlockadeReliefStatus::Claimed->value])
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($service): void {
                foreach ($requests as $request) {
                    $service->reconcile($request);
                }
            });
    }
}
