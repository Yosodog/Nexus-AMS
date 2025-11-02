<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Models\WarPlan;
use App\Services\AllianceMembershipService;
use App\Services\PWHealthService;
use App\Services\War\PlanAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates auto assignments for a war plan using the current TPS dataset.
 */
class AutoGeneratePlanAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $planId) {}

    public function handle(
        PlanAssignmentService $assignmentService,
        AllianceMembershipService $membershipService,
        PWHealthService $healthService
    ): void {
        if ($healthService->isDown()) {
            $this->release(300);

            return;
        }

        /** @var WarPlan|null $plan */
        $plan = WarPlan::query()
            ->with(['targets.nation', 'friendlyAlliances', 'enemyAlliances'])
            ->find($this->planId);

        if (! $plan) {
            return;
        }

        if ($plan->targets->isEmpty()) {
            Log::info('Plan missing targets; recomputing TPS first', ['plan_id' => $plan->id]);

            RecomputePlanTPSJob::dispatch($plan->id);

            return;
        }

        $friendlyAllianceIds = $plan->friendlyAlliances->pluck('alliance_id');

        if ($friendlyAllianceIds->isEmpty()) {
            // Fallback to overall membership if friendly alliances not explicitly configured.
            $friendlyAllianceIds = $membershipService->getAllianceIds();
        }

        $friendlies = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->get();

        $assignmentService->generate($plan, $plan->targets, $friendlies);
    }
}
