<?php

namespace App\Services;

use App\Models\Nations;
use App\Models\NationSignIns;

class MemberStatsService
{
    /**
     * @return array
     */
    public function getOverviewData(): array
    {
        $nations = Nations::with(['resources', 'accounts', 'military'])
            ->where('alliance_id', env("PW_ALLIANCE_ID"))
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0)
            ->get();

        $maxTier = $nations->max('num_cities') ?? 0;

        $cityTiers = collect(range(1, $maxTier))->mapWithKeys(fn($tier) => [
            $tier => $nations->where('num_cities', $tier)->count(),
        ])->toArray();

        return [
            'totalMembers' => $nations->count(),
            'avgScore' => round($nations->avg('score'), 2),
            'totalCities' => $nations->sum('num_cities'),
            'cityTiers' => $cityTiers,
            'cityGrowthHistory' => $this->getCityGrowthHistory(),
            'members' => $nations->map(fn($nation) => $this->formatNation($nation)),
        ];
    }

    /**
     * @return array
     */
    protected function getCityGrowthHistory(): array
    {
        return NationSignIns::selectRaw('DATE(created_at) as date, SUM(num_cities) as total_cities')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total_cities', 'date')
            ->toArray();
    }

    /**
     * @param Nations $nation
     * @return array
     */
    protected function formatNation(Nations $nation): array
    {
        $cities = $nation->num_cities;
        $max = [
            'soldiers' => $cities * 15000,
            'tanks' => $cities * 1250,
            'aircraft' => $cities * 75,
            'ships' => $cities * 15,
        ];

        $current = [
            'soldiers' => $nation->military->soldiers ?? 0,
            'tanks' => $nation->military->tanks ?? 0,
            'aircraft' => $nation->military->aircraft ?? 0,
            'ships' => $nation->military->ships ?? 0,
        ];

        $militaryPercent = collect($max)->mapWithKeys(fn($maxVal, $type) => [
            $type => $maxVal > 0 ? round(($current[$type] / $maxVal) * 100, 2) : 0,
        ])->toArray();

        $resources = [
            'money',
            'steel',
            'gasoline',
            'aluminum',
            'munitions',
            'uranium',
            'food'
        ];

        $resourceValues = collect($resources)->mapWithKeys(function ($res) use ($nation) {
            $accountTotal = $nation->accounts->sum($res);
            $inGame = optional($nation->resources)->$res ?? 0;

            return [
                $res => [
                    'total' => $accountTotal + $inGame,
                    'in_game' => $inGame,
                ]
            ];
        });

        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'score' => $nation->score,
            'cities' => $cities,
            'timezone' => $nation->update_tz,
            'spies' => $nation->military->spies,
            'military_percent' => $militaryPercent,
            'military_current' => $current,
            'resources' => $resourceValues,
        ];
    }
}