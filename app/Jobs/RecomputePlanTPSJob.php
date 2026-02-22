<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Models\WarPlan;
use App\Services\PWHealthService;
use App\Services\War\PlanOrchestratorService;
use App\Services\War\TargetPriorityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes Target Priority Scores for a given war plan.
 *
 * The job aborts early if the PW API is marked down to avoid wasted effort on stale data.
 */
class RecomputePlanTPSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  int  $planId  ID of the war plan to recompute.
     */
    public function __construct(public readonly int $planId) {}

    /**
     * Execute the job.
     */
    public function handle(
        TargetPriorityService $targetPriorityService,
        PlanOrchestratorService $orchestrator,
        PWHealthService $healthService
    ): void {
        if ($healthService->isDown()) {
            $this->release(300);

            return;
        }

        /** @var WarPlan|null $plan */
        $plan = WarPlan::query()
            ->with(['enemyAlliances', 'friendlyAlliances'])
            ->find($this->planId);

        if (! $plan) {
            return;
        }

        $enemyAllianceIds = $plan->enemyAlliances->pluck('alliance_id');

        if ($enemyAllianceIds->isEmpty()) {
            Log::info('No enemy alliances configured; skipping TPS recompute', [
                'plan_id' => $plan->id,
            ]);

            return;
        }

        $enemies = Nation::query()
            ->whereIn('alliance_id', $enemyAllianceIds)
            ->get();

        $friendlyAllianceIds = $plan->friendlyAlliances->pluck('alliance_id');

        $friendlies = Nation::query()
            ->when($friendlyAllianceIds->isNotEmpty(), function ($query) use ($friendlyAllianceIds) {
                $query->whereIn('alliance_id', $friendlyAllianceIds);
            })
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->get();

        $targets = $targetPriorityService->computeAndStore($plan, $enemies, $friendlies);

        // Clean up entries for nations that have left the tracked enemy alliances.
        $plan->targets()
            ->whereNotIn('nation_id', $enemies->pluck('id'))
            ->delete();

        $orchestrator->refreshSuppressionCache();
    }
}
