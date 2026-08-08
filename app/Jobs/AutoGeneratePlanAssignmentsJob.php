<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Compatibility envelope for legacy queued work created before the Milcom v2 cutover. */
class AutoGeneratePlanAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $planId) {}

    public function handle(): void
    {
        Log::notice('Ignored retired legacy war-plan assignment job.', [
            'war_plan_id' => $this->planId,
        ]);
    }
}
