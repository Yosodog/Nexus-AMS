<?php

namespace App\Services\War;

use App\Models\Account;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarCounterReimbursement;
use App\Services\TradePriceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class CounterReimbursementService
{
    private const SOLDIER_BASE_COST = 5.0;

    private const TANK_BASE_COST = 60.0;

    private const TANK_STEEL_FACTOR = 0.5;

    private const AIRCRAFT_BASE_COST = 4000.0;

    private const AIRCRAFT_ALUMINUM_FACTOR = 10.0;

    private const SHIP_BASE_COST = 50000.0;

    private const SHIP_STEEL_FACTOR = 30.0;

    public function __construct(private readonly TradePriceService $tradePriceService) {}

    /**
     * Build cost snapshots for wars and member reimbursements.
     *
     * @param  Collection<int, int|string>  $friendlyAllianceIds
     * @return array<string, mixed>
     */
    public function buildCostingSnapshot(WarCounter $counter, Collection $friendlyAllianceIds): array
    {
        $counter->loadMissing([
            'assignments.friendlyNation',
        ]);

        $allianceIds = $friendlyAllianceIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $windowStart = $this->counterWindowStart($counter);
        $market = $this->resolveMarketPricing();
        $prices = $market['prices'];
        $unitPrices = $this->resolveUnitPrices($prices);
        $wars = $this->counterWars($counter, $allianceIds, $windowStart);
        $warRows = $wars
            ->map(fn (War $war) => $this->buildWarRow($war, $counter->aggressor_nation_id, $prices, $unitPrices))
            ->filter()
            ->values();

        $participantMap = [];

        foreach ($warRows as $row) {
            $nationId = (int) ($row['friendly_nation_id'] ?? 0);
            if ($nationId <= 0) {
                continue;
            }

            if (! isset($participantMap[$nationId])) {
                $participantMap[$nationId] = [
                    'nation_id' => $nationId,
                    'nation' => $row['friendly_nation'],
                    'resource_usage' => [
                        'gasoline' => 0.0,
                        'munitions' => 0.0,
                        'steel' => 0.0,
                        'aluminum' => 0.0,
                    ],
                    'resources_cost' => 0.0,
                    'unit_loss_cost' => 0.0,
                    'infra_loss_cost' => 0.0,
                    'total_cost' => 0.0,
                    'war_count' => 0,
                    'active_war_count' => 0,
                ];
            }

            $participantMap[$nationId]['resource_usage']['gasoline'] += (float) ($row['resources_used']['gasoline'] ?? 0.0);
            $participantMap[$nationId]['resource_usage']['munitions'] += (float) ($row['resources_used']['munitions'] ?? 0.0);
            $participantMap[$nationId]['resource_usage']['steel'] += (float) ($row['resources_used']['steel'] ?? 0.0);
            $participantMap[$nationId]['resource_usage']['aluminum'] += (float) ($row['resources_used']['aluminum'] ?? 0.0);
            $participantMap[$nationId]['resources_cost'] += (float) ($row['resources_cost'] ?? 0.0);
            $participantMap[$nationId]['unit_loss_cost'] += (float) ($row['unit_loss_cost'] ?? 0.0);
            $participantMap[$nationId]['infra_loss_cost'] += (float) ($row['infra_loss_cost'] ?? 0.0);
            $participantMap[$nationId]['total_cost'] += (float) (($row['unit_loss_cost'] ?? 0.0) + ($row['infra_loss_cost'] ?? 0.0));
            $participantMap[$nationId]['war_count']++;

            if (($row['is_active'] ?? false) === true) {
                $participantMap[$nationId]['active_war_count']++;
            }
        }

        $counter->assignments
            ->whereIn('status', ['assigned', 'finalized'])
            ->each(function ($assignment) use (&$participantMap): void {
                $friendly = $assignment->friendlyNation;
                $nationId = (int) ($friendly?->id ?? 0);

                if ($nationId <= 0 || isset($participantMap[$nationId])) {
                    return;
                }

                $participantMap[$nationId] = [
                    'nation_id' => $nationId,
                    'nation' => $friendly,
                    'resource_usage' => [
                        'gasoline' => 0.0,
                        'munitions' => 0.0,
                        'steel' => 0.0,
                        'aluminum' => 0.0,
                    ],
                    'resources_cost' => 0.0,
                    'unit_loss_cost' => 0.0,
                    'infra_loss_cost' => 0.0,
                    'total_cost' => 0.0,
                    'war_count' => 0,
                    'active_war_count' => 0,
                ];
            });

        $participantIds = collect(array_keys($participantMap))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $accountsByNation = Account::query()
            ->whereIn('nation_id', $participantIds->all())
            ->orderBy('name')
            ->get(['id', 'nation_id', 'name', 'frozen'])
            ->groupBy('nation_id');

        $reimbursedByNation = WarCounterReimbursement::query()
            ->where('war_counter_id', $counter->id)
            ->whereIn('nation_id', $participantIds->all())
            ->selectRaw(
                'nation_id, SUM(gasoline) as reimbursed_gasoline, SUM(munitions) as reimbursed_munitions, SUM(steel) as reimbursed_steel, SUM(aluminum) as reimbursed_aluminum, SUM(resources_cost) as reimbursed_resources_cost, SUM(unit_loss_cost) as reimbursed_unit_loss_cost, SUM(infra_loss_cost) as reimbursed_infra_loss_cost, SUM(total_cost) as reimbursed_total, COUNT(*) as reimbursement_count'
            )
            ->groupBy('nation_id')
            ->get()
            ->keyBy('nation_id');

        $participants = collect($participantMap)
            ->map(function (array $row, $nationId) use ($accountsByNation, $reimbursedByNation): array {
                $nationId = (int) $nationId;
                $accounts = ($accountsByNation->get($nationId) ?? collect())->values();
                $resourceUsage = [
                    'gasoline' => round((float) ($row['resource_usage']['gasoline'] ?? 0.0), 2),
                    'munitions' => round((float) ($row['resource_usage']['munitions'] ?? 0.0), 2),
                    'steel' => round((float) ($row['resource_usage']['steel'] ?? 0.0), 2),
                    'aluminum' => round((float) ($row['resource_usage']['aluminum'] ?? 0.0), 2),
                ];
                $reimbursedResources = [
                    'gasoline' => round((float) ($reimbursedByNation->get($nationId)?->reimbursed_gasoline ?? 0.0), 2),
                    'munitions' => round((float) ($reimbursedByNation->get($nationId)?->reimbursed_munitions ?? 0.0), 2),
                    'steel' => round((float) ($reimbursedByNation->get($nationId)?->reimbursed_steel ?? 0.0), 2),
                    'aluminum' => round((float) ($reimbursedByNation->get($nationId)?->reimbursed_aluminum ?? 0.0), 2),
                ];
                $reimbursedResourcesCost = round((float) ($reimbursedByNation->get($nationId)?->reimbursed_resources_cost ?? 0.0), 2);
                $reimbursedUnitLossCost = round((float) ($reimbursedByNation->get($nationId)?->reimbursed_unit_loss_cost ?? 0.0), 2);
                $reimbursedInfraLossCost = round((float) ($reimbursedByNation->get($nationId)?->reimbursed_infra_loss_cost ?? 0.0), 2);
                $reimbursedTotal = (float) ($reimbursedByNation->get($nationId)?->reimbursed_total ?? 0.0);
                $reimbursementCount = (int) ($reimbursedByNation->get($nationId)?->reimbursement_count ?? 0);
                $recommendedAccount = $accounts->first(fn (Account $account) => ! $account->frozen) ?? $accounts->first();

                $resourcesCost = round((float) $row['resources_cost'], 2);
                $unitLossCost = round((float) $row['unit_loss_cost'], 2);
                $infraLossCost = round((float) $row['infra_loss_cost'], 2);
                $totalCost = round((float) $row['total_cost'], 2);
                $outstandingResources = [
                    'gasoline' => round(max(0.0, $resourceUsage['gasoline'] - $reimbursedResources['gasoline']), 2),
                    'munitions' => round(max(0.0, $resourceUsage['munitions'] - $reimbursedResources['munitions']), 2),
                    'steel' => round(max(0.0, $resourceUsage['steel'] - $reimbursedResources['steel']), 2),
                    'aluminum' => round(max(0.0, $resourceUsage['aluminum'] - $reimbursedResources['aluminum']), 2),
                ];
                $outstandingResourcesCost = round(max(0.0, $resourcesCost - $reimbursedResourcesCost), 2);
                $outstandingUnitLossCost = round(max(0.0, $unitLossCost - $reimbursedUnitLossCost), 2);
                $outstandingInfraLossCost = round(max(0.0, $infraLossCost - $reimbursedInfraLossCost), 2);
                $outstandingCost = round($outstandingUnitLossCost + $outstandingInfraLossCost, 2);

                return [
                    'nation_id' => $nationId,
                    'nation' => $row['nation'],
                    'resource_usage' => $resourceUsage,
                    'reimbursed_resources' => $reimbursedResources,
                    'outstanding_resources' => $outstandingResources,
                    'resources_cost' => $resourcesCost,
                    'unit_loss_cost' => $unitLossCost,
                    'infra_loss_cost' => $infraLossCost,
                    'total_cost' => $totalCost,
                    'war_count' => (int) $row['war_count'],
                    'active_war_count' => (int) $row['active_war_count'],
                    'reimbursed_resources_cost' => $reimbursedResourcesCost,
                    'reimbursed_unit_loss_cost' => $reimbursedUnitLossCost,
                    'reimbursed_infra_loss_cost' => $reimbursedInfraLossCost,
                    'reimbursed_total' => round($reimbursedTotal, 2),
                    'outstanding_resources_cost' => $outstandingResourcesCost,
                    'outstanding_unit_loss_cost' => $outstandingUnitLossCost,
                    'outstanding_infra_loss_cost' => $outstandingInfraLossCost,
                    'outstanding_cost' => $outstandingCost,
                    'reimbursement_count' => $reimbursementCount,
                    'accounts' => $accounts,
                    'recommended_account_id' => $recommendedAccount?->id,
                ];
            })
            ->sortByDesc('total_cost')
            ->values();

        $totalCounterCost = round((float) $participants->sum('total_cost'), 2);
        $totalReimbursed = round((float) WarCounterReimbursement::query()
            ->where('war_counter_id', $counter->id)
            ->sum('total_cost'), 2);

        $recentReimbursements = WarCounterReimbursement::query()
            ->where('war_counter_id', $counter->id)
            ->with([
                'nation:id,leader_name,nation_name',
                'account:id,name',
                'reimbursedByUser:id,name',
            ])
            ->latest('id')
            ->limit(25)
            ->get();

        return [
            'prices' => $prices,
            'unit_prices' => $unitPrices,
            'trade_price_as_of' => $market['as_of'],
            'window_start' => $windowStart,
            'wars' => $warRows,
            'participants' => $participants,
            'participants_by_nation' => $participants->keyBy('nation_id'),
            'summary' => [
                'war_count' => $warRows->count(),
                'active_war_count' => $warRows->where('is_active', true)->count(),
                'participant_count' => $participants->count(),
                'total_resources_cost' => round((float) $participants->sum('resources_cost'), 2),
                'total_unit_loss_cost' => round((float) $participants->sum('unit_loss_cost'), 2),
                'total_infra_loss_cost' => round((float) $participants->sum('infra_loss_cost'), 2),
                'total_counter_cost' => $totalCounterCost,
                'total_reimbursed' => $totalReimbursed,
                'outstanding_total' => round((float) $participants->sum('outstanding_cost'), 2),
            ],
            'recent_reimbursements' => $recentReimbursements,
        ];
    }

    /**
     * @param  Collection<int, int>  $allianceIds
     * @return Collection<int, War>
     */
    private function counterWars(WarCounter $counter, Collection $allianceIds, Carbon $windowStart): Collection
    {
        if ($allianceIds->isEmpty()) {
            return collect();
        }

        return War::query()
            ->with([
                'attacker.alliance',
                'defender.alliance',
            ])
            ->where('date', '>=', $windowStart)
            ->where(function ($query) use ($counter, $allianceIds) {
                $query
                    ->where(function ($attackerBranch) use ($counter, $allianceIds) {
                        $attackerBranch
                            ->where('att_id', $counter->aggressor_nation_id)
                            ->whereIn('def_alliance_id', $allianceIds->all())
                            ->where(function ($friendlyDefender) {
                                $friendlyDefender->whereNull('def_alliance_position')
                                    ->orWhere('def_alliance_position', '!=', 'APPLICANT');
                            });
                    })
                    ->orWhere(function ($defenderBranch) use ($counter, $allianceIds) {
                        $defenderBranch
                            ->where('def_id', $counter->aggressor_nation_id)
                            ->whereIn('att_alliance_id', $allianceIds->all())
                            ->where(function ($friendlyAttacker) {
                                $friendlyAttacker->whereNull('att_alliance_position')
                                    ->orWhere('att_alliance_position', '!=', 'APPLICANT');
                            });
                    });
            })
            ->orderByDesc('date')
            ->get();
    }

    /**
     * @param  array<string, float>  $prices
     * @param  array<string, float>  $unitPrices
     * @return array<string, mixed>|null
     */
    private function buildWarRow(War $war, int $aggressorNationId, array $prices, array $unitPrices): ?array
    {
        if ((int) $war->att_id === $aggressorNationId) {
            $friendlyNation = $war->defender;
            $friendlyRole = 'defender';
            $resourcesUsed = [
                'gasoline' => (float) ($war->def_gas_used ?? 0),
                'munitions' => (float) ($war->def_mun_used ?? 0),
                'steel' => (float) ($war->def_steel_used ?? 0),
                'aluminum' => (float) ($war->def_alum_used ?? 0),
            ];
            $unitLosses = [
                'soldiers' => (int) ($war->def_soldiers_lost ?? 0),
                'tanks' => (int) ($war->def_tanks_lost ?? 0),
                'aircraft' => (int) ($war->def_aircraft_lost ?? 0),
                'ships' => (int) ($war->def_ships_lost ?? 0),
            ];
            $infraLossCost = (float) ($war->att_infra_destroyed_value ?? 0);
        } elseif ((int) $war->def_id === $aggressorNationId) {
            $friendlyNation = $war->attacker;
            $friendlyRole = 'attacker';
            $resourcesUsed = [
                'gasoline' => (float) ($war->att_gas_used ?? 0),
                'munitions' => (float) ($war->att_mun_used ?? 0),
                'steel' => (float) ($war->att_steel_used ?? 0),
                'aluminum' => (float) ($war->att_alum_used ?? 0),
            ];
            $unitLosses = [
                'soldiers' => (int) ($war->att_soldiers_lost ?? 0),
                'tanks' => (int) ($war->att_tanks_lost ?? 0),
                'aircraft' => (int) ($war->att_aircraft_lost ?? 0),
                'ships' => (int) ($war->att_ships_lost ?? 0),
            ];
            $infraLossCost = (float) ($war->def_infra_destroyed_value ?? 0);
        } else {
            return null;
        }

        $friendlyNationId = (int) ($friendlyNation?->id ?? 0);
        if ($friendlyNationId <= 0) {
            return null;
        }

        $resourcesCost = round(
            ($resourcesUsed['gasoline'] * $prices['gasoline'])
            + ($resourcesUsed['munitions'] * $prices['munitions'])
            + ($resourcesUsed['steel'] * $prices['steel'])
            + ($resourcesUsed['aluminum'] * $prices['aluminum']),
            2
        );

        $unitLossCost = round(
            ($unitLosses['soldiers'] * $unitPrices['soldiers'])
            + ($unitLosses['tanks'] * $unitPrices['tanks'])
            + ($unitLosses['aircraft'] * $unitPrices['aircraft'])
            + ($unitLosses['ships'] * $unitPrices['ships']),
            2
        );

        $infraLossCost = round($infraLossCost, 2);
        $totalCost = round($resourcesCost + $unitLossCost + $infraLossCost, 2);

        return [
            'war_id' => (int) $war->id,
            'date' => $war->date ? Carbon::parse($war->date) : null,
            'is_active' => $war->end_date === null && (int) ($war->turns_left ?? 0) > 0,
            'friendly_nation_id' => $friendlyNationId,
            'friendly_nation' => $friendlyNation,
            'friendly_role' => $friendlyRole,
            'resources_used' => $resourcesUsed,
            'resources_cost' => $resourcesCost,
            'unit_losses' => $unitLosses,
            'unit_loss_cost' => $unitLossCost,
            'infra_loss_cost' => $infraLossCost,
            'total_cost' => $totalCost,
        ];
    }

    /**
     * @return array{prices: array<string, float>, as_of: string|null}
     */
    private function resolveMarketPricing(): array
    {
        $prices = [
            'gasoline' => 0.0,
            'munitions' => 0.0,
            'steel' => 0.0,
            'aluminum' => 0.0,
        ];
        $asOf = null;

        try {
            $average = $this->tradePriceService->get24hAverage();
            $latest = $this->tradePriceService->getLatest();

            $prices = [
                'gasoline' => (float) ($average->gasoline ?? 0),
                'munitions' => (float) ($average->munitions ?? 0),
                'steel' => (float) ($average->steel ?? 0),
                'aluminum' => (float) ($average->aluminum ?? 0),
            ];
            $asOf = $latest->date?->toDateString() ?? optional($latest->created_at)->toDateString();
        } catch (Throwable) {
        }

        return [
            'prices' => $prices,
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  array<string, float>  $prices
     * @return array<string, float>
     */
    private function resolveUnitPrices(array $prices): array
    {
        return [
            'soldiers' => self::SOLDIER_BASE_COST,
            'tanks' => self::TANK_BASE_COST + (self::TANK_STEEL_FACTOR * $prices['steel']),
            'aircraft' => self::AIRCRAFT_BASE_COST + (self::AIRCRAFT_ALUMINUM_FACTOR * $prices['aluminum']),
            'ships' => self::SHIP_BASE_COST + (self::SHIP_STEEL_FACTOR * $prices['steel']),
        ];
    }

    private function counterWindowStart(WarCounter $counter): Carbon
    {
        $createdAt = $counter->created_at ? Carbon::parse($counter->created_at) : now()->subDays(7);
        $lastDeclaredAt = $counter->last_war_declared_at ? Carbon::parse($counter->last_war_declared_at) : null;

        if ($lastDeclaredAt && $lastDeclaredAt->lessThan($createdAt)) {
            return $lastDeclaredAt;
        }

        return $createdAt;
    }
}
