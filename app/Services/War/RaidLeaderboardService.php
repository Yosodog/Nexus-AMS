<?php

namespace App\Services\War;

use App\Enums\WarAttackTypeEnum;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\AllianceMembershipService;
use App\Services\PWHelperService;
use App\Services\TradePriceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RaidLeaderboardService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly TradePriceService $tradePriceService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildLeaderboard(Carbon $from, Carbon $to, ?int $nationId = null): array
    {
        $memberNations = $this->memberNations();
        $memberIds = $memberNations->keys()->all();
        $resourceKeys = PWHelperService::resources(includeMoney: false);
        $resourcePrices = $this->resourcePrices($resourceKeys);

        $cacheKey = sprintf(
            'raid_leaderboard:%s:%s:%s:%s',
            $from->toDateString(),
            $to->toDateString(),
            md5(json_encode($memberIds)),
            $nationId ?? 'na'
        );

        $dayWindow = max(1, $from->diffInDays($to) + 1);

        return Cache::remember($cacheKey, 300, function () use ($from, $to, $memberIds, $memberNations, $resourceKeys, $resourcePrices, $dayWindow, $nationId) {
            $stats = $this->seedStats($memberNations, $resourceKeys);

            if (empty($memberIds)) {
                return $this->buildPayload($stats, $resourceKeys, $resourcePrices, collect(), collect(), collect(), $dayWindow, $nationId);
            }

            $attackAggregates = $this->attackAggregates($from, $to, $memberIds);
            $victoryAggregates = $this->victoryAggregates($from, $to, $memberIds);

            foreach ($attackAggregates as $row) {
                $attackerId = (int) $row->att_id;

                if (! array_key_exists($attackerId, $stats)) {
                    continue;
                }

                $stats[$attackerId]['attacks'] = (int) $row->attacks;
                $stats[$attackerId]['infra_destroyed'] = (float) $row->infra_destroyed;
                $stats[$attackerId]['infra_destroyed_value'] = (float) $row->infra_destroyed_value;
                $stats[$attackerId]['soldiers_killed'] = (int) $row->soldiers_killed;
                $stats[$attackerId]['tanks_killed'] = (int) $row->tanks_killed;
                $stats[$attackerId]['aircraft_killed'] = (int) $row->aircraft_killed;
                $stats[$attackerId]['ships_killed'] = (int) $row->ships_killed;
            }

            $resourceColumnMap = $this->resourceColumnMap();
            foreach ($victoryAggregates as $row) {
                $winnerId = (int) $row->winner_id;

                if (! array_key_exists($winnerId, $stats)) {
                    continue;
                }

                $stats[$winnerId]['victories'] = (int) $row->victories;
                $moneyLooted = (float) $row->money_looted;
                $stats[$winnerId]['money_looted'] = $moneyLooted;

                $lootValue = $moneyLooted;
                foreach ($resourceKeys as $resource) {
                    $column = $resourceColumnMap[$resource] ?? $resource;
                    $amount = (float) ($row->{$column} ?? 0);
                    $stats[$winnerId]['resources_looted'][$resource] = $amount;
                    $lootValue += $amount * ($resourcePrices[$resource] ?? 0);
                }

                $stats[$winnerId]['loot_value'] = $lootValue;
            }

            $lootTimeline = $this->lootTimeline($from, $to, $memberIds);
            $infraTimeline = $this->infraTimeline($from, $to, $memberIds);
            $attackTimeline = $this->attackTimeline($from, $to, $memberIds);

            return $this->buildPayload($stats, $resourceKeys, $resourcePrices, $lootTimeline, $infraTimeline, $attackTimeline, $dayWindow, $nationId);
        });
    }

    private function memberNations(): Collection
    {
        return Nation::query()
            ->select(['id', 'leader_name', 'nation_name'])
            ->whereIn('alliance_id', $this->membershipService->getAllianceIds())
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, string>  $resourceKeys
     * @return array<string, float>
     */
    private function resourcePrices(array $resourceKeys): array
    {
        $tradePrices = $this->tradePriceService->get24hAverage();

        return collect($resourceKeys)->mapWithKeys(fn (string $resource) => [
            $resource => (float) ($tradePrices->{$resource} ?? 0),
        ])->all();
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function attackAggregates(Carbon $from, Carbon $to, array $memberIds): Collection
    {
        return WarAttack::query()
            ->selectRaw('att_id, COUNT(*) as attacks')
            ->selectRaw('SUM(infra_destroyed) as infra_destroyed')
            ->selectRaw('SUM(infra_destroyed_value) as infra_destroyed_value')
            ->selectRaw('SUM(def_soldiers_lost) as soldiers_killed')
            ->selectRaw('SUM(def_tanks_lost) as tanks_killed')
            ->selectRaw('SUM(def_aircraft_lost) as aircraft_killed')
            ->selectRaw('SUM(def_ships_lost) as ships_killed')
            ->whereBetween('date', [$from, $to])
            ->whereIn('att_id', $memberIds)
            ->groupBy('att_id')
            ->get();
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function victoryAggregates(Carbon $from, Carbon $to, array $memberIds): Collection
    {
        return WarAttack::query()
            ->selectRaw('IFNULL(NULLIF(victor, 0), att_id) as winner_id')
            ->selectRaw('COUNT(*) as victories')
            ->selectRaw('SUM(money_looted) as money_looted')
            ->selectRaw('SUM(coal_looted) as coal')
            ->selectRaw('SUM(oil_looted) as oil')
            ->selectRaw('SUM(uranium_looted) as uranium')
            ->selectRaw('SUM(iron_looted) as iron')
            ->selectRaw('SUM(bauxite_looted) as bauxite')
            ->selectRaw('SUM(lead_looted) as lead_looted_total')
            ->selectRaw('SUM(gasoline_looted) as gasoline')
            ->selectRaw('SUM(munitions_looted) as munitions')
            ->selectRaw('SUM(steel_looted) as steel')
            ->selectRaw('SUM(aluminum_looted) as aluminum')
            ->selectRaw('SUM(food_looted) as food')
            ->whereBetween('date', [$from, $to])
            ->where('type', WarAttackTypeEnum::VICTORY->value)
            ->where(function ($query) use ($memberIds) {
                $query->whereIn('victor', $memberIds)
                    ->orWhere(function ($inner) use ($memberIds) {
                        $inner->whereIn('att_id', $memberIds)
                            ->where(function ($filter) {
                                $filter->whereNull('victor')
                                    ->orWhere('victor', 0);
                            });
                    });
            })
            ->groupByRaw('IFNULL(NULLIF(victor, 0), att_id)')
            ->get();
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function lootTimeline(Carbon $from, Carbon $to, array $memberIds): Collection
    {
        return WarAttack::query()
            ->selectRaw('DATE(date) as day')
            ->selectRaw('SUM(money_looted) as money_looted')
            ->selectRaw('SUM(coal_looted) as coal_looted')
            ->selectRaw('SUM(oil_looted) as oil_looted')
            ->selectRaw('SUM(uranium_looted) as uranium_looted')
            ->selectRaw('SUM(iron_looted) as iron_looted')
            ->selectRaw('SUM(bauxite_looted) as bauxite_looted')
            ->selectRaw('SUM(lead_looted) as lead_looted')
            ->selectRaw('SUM(gasoline_looted) as gasoline_looted')
            ->selectRaw('SUM(munitions_looted) as munitions_looted')
            ->selectRaw('SUM(steel_looted) as steel_looted')
            ->selectRaw('SUM(aluminum_looted) as aluminum_looted')
            ->selectRaw('SUM(food_looted) as food_looted')
            ->whereBetween('date', [$from, $to])
            ->where('type', WarAttackTypeEnum::VICTORY->value)
            ->where(function ($query) use ($memberIds) {
                $query->whereIn('victor', $memberIds)
                    ->orWhere(function ($inner) use ($memberIds) {
                        $inner->whereIn('att_id', $memberIds)
                            ->where(function ($filter) {
                                $filter->whereNull('victor')
                                    ->orWhere('victor', 0);
                            });
                    });
            })
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function infraTimeline(Carbon $from, Carbon $to, array $memberIds): Collection
    {
        return WarAttack::query()
            ->selectRaw('DATE(date) as day')
            ->selectRaw('SUM(infra_destroyed_value) as infra_destroyed_value')
            ->selectRaw('SUM(infra_destroyed) as infra_destroyed')
            ->whereBetween('date', [$from, $to])
            ->whereIn('att_id', $memberIds)
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function attackTimeline(Carbon $from, Carbon $to, array $memberIds): Collection
    {
        return WarAttack::query()
            ->selectRaw('DATE(date) as day')
            ->selectRaw('COUNT(*) as attacks')
            ->whereBetween('date', [$from, $to])
            ->whereIn('att_id', $memberIds)
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * @param  Collection<int, \App\Models\Nation>  $memberNations
     * @param  array<int, string>  $resourceKeys
     * @return array<int, array<string, mixed>>
     */
    private function seedStats(Collection $memberNations, array $resourceKeys): array
    {
        $stats = [];

        foreach ($memberNations as $nation) {
            $stats[$nation->id] = [
                'id' => $nation->id,
                'leader_name' => $nation->leader_name,
                'nation_name' => $nation->nation_name,
                'attacks' => 0,
                'victories' => 0,
                'loot_value' => 0,
                'money_looted' => 0,
                'resources_looted' => array_fill_keys($resourceKeys, 0),
                'infra_destroyed' => 0,
                'infra_destroyed_value' => 0,
                'soldiers_killed' => 0,
                'tanks_killed' => 0,
                'aircraft_killed' => 0,
                'ships_killed' => 0,
                'unit_score' => 0,
            ];
        }

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stats
     * @param  array<int, string>  $resourceKeys
     * @param  array<string, float>  $resourcePrices
     * @return array<string, mixed>
     */
    private function buildPayload(
        array $stats,
        array $resourceKeys,
        array $resourcePrices,
        Collection $lootTimeline,
        Collection $infraTimeline,
        Collection $attackTimeline,
        int $dayWindow,
        ?int $nationId
    ): array {
        $weights = [
            'soldiers' => 0.0004,
            'tanks' => 0.025,
            'aircraft' => 0.3,
            'ships' => 1,
        ];

        foreach ($stats as $memberId => $row) {
            $score = 0;
            foreach ($weights as $unit => $weight) {
                $score += ($row[$unit.'_killed'] ?? 0) * $weight;
            }

            $stats[$memberId]['unit_score'] = round($score, 2);
        }

        $collection = $this->withDerivedMetrics(collect($stats)->values());

        $resourceTotals = collect($resourceKeys)->mapWithKeys(function (string $resource) use ($collection) {
            return [$resource => $collection->sum(fn (array $row) => $row['resources_looted'][$resource] ?? 0)];
        })->all();

        $resourceValues = collect($resourceTotals)->mapWithKeys(function (float $amount, string $resource) use ($resourcePrices) {
            return [$resource => round($amount * ($resourcePrices[$resource] ?? 0), 2)];
        })->all();

        $resourceValue = collect($resourceTotals)
            ->map(fn (float $amount, string $resource) => $amount * ($resourcePrices[$resource] ?? 0))
            ->sum();

        $totalLootValue = $collection->sum('loot_value');
        $totalAttacks = $collection->sum('attacks');
        $totalVictories = $collection->sum('victories');
        $totalInfraValue = $collection->sum('infra_destroyed_value');

        $lootSplit = [
            'labels' => ['Money', 'Resources'],
            'values' => [(float) $collection->sum('money_looted'), (float) $resourceValue],
        ];

        $bestLootDay = $this->bestLootDay($lootTimeline, $resourceKeys, $resourcePrices);
        $bestAttackDay = $this->bestAttackDay($attackTimeline);

        $selfStats = $this->selfSnapshot($collection, $nationId, [
            'loot_value',
            'infra_destroyed_value',
            'unit_score',
            'victories',
            'loot_per_attack',
            'loot_per_victory',
            'infra_per_attack',
            'kill_score_per_attack',
        ]);

        return [
            'leaderboards' => [
                'loot' => $this->rank($collection, 'loot_value'),
                'loot_rate' => $this->rank($collection, 'loot_per_attack'),
                'loot_closer' => $this->rank($collection, 'loot_per_victory'),
                'infra' => $this->rank($collection, 'infra_destroyed_value'),
                'infra_rate' => $this->rank($collection, 'infra_per_attack'),
                'kills' => $this->rank($collection, 'unit_score'),
                'kill_rate' => $this->rank($collection, 'kill_score_per_attack'),
                'victories' => $this->rank($collection, 'victories'),
                'attacks' => $this->rank($collection, 'attacks'),
                'money' => $this->rank($collection, 'money_looted'),
            ],
            'totals' => [
                'loot_value' => $totalLootValue,
                'loot_value_per_day' => $dayWindow > 0 ? round($totalLootValue / $dayWindow, 2) : 0,
                'infra_destroyed_value' => $totalInfraValue,
                'attacks' => $totalAttacks,
                'victories' => $totalVictories,
                'avg_loot_per_attack' => $totalAttacks > 0 ? round($totalLootValue / $totalAttacks, 2) : 0,
                'avg_loot_per_victory' => $totalVictories > 0 ? round($totalLootValue / $totalVictories, 2) : 0,
                'avg_infra_per_attack' => $totalAttacks > 0 ? round($totalInfraValue / $totalAttacks, 2) : 0,
                'kills_score' => $collection->sum('unit_score'),
                'money_looted' => $collection->sum('money_looted'),
                'resources_looted' => $resourceTotals,
                'resource_values' => $resourceValues,
                'resources_value' => round($resourceValue, 2),
                'top_looter' => $this->firstOrNull($this->rank($collection, 'loot_value')),
                'top_closer' => $this->firstOrNull($this->rank($collection, 'victories')),
                'loot_split' => $lootSplit,
                'resource_share_pct' => $totalLootValue > 0 ? round(($resourceValue / $totalLootValue) * 100, 2) : 0,
                'money_share_pct' => $totalLootValue > 0 ? round(((float) $collection->sum('money_looted') / $totalLootValue) * 100, 2) : 0,
                'best_loot_day' => $bestLootDay,
                'best_attack_day' => $bestAttackDay,
            ],
            'charts' => [
                'loot_timeline' => $this->buildLootTimelineChart($lootTimeline, $resourceKeys, $resourcePrices),
                'infra_timeline' => $this->buildInfraTimelineChart($infraTimeline),
                'attack_timeline' => $this->buildAttackTimelineChart($attackTimeline),
                'resource_mix' => [
                    'labels' => array_map('ucfirst', $resourceKeys),
                    'values' => array_values($resourceTotals),
                ],
                'top_loot' => $this->buildLeaderboardChart($collection, 'loot_value', 6),
                'top_infra' => $this->buildLeaderboardChart($collection, 'infra_destroyed_value', 6),
                'top_efficiency' => $this->buildLeaderboardChart($collection, 'loot_per_attack', 6),
            ],
            'self_stats' => $selfStats,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $collection
     * @return array<int, array<string, mixed>>
     */
    private function rank(Collection $collection, string $key, int $limit = 10): array
    {
        return $collection
            ->sortByDesc($key)
            ->take($limit)
            ->values()
            ->map(function (array $row, int $index) use ($key) {
                $row['rank'] = $index + 1;
                $row['metric'] = $row[$key] ?? 0;

                return $row;
            })
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function withDerivedMetrics(Collection $collection): Collection
    {
        return $collection->map(function (array $row) {
            $attacks = (int) ($row['attacks'] ?? 0);
            $victories = (int) ($row['victories'] ?? 0);
            $lootValue = (float) ($row['loot_value'] ?? 0);
            $infraValue = (float) ($row['infra_destroyed_value'] ?? 0);
            $unitScore = (float) ($row['unit_score'] ?? 0);

            $row['loot_per_attack'] = $attacks > 0 ? round($lootValue / $attacks, 2) : 0;
            $row['loot_per_victory'] = $victories > 0 ? round($lootValue / $victories, 2) : 0;
            $row['infra_per_attack'] = $attacks > 0 ? round($infraValue / $attacks, 2) : 0;
            $row['kill_score_per_attack'] = $attacks > 0 ? round($unitScore / $attacks, 2) : 0;

            return $row;
        });
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, float>}
     */
    private function buildLeaderboardChart(Collection $collection, string $metric, int $limit): array
    {
        $rows = $collection->sortByDesc($metric)->take($limit)->values();

        return [
            'labels' => $rows->map(fn (array $row) => $row['leader_name'])->all(),
            'values' => $rows->map(fn (array $row) => (float) ($row[$metric] ?? 0))->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function firstOrNull(array $rows): ?array
    {
        return $rows[0] ?? null;
    }

    /**
     * @param  array<int, string>  $resourceKeys
     * @param  array<string, float>  $resourcePrices
     * @return array<string, array<int, float|string>>
     */
    private function buildLootTimelineChart(Collection $lootTimeline, array $resourceKeys, array $resourcePrices): array
    {
        $labels = [];
        $values = [];

        foreach ($lootTimeline as $row) {
            $labels[] = Carbon::parse($row->day)->format('M d');
            $lootValue = (float) $row->money_looted;

            foreach ($resourceKeys as $resource) {
                $column = $resource.'_looted';
                $lootValue += (float) ($row->{$column} ?? 0) * ($resourcePrices[$resource] ?? 0);
            }

            $values[] = round($lootValue, 2);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * @return array<string, array<int, float|string>>
     */
    private function buildInfraTimelineChart(Collection $infraTimeline): array
    {
        $labels = [];
        $values = [];

        foreach ($infraTimeline as $row) {
            $labels[] = Carbon::parse($row->day)->format('M d');
            $values[] = round((float) $row->infra_destroyed_value, 2);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * @return array<string, array<int, float|string>>
     */
    private function buildAttackTimelineChart(Collection $attackTimeline): array
    {
        $labels = [];
        $values = [];

        foreach ($attackTimeline as $row) {
            $labels[] = Carbon::parse($row->day)->format('M d');
            $values[] = (int) $row->attacks;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * @param  array<int, string>  $resourceKeys
     * @param  array<string, float>  $resourcePrices
     * @return array{label: string, value: float}|null
     */
    private function bestLootDay(Collection $lootTimeline, array $resourceKeys, array $resourcePrices): ?array
    {
        $best = null;

        foreach ($lootTimeline as $row) {
            $lootValue = (float) $row->money_looted;
            foreach ($resourceKeys as $resource) {
                $column = $resource.'_looted';
                $lootValue += (float) ($row->{$column} ?? 0) * ($resourcePrices[$resource] ?? 0);
            }

            if (! $best || $lootValue > $best['value']) {
                $best = [
                    'label' => Carbon::parse($row->day)->format('M d, Y'),
                    'value' => round($lootValue, 2),
                ];
            }
        }

        return $best;
    }

    /**
     * @return array{label: string, value: int}|null
     */
    private function bestAttackDay(Collection $attackTimeline): ?array
    {
        $best = null;

        foreach ($attackTimeline as $row) {
            $count = (int) $row->attacks;
            if (! $best || $count > $best['value']) {
                $best = [
                    'label' => Carbon::parse($row->day)->format('M d, Y'),
                    'value' => $count,
                ];
            }
        }

        return $best;
    }

    /**
     * @return array<string, string>
     */
    private function resourceColumnMap(): array
    {
        return [
            'lead' => 'lead_looted_total',
        ];
    }

    /**
     * @param  array<int, string>  $metrics
     * @return array<string, mixed>|null
     */
    private function selfSnapshot(Collection $collection, ?int $nationId, array $metrics): ?array
    {
        if (! $nationId) {
            return null;
        }

        $row = $collection->firstWhere('id', $nationId);
        if (! $row) {
            return null;
        }

        $ranks = [];
        foreach ($metrics as $metric) {
            $ranks[$metric] = $this->rankMap($collection, $metric)[$nationId] ?? null;
        }

        $memberCount = $collection->count();
        $lootShare = $collection->sum('loot_value') > 0
            ? round(((float) $row['loot_value'] / (float) $collection->sum('loot_value')) * 100, 2)
            : 0;

        return [
            'stats' => $row,
            'ranks' => $ranks,
            'member_count' => $memberCount,
            'loot_share' => $lootShare,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function rankMap(Collection $collection, string $key): array
    {
        $ranked = [];
        $position = 1;

        foreach ($collection->sortByDesc($key) as $row) {
            $ranked[$row['id']] = $position;
            $position++;
        }

        return $ranked;
    }
}
