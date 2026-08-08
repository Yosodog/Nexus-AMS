<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Compatibility envelope for legacy queued work created before the Milcom v2 cutover. */
class AutoPickCounterAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $counterId) {}

    public function handle(): void
    {
        Log::notice('Ignored retired legacy war-counter assignment job.', [
            'war_counter_id' => $this->counterId,
        ]);
    }
}
