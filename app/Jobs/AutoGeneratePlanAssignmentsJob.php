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

        $targetScores = $plan->targets
            ->pluck('nation.score')
            ->filter(fn ($score) => $score !== null)
            ->map(fn ($score) => (float) $score)
            ->values();

        $minFriendlyScore = null;
        $maxFriendlyScore = null;

        if ($targetScores->isNotEmpty()) {
            $lowestTargetScore = (float) $targetScores->min();
            $highestTargetScore = (float) $targetScores->max();

            // canAttack uses: target in [friendly*0.75, friendly*2.5] => friendly in [target/2.5, target/0.75]
            $minFriendlyScore = max(0.0, $lowestTargetScore / 2.5);
            $maxFriendlyScore = $highestTargetScore / 0.75;
        }

        $friendlies = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->when($minFriendlyScore !== null, fn ($query) => $query->where('score', '>=', $minFriendlyScore))
            ->when($maxFriendlyScore !== null, fn ($query) => $query->where('score', '<=', $maxFriendlyScore))
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
            ])
            ->get();

        $assignmentService->generate(
            $plan,
            $plan->targets,
            $friendlies,
            respectLocks: true,
            hydrateAssignments: false,
            enableSecondPass: false,
            rebuildSquads: false,
            includeSignInData: false
        );
    }
}
