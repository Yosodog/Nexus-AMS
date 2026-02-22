<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Models\WarCounter;
use App\Services\AllianceMembershipService;
use App\Services\PWHealthService;
use App\Services\War\CounterAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-selects counter assignments for a given aggressor.
 */
class AutoPickCounterAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $counterId) {}

    public function handle(
        CounterAssignmentService $assignmentService,
        AllianceMembershipService $membershipService,
        PWHealthService $healthService
    ): void {
        if ($healthService->isDown()) {
            $this->release(300);

            return;
        }

        /** @var WarCounter|null $counter */
        $counter = WarCounter::query()->with('aggressor')->find($this->counterId);

        if (! $counter) {
            return;
        }

        $friendlyAllianceIds = $membershipService->getAllianceIds();

        $friendlies = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->get();

        $assignmentService->proposeAssignments($counter, $friendlies);
    }
}
