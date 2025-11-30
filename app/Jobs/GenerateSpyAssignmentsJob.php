<?php

namespace App\Jobs;

use App\Models\SpyRound;
use App\Services\Spy\SpyAssignmentBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job to generate spy assignments for a round.
 */
class GenerateSpyAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public readonly int $spyRoundId) {}

    public function handle(SpyAssignmentBuilderService $builderService): void
    {
        /** @var SpyRound|null $round */
        $round = SpyRound::query()->with('campaign')->find($this->spyRoundId);

        if (! $round) {
            Log::warning('Spy round missing during assignment generation', ['round_id' => $this->spyRoundId]);

            return;
        }

        $builderService->build($round);
    }
}
