<?php

namespace App\Services;

use App\Models\Wars;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WarService
{
    protected int $ourAllianceId;

    public function __construct()
    {
        $this->ourAllianceId = (int) env("PW_ALLIANCE_ID");
    }

    /**
     * @return Collection
     */
    public function getActiveWars(): Collection
    {
        return Wars::with(['attacker.alliance', 'defender.alliance'])
            ->whereNull('end_date')
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->latest('date')
            ->get();
    }

    /**
     * @return Collection
     */
    public function getWarsLast30Days(): Collection
    {
        return Wars::with(['attacker.alliance', 'defender.alliance'])
            ->where('date', '>=', now()->subDays(30))
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->get();
    }

    /**
     * @return array
     */
    public function getStats(): array
    {
        $sevenDaysAgo = now()->subDays(7);

        $warsLast7Days = Wars::where('date', '>=', $sevenDaysAgo)
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->get();

        $totalLooted = $warsLast7Days->reduce(function ($carry, $war) {
            if ($war->att_alliance_id === $this->ourAllianceId) {
                return $carry + $war->att_money_looted;
            } elseif ($war->def_alliance_id === $this->ourAllianceId) {
                return $carry + $war->def_money_looted;
            }
            return $carry;
        }, 0);

        $activeWars = $this->getActiveWars();
        $averageDuration = $activeWars->avg(fn($w) => Carbon::parse($w->date)->diffInDays(now()));

        return [
            'total_ongoing' => $activeWars->count(),
            'wars_last_7_days' => $warsLast7Days->count(),
            'avg_duration' => round($averageDuration, 1),
            'total_looted' => $totalLooted,
        ];
    }

    /**
     * @return array
     */
    public function getWarTypeDistribution(): array
    {
        return Wars::whereNull('end_date')
            ->selectRaw('war_type, COUNT(*) as count')
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->groupBy('war_type')
            ->pluck('count', 'war_type')
            ->toArray();
    }

    /**
     * @return array
     */
    public function getWarStartHistory(): array
    {
        return Wars::whereDate('date', '>=', now()->subDays(30))
            ->selectRaw('DATE(date) as date, COUNT(*) as count')
            ->whereNull('end_date')
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->groupByRaw('DATE(date)')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * @param int $limit
     * @return array
     */
    public function getTopNationsWithActiveWars(int $limit = 5): array
    {
        return Wars::whereNull('end_date')
            ->selectRaw('att_id as nation_id, COUNT(*) as total')
            ->where(function ($query) {
                $this->whereOurAllianceEngagedProperly($query);
            })
            ->groupBy('att_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->pluck('total', 'nation_id')
            ->toArray();
    }

    /**
     * @param Wars $war
     * @return bool
     */
    public function isUsAttacker(Wars $war): bool
    {
        return $war->att_alliance_id === $this->ourAllianceId;
    }

    /**
     * @param Wars $war
     * @return int
     */
    public function getOurResistance(Wars $war): int
    {
        return $this->isUsAttacker($war) ? $war->att_resistance : $war->def_resistance;
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
     * @return array[]
     */
    public function getResourceUsageSummary(): array
    {
        $wars = $this->getWarsLast30Days();

        return [
            'gasoline' => ['used' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->att_gas_used : $w->def_gas_used)],
            'munitions' => ['used' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->att_mun_used : $w->def_mun_used)],
            'steel'     => ['used' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->att_steel_used : $w->def_steel_used)],
            'aluminum'  => ['used' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->att_alum_used : $w->def_alum_used)],
        ];
    }

    /**
     * @return array
     */
    public function getDamageDealtVsTaken(): array
    {
        $wars = $this->getWarsLast30Days();

        $metrics = [
            'infra_destroyed_value',
            'soldiers_killed',
            'tanks_killed',
            'aircraft_killed',
            'ships_killed',
        ];

        $result = [];

        foreach ($metrics as $metric) {
            [$attKey, $defKey] = ["att_{$metric}", "def_{$metric}"];
            $result[$metric] = [
                'dealt' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->$attKey : $w->$defKey),
                'taken' => $wars->sum(fn($w) => $this->isUsAttacker($w) ? $w->$defKey : $w->$attKey),
            ];
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getAggressorDefenderSplit(): array
    {
        $wars = $this->getWarsLast30Days();

        $aggressor = $wars->filter(fn($w) => $this->isUsAttacker($w))->count();
        $defender = $wars->count() - $aggressor;

        return [
            'Aggressor' => $aggressor,
            'Defender' => $defender,
        ];
    }

    /**
     * @return array
     */
    public function getActiveWarsByNation(): array
    {
        $wars = $this->getWarsLast30Days();

        $nationCounts = [];

        foreach ($wars as $war) {
            $nation = $this->isUsAttacker($war) ? $war->attacker : $war->defender;

            if (!$nation) continue;

            $name = $nation->leader_name ?? 'Unknown';
            $nationCounts[$name] = ($nationCounts[$name] ?? 0) + 1;
        }

        arsort($nationCounts);

        return $nationCounts;
    }
}