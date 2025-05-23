<?php

namespace App\Services;

use App\Models\War;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WarService
{
    protected int $ourAllianceId;
    protected ?Collection $cachedActiveWars = null;
    protected ?Collection $cachedRecentWars = null;

    public function __construct()
    {
        $this->ourAllianceId = (int)env("PW_ALLIANCE_ID");
    }

    /**
     * @return array
     */
    public function getStats(): array
    {
        return cache()->remember('war_stats_summary', 300, function () {
            $warsLast7Days = $this->getWarsLast30Days()->filter(fn($w) => $w->date >= now()->subDays(7));

            $totalLooted = $warsLast7Days->reduce(function ($carry, $war) {
                if ($war->att_alliance_id === $this->ourAllianceId) {
                    return $carry + $war->att_money_looted;
                } elseif ($war->def_alliance_id === $this->ourAllianceId) {
                    return $carry + $war->def_money_looted;
                }
                return $carry;
            }, 0);

            $activeWars = $this->getActiveWars();
            $averageDuration = $activeWars->avg(function ($war) {
                $start = Carbon::parse($war->date);

                // If it ended, use the end date
                if ($war->end_date) {
                    return $start->diffInDays(Carbon::parse($war->end_date));
                }

                // If it's still active, use now (but clamp to max 5 days)
                $duration = $start->diffInDays(now());

                return min($duration, 5);
            });

            return [
                'total_ongoing' => $activeWars->count(),
                'wars_last_7_days' => $warsLast7Days->count(),
                'avg_duration' => round($averageDuration, 1),
                'total_looted' => $totalLooted,
            ];
        });
    }

    /**
     * @return Collection
     */
    public function getWarsLast30Days(): Collection
    {
        if ($this->cachedRecentWars) {
            return $this->cachedRecentWars;
        }

        return $this->cachedRecentWars = cache()->remember('wars_last_30_days_collection', 300, function () {
            return War::with([
                'attacker:id,leader_name,alliance_id',
                'attacker.alliance:id,name',
                'defender:id,leader_name,alliance_id',
                'defender.alliance:id,name',
            ])
                ->where('date', '>=', now()->subDays(30))
                ->where(function ($query) {
                    $this->whereOurAllianceEngagedProperly($query);
                })
                ->get();
        });
    }

    /**
     * @param $query
     * @return void
     */
    private function whereOurAllianceEngagedProperly($query): void
    {
        $query->where(function ($q) {
            $q->where('att_alliance_id', $this->ourAllianceId)
                ->where('att_alliance_position', '!=', 'APPLICANT');
        })->orWhere(function ($q) {
            $q->where('def_alliance_id', $this->ourAllianceId)
                ->where('def_alliance_position', '!=', 'APPLICANT');
        });
    }

    /**
     * @return Collection
     */
    public function getActiveWars(): Collection
    {
        return $this->cachedActiveWars ??= War::with([
            'attacker:id,leader_name,alliance_id',
            'attacker.alliance:id,name',
            'defender:id,leader_name,alliance_id',
            'defender.alliance:id,name',
        ])
            ->whereNull('end_date')
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->latest('date')
            ->get();
    }

    /**
     * @return array
     */
    public function getWarTypeDistribution(): array
    {
        return cache()->remember('war_type_distribution', 300, function () {
            return War::whereNull('end_date')
                ->selectRaw('war_type, COUNT(*) as count')
                ->where(function ($query) {
                    $this->whereOurAllianceEngagedProperly($query);
                })
                ->groupBy('war_type')
                ->pluck('count', 'war_type')
                ->toArray();
        });
    }

    /**
     * @return array
     */
    public function getWarStartHistory(): array
    {
        return cache()->remember('war_start_history', 300, function () {
            return War::whereDate('date', '>=', now()->subDays(30))
                ->selectRaw('DATE(date) as date, COUNT(*) as count')
                ->where(function ($query) {
                    $this->whereOurAllianceEngagedProperly($query);
                })
                ->groupByRaw('DATE(date)')
                ->orderBy('date')
                ->pluck('count', 'date')
                ->toArray();
        });
    }

    /**
     * @param int $limit
     * @return array
     */
    public function getTopNationsWithActiveWars(int $limit = 5): array
    {
        return cache()->remember("top_nations_active_wars_{$limit}", 300, function () use ($limit) {
            $attackerCounts = War::whereNull('end_date')
                ->where(function ($query) {
                    $this->whereOurAllianceEngagedProperly($query);
                })
                ->selectRaw('att_id as nation_id, COUNT(*) as total')
                ->groupBy('att_id')
                ->pluck('total', 'nation_id');

            $defenderCounts = War::whereNull('end_date')
                ->where(function ($query) {
                    $this->whereOurAllianceEngagedProperly($query);
                })
                ->selectRaw('def_id as nation_id, COUNT(*) as total')
                ->groupBy('def_id')
                ->pluck('total', 'nation_id');

            // Merge counts: add together if they exist in both
            $combined = $attackerCounts->mergeRecursive($defenderCounts)->map(function ($value) {
                return is_array($value) ? array_sum($value) : $value;
            });

            return $combined->sortDesc()->take($limit)->toArray();
        });
    }

    /**
     * @param War $war
     * @return int
     */
    public function getOurResistance(War $war): int
    {
        return $this->isUsAttacker($war) ? $war->att_resistance : $war->def_resistance;
    }

    /**
     * @param War $war
     * @return bool
     */
    public function isUsAttacker(War $war): bool
    {
        return $war->att_alliance_id === $this->ourAllianceId;
    }

    /**
     * @return array[]
     */
    public function getResourceUsageSummary(): array
    {
        return cache()->remember('war_resource_usage_summary', 300, function () {
            $wars = $this->getWarsLast30Days();

            $resources = [
                'gasoline' => ['att_gas_used', 'def_gas_used'],
                'munitions' => ['att_mun_used', 'def_mun_used'],
                'steel' => ['att_steel_used', 'def_steel_used'],
                'aluminum' => ['att_alum_used', 'def_alum_used'],
            ];

            $summary = [];

            foreach ($resources as $key => [$attKey, $defKey]) {
                $summary[$key] = [
                    'used' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->$attKey : $w->$defKey)
                ];
            }

            return $summary;
        });
    }

    /**
     * @return array
     */
    public function getDamageDealtVsTaken(): array
    {
        return cache()->remember('war_damage_dealt_vs_taken', 300, function () {
            $wars = $this->getWarsLast30Days();

            $infraMetrics = [
                'infra_destroyed_value' => ['att_infra_destroyed_value', 'def_infra_destroyed_value'],
            ];

            $unitMetrics = [
                'soldiers' => ['att_soldiers_lost', 'def_soldiers_lost'],
                'tanks' => ['att_tanks_lost', 'def_tanks_lost'],
                'aircraft' => ['att_aircraft_lost', 'def_aircraft_lost'],
                'ships' => ['att_ships_lost', 'def_ships_lost'],
            ];

            $result = [];

            foreach ($infraMetrics as $key => [$attKey, $defKey]) {
                $result[$key] = $this->calculateDealtAndTaken($wars, $attKey, $defKey, false);
            }

            foreach ($unitMetrics as $key => [$attKey, $defKey]) {
                $result[$key] = $this->calculateDealtAndTaken($wars, $attKey, $defKey, true);
            }

            return $result;
        });
    }

    /**
     * @param Collection $wars
     * @param string $attKey
     * @param string $defKey
     * @param bool $flip
     * @return array
     */
    private function calculateDealtAndTaken(Collection $wars, string $attKey, string $defKey, bool $flip = false): array
    {
        return [
            'dealt' => $wars->sum(fn($w) => $this->isUsAttacker($w)
                ? ($flip ? $w->$defKey : $w->$attKey)
                : ($flip ? $w->$attKey : $w->$defKey)
            ),
            'taken' => $wars->sum(fn($w) => $this->isUsAttacker($w)
                ? ($flip ? $w->$attKey : $w->$defKey)
                : ($flip ? $w->$defKey : $w->$attKey)
            ),
        ];
    }

    /**
     * @return array
     */
    public function getAggressorDefenderSplit(): array
    {
        return cache()->remember('war_aggressor_defender_split', 300, function () {
            $wars = $this->getWarsLast30Days();

            $aggressor = $wars->filter(fn($w) => $this->isUsAttacker($w))->count();
            $defender = $wars->count() - $aggressor;

            return [
                'Aggressor' => $aggressor,
                'Defender' => $defender,
            ];
        });
    }

    /**
     * @return array
     */
    public function getActiveWarsByNation(): array
    {
        $wars = $this->getActiveWars();

        $nationCounts = [];

        foreach ($wars as $war) {
            $nation = $this->isUsAttacker($war) ? $war->attacker : $war->defender;

            if (!$nation) {
                continue;
            }

            $name = $nation->leader_name ?? 'Unknown';
            $nationCounts[$name] = ($nationCounts[$name] ?? 0) + 1;
        }

        arsort($nationCounts);

        return $nationCounts;
    }
}