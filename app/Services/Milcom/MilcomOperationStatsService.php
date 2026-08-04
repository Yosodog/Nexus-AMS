<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomAssignment;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\WarAttack;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class MilcomOperationStatsService
{
    private const CHART_DAYS = 14;

    private const CURRENT_WAR_LIMIT = 25;

    private const ATTENTION_LIMIT = 10;

    private const CONTRIBUTOR_LIMIT = 10;

    private const ALLIANCE_LIMIT_PER_SIDE = 25;

    private const LOW_RESISTANCE_THRESHOLD = 25;

    /** @return array<string, mixed> */
    public function forOperation(MilcomOperation $operation): array
    {
        $totalAssignments = (clone $this->assignments($operation))->count();
        $declaredAssignments = (clone $this->assignments($operation))
            ->whereNotNull('milcom_assignments.declared_war_id')
            ->count();
        $warSummary = $this->warSummary($operation);
        $attackSummary = $this->attackSummary($operation);
        $currentWarRows = $this->currentWarRows($operation);
        $contributors = $this->contributorRows($operation);
        $allianceRows = $this->allianceRows($operation);
        $nationDetails = $this->nationDetails($currentWarRows, $contributors);
        $allianceDetails = $this->allianceDetails($currentWarRows, $allianceRows, $nationDetails);
        $currentWars = $this->formatCurrentWars($currentWarRows, $nationDetails, $allianceDetails);
        $waitingToDeclare = $this->waitingToDeclare($operation);
        $noFirstHit = $this->noFirstHitCount($operation);
        $lowResistance = (int) ($warSummary->low_resistance_wars ?? 0);
        $outgoingAttacks = (int) ($attackSummary->outgoing_attacks ?? 0);
        $successfulAttacks = (int) ($attackSummary->successful_outgoing_attacks ?? 0);
        $infraInflictedValue = (float) ($warSummary->infra_inflicted_value ?? 0);
        $infraTakenValue = (float) ($warSummary->infra_taken_value ?? 0);
        $forces = $this->forceComparison($operation);

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'assignments' => $totalAssignments,
                'declared_assignments' => $declaredAssignments,
                'declaration_rate' => $this->percent($declaredAssignments, $totalAssignments),
                'active_wars' => (int) ($warSummary->active_wars ?? 0),
                'finished_wars' => (int) ($warSummary->finished_wars ?? 0),
                'wins' => (int) ($warSummary->wins ?? 0),
                'losses' => (int) ($warSummary->losses ?? 0),
                'no_result' => (int) ($warSummary->no_result ?? 0),
                'outgoing_attacks' => $outgoingAttacks,
                'successful_outgoing_attacks' => $successfulAttacks,
                'attack_success_rate' => $this->percent($successfulAttacks, $outgoingAttacks),
                'infra_inflicted_value' => $infraInflictedValue,
                'infra_taken_value' => $infraTakenValue,
                'net_infra_value' => $infraInflictedValue - $infraTakenValue,
                'loot' => (float) ($warSummary->loot ?? 0),
            ],
            'damage' => [
                'infra_inflicted' => (float) ($warSummary->infra_inflicted ?? 0),
                'infra_taken' => (float) ($warSummary->infra_taken ?? 0),
                'units_inflicted' => [
                    'soldiers' => (int) ($warSummary->enemy_soldiers_lost ?? 0),
                    'tanks' => (int) ($warSummary->enemy_tanks_lost ?? 0),
                    'aircraft' => (int) ($warSummary->enemy_aircraft_lost ?? 0),
                    'ships' => (int) ($warSummary->enemy_ships_lost ?? 0),
                ],
                'units_taken' => [
                    'soldiers' => (int) ($warSummary->friendly_soldiers_lost ?? 0),
                    'tanks' => (int) ($warSummary->friendly_tanks_lost ?? 0),
                    'aircraft' => (int) ($warSummary->friendly_aircraft_lost ?? 0),
                    'ships' => (int) ($warSummary->friendly_ships_lost ?? 0),
                ],
                'missiles_used' => (int) ($warSummary->friendly_missiles_used ?? 0),
                'nukes_used' => (int) ($warSummary->friendly_nukes_used ?? 0),
            ],
            'forces' => $forces,
            'side_results' => $this->sideResults($warSummary, $attackSummary),
            'attention' => [
                'waiting_to_declare' => $waitingToDeclare['count'],
                'waiting_to_declare_rows' => $waitingToDeclare['rows'],
                'no_first_hit' => $noFirstHit,
                'low_resistance' => $lowResistance,
            ],
            'charts' => $this->chartSeries($operation),
            'current_wars' => $currentWars,
            'current_wars_total' => (int) ($warSummary->active_wars ?? 0),
            'alliances' => $this->formatAllianceRows($allianceRows, $allianceDetails),
            'contributors' => $this->formatContributors($contributors, $nationDetails, $allianceDetails),
        ];
    }

    /** @return Builder<MilcomAssignment> */
    private function assignments(MilcomOperation $operation): Builder
    {
        return MilcomAssignment::query()
            ->join('milcom_objectives', 'milcom_objectives.id', '=', 'milcom_assignments.objective_id')
            ->where('milcom_objectives.operation_id', $operation->id)
            ->whereNotIn('milcom_assignments.status', [
                AssignmentStatus::Released->value,
                AssignmentStatus::Failed->value,
            ]);
    }

    private function linkedWars(MilcomOperation $operation): Builder
    {
        return (clone $this->assignments($operation))
            ->join('wars', 'wars.id', '=', 'milcom_assignments.declared_war_id')
            ->whereNotNull('milcom_assignments.declared_war_id');
    }

    private function warSummary(MilcomOperation $operation): object
    {
        return (clone $this->linkedWars($operation))
            ->selectRaw('COUNT(DISTINCT wars.id) as declared_wars')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NULL AND wars.turns_left > 0 THEN 1 ELSE 0 END) as active_wars')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NOT NULL OR wars.turns_left = 0 THEN 1 ELSE 0 END) as finished_wars')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_objectives.target_nation_id THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM(CASE WHEN (wars.end_date IS NOT NULL OR wars.turns_left = 0) AND (wars.winner_id IS NULL OR wars.winner_id NOT IN (milcom_assignments.friendly_nation_id, milcom_objectives.target_nation_id)) THEN 1 ELSE 0 END) as no_result')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NULL AND wars.turns_left > 0 AND wars.att_resistance <= ? THEN 1 ELSE 0 END) as low_resistance_wars', [self::LOW_RESISTANCE_THRESHOLD])
            ->selectRaw('COALESCE(SUM(wars.att_infra_destroyed_value), 0) as infra_inflicted_value')
            ->selectRaw('COALESCE(SUM(wars.def_infra_destroyed_value), 0) as infra_taken_value')
            ->selectRaw('COALESCE(SUM(wars.att_infra_destroyed), 0) as infra_inflicted')
            ->selectRaw('COALESCE(SUM(wars.def_infra_destroyed), 0) as infra_taken')
            ->selectRaw('COALESCE(SUM(wars.att_money_looted), 0) as loot')
            ->selectRaw('COALESCE(SUM(wars.def_money_looted), 0) as enemy_loot')
            ->selectRaw('COALESCE(SUM(wars.def_soldiers_lost), 0) as enemy_soldiers_lost')
            ->selectRaw('COALESCE(SUM(wars.att_soldiers_lost), 0) as friendly_soldiers_lost')
            ->selectRaw('COALESCE(SUM(wars.def_tanks_lost), 0) as enemy_tanks_lost')
            ->selectRaw('COALESCE(SUM(wars.att_tanks_lost), 0) as friendly_tanks_lost')
            ->selectRaw('COALESCE(SUM(wars.def_aircraft_lost), 0) as enemy_aircraft_lost')
            ->selectRaw('COALESCE(SUM(wars.att_aircraft_lost), 0) as friendly_aircraft_lost')
            ->selectRaw('COALESCE(SUM(wars.def_ships_lost), 0) as enemy_ships_lost')
            ->selectRaw('COALESCE(SUM(wars.att_ships_lost), 0) as friendly_ships_lost')
            ->selectRaw('COALESCE(SUM(wars.att_missiles_used), 0) as friendly_missiles_used')
            ->selectRaw('COALESCE(SUM(wars.att_nukes_used), 0) as friendly_nukes_used')
            ->selectRaw('COALESCE(SUM(wars.def_missiles_used), 0) as enemy_missiles_used')
            ->selectRaw('COALESCE(SUM(wars.def_nukes_used), 0) as enemy_nukes_used')
            ->toBase()
            ->first() ?? (object) [];
    }

    private function attackSummary(MilcomOperation $operation): object
    {
        return WarAttack::query()
            ->join('milcom_assignments', 'milcom_assignments.declared_war_id', '=', 'war_attacks.war_id')
            ->join('milcom_objectives', 'milcom_objectives.id', '=', 'milcom_assignments.objective_id')
            ->where('milcom_objectives.operation_id', $operation->id)
            ->whereNotIn('milcom_assignments.status', [
                AssignmentStatus::Released->value,
                AssignmentStatus::Failed->value,
            ])
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as outgoing_attacks')
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id = milcom_assignments.friendly_nation_id AND war_attacks.success > 0 THEN 1 ELSE 0 END) as successful_outgoing_attacks')
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id <> milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as incoming_attacks')
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id <> milcom_assignments.friendly_nation_id AND war_attacks.success > 0 THEN 1 ELSE 0 END) as successful_incoming_attacks')
            ->toBase()
            ->first() ?? (object) [];
    }

    /** @return array<string, mixed> */
    private function forceComparison(MilcomOperation $operation): array
    {
        $recommendationRunId = MilcomRecommendationRun::query()
            ->where('operation_id', $operation->id)
            ->whereNull('objective_id')
            ->where('status', RecommendationRunStatus::Succeeded->value)
            ->latest('id')
            ->value('id');
        $friendly = $this->forceTotals($operation, 'friendly', $recommendationRunId);
        $enemy = $this->forceTotals($operation, 'target', $recommendationRunId);

        return [
            'source' => $recommendationRunId !== null ? 'latest_generation' : 'wave',
            'as_of' => collect([$friendly['updated_at'], $enemy['updated_at']])
                ->filter()
                ->sortDesc()
                ->first(),
            'friendly' => $friendly,
            'enemy' => $enemy,
        ];
    }

    /** @return array<string, int|float|string|null> */
    private function forceTotals(
        MilcomOperation $operation,
        string $role,
        ?int $recommendationRunId,
    ): array {
        $query = Nation::query()
            ->leftJoin('nation_military', function (JoinClause $join): void {
                $join->on('nation_military.nation_id', '=', 'nations.id')
                    ->whereNull('nation_military.deleted_at');
            });

        if ($recommendationRunId !== null) {
            $query->whereExists(function (QueryBuilder $snapshotQuery) use ($recommendationRunId, $role): void {
                $snapshotQuery
                    ->selectRaw('1')
                    ->from('milcom_readiness_snapshots')
                    ->whereColumn('milcom_readiness_snapshots.nation_id', 'nations.id')
                    ->where('milcom_readiness_snapshots.recommendation_run_id', $recommendationRunId)
                    ->where('milcom_readiness_snapshots.role', $role);
            });
        } elseif ($role === 'friendly') {
            $query->whereExists(function (QueryBuilder $assignmentQuery) use ($operation): void {
                $assignmentQuery
                    ->selectRaw('1')
                    ->from('milcom_assignments')
                    ->join('milcom_objectives', 'milcom_objectives.id', '=', 'milcom_assignments.objective_id')
                    ->whereColumn('milcom_assignments.friendly_nation_id', 'nations.id')
                    ->where('milcom_objectives.operation_id', $operation->id)
                    ->whereNotIn('milcom_assignments.status', [
                        AssignmentStatus::Released->value,
                        AssignmentStatus::Failed->value,
                    ]);
            });
        } else {
            $query->whereExists(function (QueryBuilder $objectiveQuery) use ($operation): void {
                $objectiveQuery
                    ->selectRaw('1')
                    ->from('milcom_objectives')
                    ->whereColumn('milcom_objectives.target_nation_id', 'nations.id')
                    ->where('milcom_objectives.operation_id', $operation->id);
            });
        }

        $totals = $query
            ->selectRaw('COUNT(DISTINCT nations.id) as nations')
            ->selectRaw('COUNT(DISTINCT nation_military.nation_id) as military_reports')
            ->selectRaw('COALESCE(SUM(nations.num_cities), 0) as cities')
            ->selectRaw('COALESCE(SUM(CASE WHEN nation_military.nation_id IS NOT NULL THEN nations.num_cities ELSE 0 END), 0) as reported_cities')
            ->selectRaw('COALESCE(SUM(nations.score), 0) as score')
            ->selectRaw('COALESCE(SUM(nations.offensive_wars_count), 0) as offensive_wars')
            ->selectRaw('COALESCE(SUM(nations.defensive_wars_count), 0) as defensive_wars')
            ->selectRaw('COALESCE(SUM(nation_military.soldiers), 0) as soldiers')
            ->selectRaw('COALESCE(SUM(nation_military.tanks), 0) as tanks')
            ->selectRaw('COALESCE(SUM(nation_military.aircraft), 0) as aircraft')
            ->selectRaw('COALESCE(SUM(nation_military.ships), 0) as ships')
            ->selectRaw('COALESCE(SUM(nation_military.missiles), 0) as missiles')
            ->selectRaw('COALESCE(SUM(nation_military.nukes), 0) as nukes')
            ->selectRaw('COALESCE(SUM(nation_military.spies), 0) as spies')
            ->selectRaw('MAX(nations.updated_at) as updated_at')
            ->toBase()
            ->first() ?? (object) [];

        $nationCount = (int) ($totals->nations ?? 0);
        $cities = (int) ($totals->cities ?? 0);
        $reportedCities = (int) ($totals->reported_cities ?? 0);

        return [
            'nations' => $nationCount,
            'military_reports' => (int) ($totals->military_reports ?? 0),
            'cities' => $cities,
            'average_cities' => $this->average($cities, $nationCount),
            'score' => round((float) ($totals->score ?? 0), 2),
            'average_score' => $this->average((float) ($totals->score ?? 0), $nationCount),
            'soldiers' => (int) ($totals->soldiers ?? 0),
            'soldiers_per_city' => $this->average((int) ($totals->soldiers ?? 0), $reportedCities),
            'tanks' => (int) ($totals->tanks ?? 0),
            'tanks_per_city' => $this->average((int) ($totals->tanks ?? 0), $reportedCities),
            'aircraft' => (int) ($totals->aircraft ?? 0),
            'aircraft_per_city' => $this->average((int) ($totals->aircraft ?? 0), $reportedCities),
            'ships' => (int) ($totals->ships ?? 0),
            'ships_per_city' => $this->average((int) ($totals->ships ?? 0), $reportedCities),
            'missiles' => (int) ($totals->missiles ?? 0),
            'nukes' => (int) ($totals->nukes ?? 0),
            'spies' => (int) ($totals->spies ?? 0),
            'offensive_wars' => (int) ($totals->offensive_wars ?? 0),
            'defensive_wars' => (int) ($totals->defensive_wars ?? 0),
            'updated_at' => $totals->updated_at ?? null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function sideResults(object $warSummary, object $attackSummary): array
    {
        $outgoingAttacks = (int) ($attackSummary->outgoing_attacks ?? 0);
        $successfulOutgoingAttacks = (int) ($attackSummary->successful_outgoing_attacks ?? 0);
        $incomingAttacks = (int) ($attackSummary->incoming_attacks ?? 0);
        $successfulIncomingAttacks = (int) ($attackSummary->successful_incoming_attacks ?? 0);

        return [
            'friendly' => [
                'wars_won' => (int) ($warSummary->wins ?? 0),
                'attacks' => $outgoingAttacks,
                'successful_attacks' => $successfulOutgoingAttacks,
                'attack_success_rate' => $this->percent($successfulOutgoingAttacks, $outgoingAttacks),
                'infra_destroyed' => (float) ($warSummary->infra_inflicted ?? 0),
                'infra_destroyed_value' => (float) ($warSummary->infra_inflicted_value ?? 0),
                'loot' => (float) ($warSummary->loot ?? 0),
                'soldiers_destroyed' => (int) ($warSummary->enemy_soldiers_lost ?? 0),
                'tanks_destroyed' => (int) ($warSummary->enemy_tanks_lost ?? 0),
                'aircraft_destroyed' => (int) ($warSummary->enemy_aircraft_lost ?? 0),
                'ships_destroyed' => (int) ($warSummary->enemy_ships_lost ?? 0),
                'missiles_used' => (int) ($warSummary->friendly_missiles_used ?? 0),
                'nukes_used' => (int) ($warSummary->friendly_nukes_used ?? 0),
            ],
            'enemy' => [
                'wars_won' => (int) ($warSummary->losses ?? 0),
                'attacks' => $incomingAttacks,
                'successful_attacks' => $successfulIncomingAttacks,
                'attack_success_rate' => $this->percent($successfulIncomingAttacks, $incomingAttacks),
                'infra_destroyed' => (float) ($warSummary->infra_taken ?? 0),
                'infra_destroyed_value' => (float) ($warSummary->infra_taken_value ?? 0),
                'loot' => (float) ($warSummary->enemy_loot ?? 0),
                'soldiers_destroyed' => (int) ($warSummary->friendly_soldiers_lost ?? 0),
                'tanks_destroyed' => (int) ($warSummary->friendly_tanks_lost ?? 0),
                'aircraft_destroyed' => (int) ($warSummary->friendly_aircraft_lost ?? 0),
                'ships_destroyed' => (int) ($warSummary->friendly_ships_lost ?? 0),
                'missiles_used' => (int) ($warSummary->enemy_missiles_used ?? 0),
                'nukes_used' => (int) ($warSummary->enemy_nukes_used ?? 0),
            ],
        ];
    }

    private function average(int|float $total, int $count): float
    {
        return $count > 0 ? round($total / $count, 1) : 0.0;
    }

    private function currentWarRows(MilcomOperation $operation): Collection
    {
        return (clone $this->linkedWars($operation))
            ->whereNull('wars.end_date')
            ->where('wars.turns_left', '>', 0)
            ->orderByRaw('CASE WHEN wars.att_resistance <= ? THEN 0 ELSE 1 END', [self::LOW_RESISTANCE_THRESHOLD])
            ->orderBy('wars.turns_left')
            ->orderBy('wars.id')
            ->limit(self::CURRENT_WAR_LIMIT)
            ->get([
                'wars.id as war_id',
                'wars.date as declared_at',
                'wars.turns_left',
                'wars.att_resistance as friendly_resistance',
                'wars.def_resistance as target_resistance',
                'wars.att_infra_destroyed_value as infra_inflicted_value',
                'wars.def_infra_destroyed_value as infra_taken_value',
                'wars.att_money_looted as loot',
                'wars.att_alliance_id as friendly_alliance_id',
                'wars.def_alliance_id as target_alliance_id',
                'milcom_assignments.friendly_nation_id',
                'milcom_objectives.target_nation_id',
            ]);
    }

    /** @return array{count: int, rows: array<int, array<string, mixed>>} */
    private function waitingToDeclare(MilcomOperation $operation): array
    {
        $query = (clone $this->assignments($operation))
            ->whereNull('milcom_assignments.declared_war_id')
            ->whereIn('milcom_assignments.status', [
                AssignmentStatus::Approved->value,
                AssignmentStatus::Dispatched->value,
            ]);
        $count = (clone $query)->count();
        $rows = $query
            ->join('nations as friendly', 'friendly.id', '=', 'milcom_assignments.friendly_nation_id')
            ->join('nations as target', 'target.id', '=', 'milcom_objectives.target_nation_id')
            ->orderByRaw('milcom_objectives.deadline_at IS NULL')
            ->orderBy('milcom_objectives.deadline_at')
            ->orderBy('milcom_assignments.id')
            ->limit(self::ATTENTION_LIMIT)
            ->get([
                'milcom_assignments.id as assignment_id',
                'milcom_assignments.friendly_nation_id',
                'friendly.nation_name as friendly_nation_name',
                'friendly.leader_name as friendly_leader_name',
                'milcom_objectives.target_nation_id',
                'target.nation_name as target_nation_name',
                'target.leader_name as target_leader_name',
                'milcom_objectives.deadline_at',
            ])
            ->map(fn (MilcomAssignment $row): array => [
                'assignment_id' => (int) $row->assignment_id,
                'friendly' => [
                    'id' => (int) $row->friendly_nation_id,
                    'nation_name' => (string) $row->friendly_nation_name,
                    'leader_name' => (string) $row->friendly_leader_name,
                ],
                'target' => [
                    'id' => (int) $row->target_nation_id,
                    'nation_name' => (string) $row->target_nation_name,
                    'leader_name' => (string) $row->target_leader_name,
                ],
                'deadline_at' => $row->deadline_at,
            ])
            ->all();

        return ['count' => $count, 'rows' => $rows];
    }

    private function noFirstHitCount(MilcomOperation $operation): int
    {
        return (clone $this->linkedWars($operation))
            ->whereNull('wars.end_date')
            ->where('wars.turns_left', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('war_attacks')
                    ->whereColumn('war_attacks.war_id', 'wars.id')
                    ->whereColumn('war_attacks.att_id', 'milcom_assignments.friendly_nation_id');
            })
            ->count();
    }

    private function contributorRows(MilcomOperation $operation): Collection
    {
        return (clone $this->linkedWars($operation))
            ->select('milcom_assignments.friendly_nation_id')
            ->selectRaw('COUNT(DISTINCT wars.id) as war_count')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NULL AND wars.turns_left > 0 THEN 1 ELSE 0 END) as active_wars')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as wins')
            ->selectRaw('COALESCE(SUM(wars.att_infra_destroyed_value), 0) as infra_inflicted_value')
            ->selectRaw('COALESCE(SUM(wars.att_money_looted), 0) as loot')
            ->groupBy('milcom_assignments.friendly_nation_id')
            ->orderByDesc('infra_inflicted_value')
            ->orderBy('milcom_assignments.friendly_nation_id')
            ->limit(self::CONTRIBUTOR_LIMIT)
            ->get();
    }

    private function allianceRows(MilcomOperation $operation): Collection
    {
        $friendly = (clone $this->linkedWars($operation))
            ->select('wars.att_alliance_id as alliance_id')
            ->selectRaw("'friendly' as side")
            ->selectRaw('COUNT(DISTINCT wars.id) as war_count')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NULL AND wars.turns_left > 0 THEN 1 ELSE 0 END) as active_wars')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_objectives.target_nation_id THEN 1 ELSE 0 END) as losses')
            ->selectRaw('COALESCE(SUM(wars.att_infra_destroyed_value), 0) as infra_inflicted_value')
            ->selectRaw('COALESCE(SUM(wars.def_infra_destroyed_value), 0) as infra_taken_value')
            ->selectRaw('COALESCE(SUM(wars.att_money_looted), 0) as loot')
            ->groupBy('wars.att_alliance_id')
            ->orderByDesc('war_count')
            ->orderBy('wars.att_alliance_id')
            ->limit(self::ALLIANCE_LIMIT_PER_SIDE)
            ->get();

        $enemy = (clone $this->linkedWars($operation))
            ->select('wars.def_alliance_id as alliance_id')
            ->selectRaw("'enemy' as side")
            ->selectRaw('COUNT(DISTINCT wars.id) as war_count')
            ->selectRaw('SUM(CASE WHEN wars.end_date IS NULL AND wars.turns_left > 0 THEN 1 ELSE 0 END) as active_wars')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_objectives.target_nation_id THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN wars.winner_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as losses')
            ->selectRaw('COALESCE(SUM(wars.def_infra_destroyed_value), 0) as infra_inflicted_value')
            ->selectRaw('COALESCE(SUM(wars.att_infra_destroyed_value), 0) as infra_taken_value')
            ->selectRaw('COALESCE(SUM(wars.def_money_looted), 0) as loot')
            ->groupBy('wars.def_alliance_id')
            ->orderByDesc('war_count')
            ->orderBy('wars.def_alliance_id')
            ->limit(self::ALLIANCE_LIMIT_PER_SIDE)
            ->get();

        return $friendly->concat($enemy);
    }

    private function nationDetails(Collection $currentWarRows, Collection $contributors): Collection
    {
        $nationIds = $currentWarRows
            ->flatMap(fn (MilcomAssignment $row): array => [
                (int) $row->friendly_nation_id,
                (int) $row->target_nation_id,
            ])
            ->merge($contributors->pluck('friendly_nation_id')->map(fn ($id): int => (int) $id))
            ->filter()
            ->unique()
            ->values();

        if ($nationIds->isEmpty()) {
            return collect();
        }

        return Nation::query()
            ->whereIn('id', $nationIds)
            ->get(['id', 'nation_name', 'leader_name', 'alliance_id'])
            ->keyBy('id');
    }

    private function allianceDetails(Collection $currentWarRows, Collection $allianceRows, Collection $nationDetails): Collection
    {
        $allianceIds = $currentWarRows
            ->flatMap(fn (MilcomAssignment $row): array => [
                (int) $row->friendly_alliance_id,
                (int) $row->target_alliance_id,
            ])
            ->merge($allianceRows->pluck('alliance_id')->map(fn ($id): int => (int) $id))
            ->merge($nationDetails->pluck('alliance_id')->map(fn ($id): int => (int) $id))
            ->filter()
            ->unique()
            ->values();

        if ($allianceIds->isEmpty()) {
            return collect();
        }

        return Alliance::query()
            ->whereIn('id', $allianceIds)
            ->get(['id', 'name', 'acronym', 'flag'])
            ->keyBy('id');
    }

    private function formatCurrentWars(Collection $rows, Collection $nations, Collection $alliances): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $attackRows = WarAttack::query()
            ->whereIn('war_id', $rows->pluck('war_id'))
            ->select(['war_id', 'att_id'])
            ->selectRaw('COUNT(*) as attacks')
            ->selectRaw('MAX(date) as last_attack_at')
            ->groupBy('war_id', 'att_id')
            ->get()
            ->groupBy('war_id');

        return $rows->map(function (MilcomAssignment $row) use ($attackRows, $nations, $alliances): array {
            $friendly = $nations->get((int) $row->friendly_nation_id);
            $target = $nations->get((int) $row->target_nation_id);
            $warAttacks = $attackRows->get((int) $row->war_id, collect());
            $outgoing = $warAttacks->firstWhere('att_id', (int) $row->friendly_nation_id);
            $outgoingCount = (int) data_get($outgoing, 'attacks', 0);
            $incomingCount = (int) $warAttacks->reject(
                fn (WarAttack $attack): bool => (int) $attack->att_id === (int) $row->friendly_nation_id
            )->sum('attacks');
            $lastAttackAt = $warAttacks->max('last_attack_at');
            $alert = $outgoingCount === 0
                ? 'No first hit recorded'
                : ((int) $row->friendly_resistance <= self::LOW_RESISTANCE_THRESHOLD ? 'Friendly resistance is low' : null);

            return [
                'war_id' => (int) $row->war_id,
                'war_url' => 'https://politicsandwar.com/nation/war/timeline/war='.(int) $row->war_id,
                'friendly' => $this->nationPayload($friendly, $alliances),
                'target' => $this->nationPayload($target, $alliances),
                'turns_left' => (int) $row->turns_left,
                'friendly_resistance' => (int) $row->friendly_resistance,
                'target_resistance' => (int) $row->target_resistance,
                'infra_inflicted_value' => (float) $row->infra_inflicted_value,
                'infra_taken_value' => (float) $row->infra_taken_value,
                'loot' => (float) $row->loot,
                'outgoing_attacks' => $outgoingCount,
                'incoming_attacks' => $incomingCount,
                'last_attack_at' => $lastAttackAt,
                'alert' => $alert,
            ];
        })->all();
    }

    private function formatAllianceRows(Collection $rows, Collection $alliances): array
    {
        return $rows
            ->sortBy([
                ['side', 'asc'],
                ['infra_inflicted_value', 'desc'],
            ])
            ->map(function (MilcomAssignment $row) use ($alliances): array {
                $alliance = $alliances->get((int) $row->alliance_id);

                return [
                    'side' => (string) $row->side,
                    'alliance' => $this->alliancePayload($alliance, (int) $row->alliance_id),
                    'wars' => (int) $row->war_count,
                    'active_wars' => (int) $row->active_wars,
                    'wins' => (int) $row->wins,
                    'losses' => (int) $row->losses,
                    'infra_inflicted_value' => (float) $row->infra_inflicted_value,
                    'infra_taken_value' => (float) $row->infra_taken_value,
                    'net_infra_value' => (float) $row->infra_inflicted_value - (float) $row->infra_taken_value,
                    'loot' => (float) $row->loot,
                ];
            })
            ->values()
            ->all();
    }

    private function formatContributors(Collection $rows, Collection $nations, Collection $alliances): array
    {
        return $rows->map(function (MilcomAssignment $row) use ($nations, $alliances): array {
            return [
                'nation' => $this->nationPayload($nations->get((int) $row->friendly_nation_id), $alliances),
                'wars' => (int) $row->war_count,
                'active_wars' => (int) $row->active_wars,
                'wins' => (int) $row->wins,
                'infra_inflicted_value' => (float) $row->infra_inflicted_value,
                'loot' => (float) $row->loot,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function chartSeries(MilcomOperation $operation): array
    {
        $end = Carbon::parse($operation->completed_at ?? $operation->archived_at ?? now())->endOfDay();
        $start = $end->copy()->subDays(self::CHART_DAYS - 1)->startOfDay();
        $dailyRows = WarAttack::query()
            ->join('milcom_assignments', 'milcom_assignments.declared_war_id', '=', 'war_attacks.war_id')
            ->join('milcom_objectives', 'milcom_objectives.id', '=', 'milcom_assignments.objective_id')
            ->where('milcom_objectives.operation_id', $operation->id)
            ->whereNotIn('milcom_assignments.status', [
                AssignmentStatus::Released->value,
                AssignmentStatus::Failed->value,
            ])
            ->whereBetween('war_attacks.date', [$start, $end])
            ->selectRaw('DATE(war_attacks.date) as attack_day')
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id = milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as outgoing_attacks')
            ->selectRaw('SUM(CASE WHEN war_attacks.att_id <> milcom_assignments.friendly_nation_id THEN 1 ELSE 0 END) as incoming_attacks')
            ->selectRaw('COALESCE(SUM(CASE WHEN war_attacks.att_id = milcom_assignments.friendly_nation_id THEN war_attacks.infra_destroyed_value ELSE 0 END), 0) as infra_inflicted_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN war_attacks.att_id <> milcom_assignments.friendly_nation_id THEN war_attacks.infra_destroyed_value ELSE 0 END), 0) as infra_taken_value')
            ->groupByRaw('DATE(war_attacks.date)')
            ->orderByRaw('DATE(war_attacks.date)')
            ->get()
            ->keyBy('attack_day');

        $labels = [];
        $outgoing = [];
        $incoming = [];
        $infraInflicted = [];
        $infraTaken = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $row = $dailyRows->get($key);
            $labels[] = $day->format('M j');
            $outgoing[] = (int) data_get($row, 'outgoing_attacks', 0);
            $incoming[] = (int) data_get($row, 'incoming_attacks', 0);
            $infraInflicted[] = (float) data_get($row, 'infra_inflicted_value', 0);
            $infraTaken[] = (float) data_get($row, 'infra_taken_value', 0);
        }

        return [
            'labels' => $labels,
            'outgoing_attacks' => $outgoing,
            'incoming_attacks' => $incoming,
            'infra_inflicted_value' => $infraInflicted,
            'infra_taken_value' => $infraTaken,
        ];
    }

    /** @return array<string, mixed> */
    private function nationPayload(?Nation $nation, Collection $alliances): array
    {
        if ($nation === null) {
            return [
                'id' => null,
                'nation_name' => 'Unknown nation',
                'leader_name' => 'Unknown leader',
                'alliance' => $this->alliancePayload(null, null),
            ];
        }

        return [
            'id' => (int) $nation->id,
            'nation_name' => (string) $nation->nation_name,
            'leader_name' => (string) $nation->leader_name,
            'alliance' => $this->alliancePayload($alliances->get((int) $nation->alliance_id), (int) $nation->alliance_id),
        ];
    }

    /** @return array<string, mixed> */
    private function alliancePayload(?Alliance $alliance, ?int $allianceId): array
    {
        return [
            'id' => $allianceId ?: null,
            'name' => $alliance?->name ?? ($allianceId ? 'Alliance #'.$allianceId : 'No alliance'),
            'acronym' => $alliance?->acronym,
            'flag' => $alliance?->flag,
            'url' => $allianceId ? 'https://politicsandwar.com/alliance/id='.$allianceId : null,
        ];
    }

    private function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
