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
            ->with([
                'targets' => fn ($query) => $query->select('id', 'war_plan_id', 'nation_id', 'target_priority_score', 'preferred_war_type', 'meta', 'computed_at'),
                'targets.nation' => fn ($query) => $query->select('id', 'alliance_id', 'nation_name', 'leader_name', 'score', 'num_cities', 'color', 'offensive_wars_count', 'defensive_wars_count', 'project_bits'),
                'targets.nation.military' => fn ($query) => $query->select('id', 'nation_id', 'soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'),
                'friendlyAlliances',
                'enemyAlliances',
            ])
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
            ->select([
                'id',
                'alliance_id',
                'alliance_position',
                'leader_name',
                'nation_name',
                'num_cities',
                'score',
                'color',
                'project_bits',
                'offensive_wars_count',
                'defensive_wars_count',
            ])
            ->with([
                'military' => fn ($query) => $query->select('id', 'nation_id', 'soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'),
                'latestSignIn' => fn ($query) => $query->select('nation_sign_ins.id', 'nation_sign_ins.nation_id', 'nation_sign_ins.created_at', 'nation_sign_ins.mmr_score'),
            ])
            ->get();

        $assignmentService->generate($plan, $plan->targets, $friendlies);
    }
}
