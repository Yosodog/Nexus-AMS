<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use App\Models\War;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class WarStatsController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $currentUserNation = Auth::user()?->nation;
        $targetNationId = $request->integer('nation_id') ?: $currentUserNation?->id;

        if (! $targetNationId) {
            return redirect()->route('user.dashboard')->with([
                'alert-message' => 'We could not load your nation profile to build war stats.',
                'alert-type' => 'error',
            ]);
        }

        $targetNation = Nation::find($targetNationId);

        if (! $targetNation) {
            return redirect()->route('defense.war-stats')->with([
                'alert-message' => 'Nation not found. Please check the ID and try again.',
                'alert-type' => 'error',
            ]);
        }

        $from = $request->date('from') ? Carbon::parse($request->date('from'))->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $request->date('to') ? Carbon::parse($request->date('to'))->endOfDay() : now()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $cacheKey = sprintf('war_stats:%s:%s:%s', $targetNation->id, $from->toDateString(), $to->toDateString());

        $payload = Cache::remember($cacheKey, 300, function () use ($targetNation, $from, $to) {
            $wars = War::query()
                ->with(['attacker', 'defender'])
                ->whereBetween('date', [$from, $to])
                ->where(function ($query) use ($targetNation) {
                    $query->where('att_id', $targetNation->id)
                        ->orWhere('def_id', $targetNation->id);
                })
                ->orderByDesc('date')
                ->get();

            [$activeWars, $pastWars] = $wars->partition(fn (War $war) => $this->isActive($war));
            $completedWars = $wars->filter(fn (War $war) => $war->end_date !== null);

            $offensiveCount = $wars->where('att_id', $targetNation->id)->count();
            $defensiveCount = $wars->where('def_id', $targetNation->id)->count();

            $wins = $completedWars->filter(fn (War $war) => (int) $war->winner_id === $targetNation->id)->count();
            $losses = $completedWars->filter(fn (War $war) => $war->winner_id && (int) $war->winner_id !== $targetNation->id)->count();
            $draws = $completedWars->filter(fn (War $war) => ! $war->winner_id)->count();

            $unitExchange = $this->buildUnitExchange($wars, (int) $targetNation->id);
            $unitScoreExchange = $this->buildUnitScoreExchange($unitExchange);
            $resourceUsage = $this->buildResourceUsage($wars, (int) $targetNation->id);
            $infraExchange = $this->buildInfraExchange($wars, (int) $targetNation->id);
            $lootTotal = $wars->sum(fn (War $war) => $this->isAttacker($war, (int) $targetNation->id) ? $war->att_money_looted : $war->def_money_looted);
            $warTypeBreakdown = $wars->countBy('war_type')->toArray();
            $timeline = $this->buildImpactTimeline($wars, (int) $targetNation->id);
            $opponents = $this->buildOpponentStories($wars, (int) $targetNation->id);

            $avgDurationHours = $wars->avg(fn (War $war) => $this->warDurationHours($war));
            $missilesUsed = $wars->sum(fn (War $war) => $this->isAttacker($war, (int) $targetNation->id) ? $war->att_missiles_used : $war->def_missiles_used);
            $nukesUsed = $wars->sum(fn (War $war) => $this->isAttacker($war, (int) $targetNation->id) ? $war->att_nukes_used : $war->def_nukes_used);

            $recentWars = $wars->take(15);

            return [
                'activeWars' => $activeWars,
                'pastWars' => $pastWars,
                'offensiveCount' => $offensiveCount,
                'defensiveCount' => $defensiveCount,
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'unitExchange' => $unitExchange,
                'unitScoreExchange' => $unitScoreExchange,
                'resourceUsage' => $resourceUsage,
                'infraExchange' => $infraExchange,
                'lootTotal' => $lootTotal,
                'warTypeBreakdown' => $warTypeBreakdown,
                'timeline' => $timeline,
                'opponents' => $opponents,
                'avgDurationHours' => $avgDurationHours,
                'missilesUsed' => $missilesUsed,
                'nukesUsed' => $nukesUsed,
                'recentWars' => $recentWars,
            ];
        });

        return view('defense.war-stats', [
            ...$payload,
            'nation' => $targetNation,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'nation_id' => $targetNation->id,
                'is_self' => $targetNationId === $currentUserNation?->id,
            ],
        ]);
    }

    private function isAttacker(War $war, int $nationId): bool
    {
        return (int) $war->att_id === $nationId;
    }

    private function isActive(War $war): bool
    {
        return $war->end_date === null && (int) $war->turns_left > 0;
    }

    private function warDurationHours(War $war): float
    {
        $started = Carbon::parse($war->date);
        $ended = $war->end_date ? Carbon::parse($war->end_date) : now();

        return $started->diffInMinutes($ended) / 60;
    }

    private function buildUnitExchange(Collection $wars, int $nationId): array
    {
        $units = ['soldiers', 'tanks', 'aircraft', 'ships'];

        $exchange = [
            'inflicted' => array_fill_keys($units, 0),
            'lost' => array_fill_keys($units, 0),
        ];

        foreach ($wars as $war) {
            $isAttacker = $this->isAttacker($war, $nationId);

            $exchange['inflicted']['soldiers'] += $isAttacker ? $war->def_soldiers_lost : $war->att_soldiers_lost;
            $exchange['inflicted']['tanks'] += $isAttacker ? $war->def_tanks_lost : $war->att_tanks_lost;
            $exchange['inflicted']['aircraft'] += $isAttacker ? $war->def_aircraft_lost : $war->att_aircraft_lost;
            $exchange['inflicted']['ships'] += $isAttacker ? $war->def_ships_lost : $war->att_ships_lost;

            $exchange['lost']['soldiers'] += $isAttacker ? $war->att_soldiers_lost : $war->def_soldiers_lost;
            $exchange['lost']['tanks'] += $isAttacker ? $war->att_tanks_lost : $war->def_tanks_lost;
            $exchange['lost']['aircraft'] += $isAttacker ? $war->att_aircraft_lost : $war->def_aircraft_lost;
            $exchange['lost']['ships'] += $isAttacker ? $war->att_ships_lost : $war->def_ships_lost;
        }

        return $exchange;
    }

    private function buildUnitScoreExchange(array $unitExchange): array
    {
        $weights = [
            'soldiers' => 0.0004,
            'tanks' => 0.025,
            'aircraft' => 0.3,
            'ships' => 1,
        ];

        $score = [
            'inflicted' => [],
            'lost' => [],
        ];

        foreach ($weights as $unit => $weight) {
            $score['inflicted'][$unit] = round(($unitExchange['inflicted'][$unit] ?? 0) * $weight, 2);
            $score['lost'][$unit] = round(($unitExchange['lost'][$unit] ?? 0) * $weight, 2);
        }

        return $score;
    }

    private function buildResourceUsage(Collection $wars, int $nationId): array
    {
        $resources = [
            'gasoline' => ['att_gas_used', 'def_gas_used'],
            'munitions' => ['att_mun_used', 'def_mun_used'],
            'steel' => ['att_steel_used', 'def_steel_used'],
            'aluminum' => ['att_alum_used', 'def_alum_used'],
        ];

        $usage = array_fill_keys(array_keys($resources), 0);

        foreach ($wars as $war) {
            $isAttacker = $this->isAttacker($war, $nationId);

            foreach ($resources as $key => [$attKey, $defKey]) {
                $usage[$key] += $isAttacker ? $war->$attKey : $war->$defKey;
            }
        }

        return $usage;
    }

    private function buildInfraExchange(Collection $wars, int $nationId): array
    {
        $exchange = [
            'value' => ['inflicted' => 0, 'taken' => 0],
            'raw' => ['inflicted' => 0, 'taken' => 0],
        ];

        foreach ($wars as $war) {
            $isAttacker = $this->isAttacker($war, $nationId);

            $exchange['value']['inflicted'] += $isAttacker ? $war->att_infra_destroyed_value : $war->def_infra_destroyed_value;
            $exchange['value']['taken'] += $isAttacker ? $war->def_infra_destroyed_value : $war->att_infra_destroyed_value;

            $exchange['raw']['inflicted'] += $isAttacker ? $war->att_infra_destroyed : $war->def_infra_destroyed;
            $exchange['raw']['taken'] += $isAttacker ? $war->def_infra_destroyed : $war->att_infra_destroyed;
        }

        return $exchange;
    }

    private function buildImpactTimeline(Collection $wars, int $nationId): array
    {
        $grouped = $wars->sortBy('date')->groupBy(
            fn (War $war) => Carbon::parse($war->date)->format('M d')
        );

        $labels = [];
        $inflicted = [];
        $taken = [];

        foreach ($grouped as $date => $list) {
            $labels[] = $date;
            $inflicted[] = $list->sum(fn (War $war) => $this->isAttacker($war, $nationId)
                ? $war->att_infra_destroyed_value
                : $war->def_infra_destroyed_value);
            $taken[] = $list->sum(fn (War $war) => $this->isAttacker($war, $nationId)
                ? $war->def_infra_destroyed_value
                : $war->att_infra_destroyed_value);
        }

        return [
            'labels' => $labels,
            'inflicted' => $inflicted,
            'taken' => $taken,
        ];
    }

    private function buildOpponentStories(Collection $wars, int $nationId): array
    {
        return $wars->map(function (War $war) use ($nationId) {
            $isAttacker = $this->isAttacker($war, $nationId);
            $opponent = $isAttacker ? $war->defender : $war->attacker;

            if (! $opponent) {
                return null;
            }

            return [
                'id' => $opponent->id,
                'name' => $opponent->leader_name,
                'role' => $isAttacker ? 'Offense' : 'Defense',
                'result' => $war->winner_id ? ((int) $war->winner_id === $nationId ? 'Win' : 'Loss') : 'Active',
            ];
        })
            ->filter()
            ->groupBy('id')
            ->map(function (Collection $entries) {
                $first = $entries->first();

                return [
                    'name' => $first['name'],
                    'count' => $entries->count(),
                    'roles' => $entries->pluck('role')->countBy()->toArray(),
                    'results' => $entries->pluck('result')->countBy()->toArray(),
                ];
            })
            ->sortByDesc('count')
            ->take(6)
            ->values()
            ->toArray();
    }
}
