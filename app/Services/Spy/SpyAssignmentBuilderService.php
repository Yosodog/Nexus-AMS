<?php

namespace App\Services\Spy;

use App\Enums\SpyOperationType;
use App\Models\Nation;
use App\Models\SpyAssignment;
use App\Models\SpyRound;
use App\Services\AllianceMembershipService;
use Illuminate\Support\Collection;

/**
 * Builds optimized spy assignments for a round.
 */
class SpyAssignmentBuilderService
{
    public function __construct(
        protected SpyOddsCalculatorService $oddsCalculator,
        protected AllianceMembershipService $membershipService,
    ) {}

    /**
     * Generate assignments for the given round.
     *
     * @return Collection<int, SpyAssignment>
     */
    public function build(SpyRound $round): Collection
    {
        $campaign = $round->campaign()->with('alliances')->firstOrFail();

        $minSuccess = $round->min_success_chance ?? (float) ($campaign->settings['min_success_chance'] ?? 55);
        $friendlyIds = $campaign->alliances()->where('role', 'ally')->pluck('alliance_id');
        $enemyIds = $campaign->alliances()->where('role', 'enemy')->pluck('alliance_id');

        if ($friendlyIds->isEmpty()) {
            $friendlyIds = $this->membershipService->getAllianceIds();
        }

        $friendlies = $this->eligibleNations($friendlyIds);
        $enemies = $this->eligibleNations($enemyIds);

        SpyAssignment::query()->where('spy_round_id', $round->id)->delete();

        $candidates = $this->buildCandidates($friendlies, $enemies, $round->op_type ?? SpyOperationType::GATHER_INTELLIGENCE, $minSuccess);
        $ordered = collect($candidates)->sortByDesc('priority_score');

        $attackerSlots = [];
        $defenderSlots = [];
        $assignments = collect();

        foreach ($ordered as $candidate) {
            $attackerSlots[$candidate['attacker']->id] = $attackerSlots[$candidate['attacker']->id] ?? 0;
            $defenderSlots[$candidate['defender']->id] = $defenderSlots[$candidate['defender']->id] ?? 0;

            if ($attackerSlots[$candidate['attacker']->id] >= 2) {
                continue;
            }

            if ($defenderSlots[$candidate['defender']->id] >= 3) {
                continue;
            }

            $assignment = SpyAssignment::query()->create([
                'spy_round_id' => $round->id,
                'attacker_nation_id' => $candidate['attacker']->id,
                'defender_nation_id' => $candidate['defender']->id,
                'op_type' => $round->op_type,
                'safety_level' => $candidate['safety_level'],
                'calculated_odds' => $candidate['success_chance'],
                'expected_impact' => $candidate['expected_impact'],
                'policy_synergy' => $candidate['policy_synergy'],
                'final_score_used_for_sorting' => $candidate['priority_score'],
                'low_odds_flag' => $candidate['low_odds'],
            ]);

            $assignments->push($assignment);

            $attackerSlots[$candidate['attacker']->id]++;
            $defenderSlots[$candidate['defender']->id]++;
        }

        $round->update(['status' => 'assigned']);

        return $assignments;
    }

    /**
     * @param  Collection<int, Nation>  $friendlies
     * @param  Collection<int, Nation>  $enemies
     * @return array<int, array<string, mixed>>
     */
    protected function buildCandidates(Collection $friendlies, Collection $enemies, SpyOperationType $opType, float $minSuccess): array
    {
        $candidates = [];

        foreach ($friendlies as $friendly) {
            foreach ($enemies as $enemy) {
                if (! $this->inSpyRange($friendly, $enemy)) {
                    continue;
                }

                $calc = $this->oddsCalculator->calculate($friendly, $enemy, $opType, $minSuccess);

                if ($calc['expected_impact'] <= 0) {
                    continue;
                }

                $priorityScore = $calc['success_chance'] * $calc['expected_impact'] * (1 + $calc['policy_synergy']);
                $priorityScore = min(999999.9999, max(0, $priorityScore));

                $candidates[] = [
                    'attacker' => $friendly,
                    'defender' => $enemy,
                    'success_chance' => $calc['success_chance'],
                    'expected_impact' => $calc['expected_impact'],
                    'policy_synergy' => $calc['policy_synergy'],
                    'safety_level' => $calc['safety_level'],
                    'low_odds' => $calc['low_odds'],
                    'priority_score' => round($priorityScore, 4),
                ];
            }
        }

        return $candidates;
    }

    protected function inSpyRange(Nation $attacker, Nation $defender): bool
    {
        $min = $attacker->score * 0.4;
        $max = $attacker->score * 2.5;

        return $defender->score >= $min && $defender->score <= $max;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $allianceIds
     * @return Collection<int, Nation>
     */
    protected function eligibleNations(Collection $allianceIds): Collection
    {
        if ($allianceIds->isEmpty()) {
            return collect();
        }

        return Nation::query()
            ->where('espionage_available', true)
            ->where('vacation_mode_turns', '<=', 0)
            ->where('alliance_position', '!=', 'APPLICANT')
            ->whereIn('alliance_id', $allianceIds)
            ->select([
                'id',
                'alliance_id',
                'nation_name',
                'leader_name',
                'num_cities',
                'score',
                'war_policy',
                'vacation_mode_turns',
                'alliance_position',
            ])
            ->with(['military' => fn ($query) => $query->select('id', 'nation_id', 'spies', 'soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes')])
            ->get();
    }
}
