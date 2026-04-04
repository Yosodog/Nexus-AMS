<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationSignIn;
use App\Models\Taxes;
use App\Models\TradePrice;
use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\PWHelperService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CACHE_KEY = 'admin:dashboard:metrics';

    private const CACHE_TTL_MINUTES = 30;

    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * Render the alliance command center with aggregated telemetry and trend analysis.
     */
    public function dashboard(Request $request): View
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY);
        }

        $payload = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () {
                $metrics = $this->buildMetrics();

                return [
                    'generated_at' => Carbon::now()->toIso8601String(),
                    'metrics' => $metrics,
                ];
            }
        );

        $generatedAt = isset($payload['generated_at'])
            ? Carbon::parse($payload['generated_at'])
            : Carbon::now();

        $metrics = $this->normalizeMetricsForView($payload['metrics'] ?? $payload);

        return view('admin.dashboard', array_merge($metrics, [
            'lastRefreshedAt' => $generatedAt,
            'cacheTtlMinutes' => self::CACHE_TTL_MINUTES,
        ]));
    }

    /**
     * Build and normalize all dashboard metrics. Result is cached by dashboard().
     */
    private function buildMetrics(): array
    {
        $now = Carbon::now();

        $memberAllianceIds = $this->membershipService->getAllianceIds()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $memberAllianceIdList = $memberAllianceIds->all();

        $memberNationIds = $this->constrainedNationQuery($memberAllianceIdList)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $memberNationIdCollection = collect($memberNationIds);
        $totalMembers = $memberNationIdCollection->count();

        $memberNationQuery = function () use ($memberAllianceIdList) {
            return $this->constrainedNationQuery($memberAllianceIdList);
        };

        $totalCities = (int) ($totalMembers > 0 ? $memberNationQuery()->sum('num_cities') : 0);
        $avgScore = $totalMembers > 0 ? round((float) $memberNationQuery()->avg('score'), 2) : 0.0;
        $avgCitiesPerMember = $totalMembers > 0 ? round($totalCities / $totalMembers, 2) : 0.0;

        $cityStatsBase = City::query()->whereIn('nation_id', $memberNationIds);

        $cityStats = (clone $cityStatsBase)
            ->selectRaw('COALESCE(SUM(infrastructure), 0) as infrastructure_total, COALESCE(SUM(land), 0) as land_total')
            ->first();

        $totalInfrastructure = $cityStats ? (float) $cityStats->infrastructure_total : 0.0;
        $avgInfrastructure = $totalCities > 0 ? round($totalInfrastructure / $totalCities, 1) : 0.0;
        $poweredCities = (clone $cityStatsBase)->where('powered', true)->count();
        $powerCoverage = $totalCities > 0 ? round(($poweredCities / $totalCities) * 100, 1) : 0.0;

        $topInfrastructureCities = City::query()
            ->with('nation:id,leader_name,nation_name')
            ->whereIn('nation_id', $memberNationIds)
            ->orderByDesc('infrastructure')
            ->limit(5)
            ->get(['id', 'nation_id', 'name', 'infrastructure', 'land']);

        $latestTradePrice = TradePrice::query()->orderByDesc('date')->first();

        $latestSignInsSub = NationSignIn::query()
            ->selectRaw('MAX(id) as id, nation_id')
            ->whereIn('nation_id', $memberNationIds)
            ->groupBy('nation_id');

        $latestSignIns = NationSignIn::query()
            ->joinSub($latestSignInsSub, 'latest_sign_ins', fn ($join) => $join->on('nation_sign_ins.id', '=', 'latest_sign_ins.id'))
            ->join('nations', 'nations.id', '=', 'nation_sign_ins.nation_id')
            ->select([
                'nation_sign_ins.id',
                'nation_sign_ins.nation_id as nation_id',
                'nation_sign_ins.num_cities',
                'nation_sign_ins.score',
                'nation_sign_ins.mmr_score',
                'nation_sign_ins.money',
                'nation_sign_ins.coal',
                'nation_sign_ins.oil',
                'nation_sign_ins.uranium',
                'nation_sign_ins.iron',
                'nation_sign_ins.bauxite',
                'nation_sign_ins.lead',
                'nation_sign_ins.gasoline',
                'nation_sign_ins.munitions',
                'nation_sign_ins.steel',
                'nation_sign_ins.aluminum',
                'nation_sign_ins.food',
                'nation_sign_ins.created_at',
                'nations.leader_name',
                'nations.nation_name',
            ])
            ->get();

        $commodityFields = PWHelperService::resources(false);

        $cashTotal = (float) $latestSignIns->sum('money');
        $cashPerMember = $totalMembers > 0 ? $cashTotal / $totalMembers : 0.0;

        $resourceTotalsCollection = collect($commodityFields)
            ->mapWithKeys(fn ($field) => [$field => (float) $latestSignIns->sum($field)]);

        $resourceValuations = $resourceTotalsCollection->mapWithKeys(function (float $amount, string $resource) use ($latestTradePrice) {
            if ($latestTradePrice) {
                $price = (float) ($latestTradePrice->$resource ?? 0);

                if ($price > 0) {
                    return [$resource => $amount * $price];
                }
            }

            return [$resource => $amount];
        });

        $resourceTotalValue = round($resourceValuations->sum(), 2);

        $resourceValueBreakdown = $resourceValuations
            ->filter(fn ($value) => $value > 0)
            ->sortDesc();

        if ($resourceValueBreakdown->count() > 5) {
            $topResources = $resourceValueBreakdown->take(5);
            $otherValue = $resourceValueBreakdown->slice(5)->sum();

            if ($otherValue > 0) {
                $resourceValueBreakdown = $topResources->put('Other', $otherValue);
            } else {
                $resourceValueBreakdown = $topResources;
            }
        }

        $resourceTotals = $resourceTotalsCollection
            ->map(fn ($value) => (float) $value)
            ->all();

        $militaryTotalsRecord = NationMilitary::query()
            ->join('nations', 'nations.id', '=', 'nation_military.nation_id')
            ->whereIn('nations.id', $memberNationIds)
            ->selectRaw('
                COALESCE(SUM(soldiers), 0) AS soldiers,
                COALESCE(SUM(tanks), 0) AS tanks,
                COALESCE(SUM(aircraft), 0) AS aircraft,
                COALESCE(SUM(ships), 0) AS ships,
                COALESCE(SUM(spies), 0) AS spies
            ')
            ->first();

        $militaryTotalsCollection = collect([
            'soldiers' => (int) ($militaryTotalsRecord->soldiers ?? 0),
            'tanks' => (int) ($militaryTotalsRecord->tanks ?? 0),
            'aircraft' => (int) ($militaryTotalsRecord->aircraft ?? 0),
            'ships' => (int) ($militaryTotalsRecord->ships ?? 0),
            'spies' => (int) ($militaryTotalsRecord->spies ?? 0),
        ]);

        $militaryCapacity = [
            'soldiers' => $totalCities * 15000,
            'tanks' => $totalCities * 1250,
            'aircraft' => $totalCities * 75,
            'ships' => $totalCities * 15,
            'spies' => $totalMembers * 60,
        ];

        $militaryReadiness = collect($militaryCapacity)->mapWithKeys(function (int $capacity, string $field) use ($militaryTotalsCollection) {
            if ($capacity <= 0) {
                return [$field => 0.0];
            }

            $percentage = ($militaryTotalsCollection[$field] ?? 0) / $capacity * 100;

            return [$field => round(min(100, $percentage), 1)];
        });

        $militaryPerUnitAverage = [
            'soldiers' => $totalCities > 0 ? round($militaryTotalsCollection['soldiers'] / $totalCities) : 0,
            'tanks' => $totalCities > 0 ? round($militaryTotalsCollection['tanks'] / $totalCities) : 0,
            'aircraft' => $totalCities > 0 ? round($militaryTotalsCollection['aircraft'] / $totalCities, 1) : 0.0,
            'ships' => $totalCities > 0 ? round($militaryTotalsCollection['ships'] / $totalCities, 2) : 0.0,
            'spies' => $totalMembers > 0 ? round($militaryTotalsCollection['spies'] / $totalMembers, 1) : 0.0,
        ];

        $militaryTotals = $militaryTotalsCollection->all();

        $mmrThreshold = 85;
        $mmrSignIns = $latestSignIns->filter(fn ($signIn) => $signIn->mmr_score !== null);
        $averageMmrScore = $mmrSignIns->isNotEmpty() ? round((float) $mmrSignIns->avg('mmr_score'), 1) : 0.0;
        $mmrCompliantCount = $mmrSignIns->where('mmr_score', '>=', $mmrThreshold)->count();
        $mmrCoverage = $totalMembers > 0 ? round(($mmrCompliantCount / $totalMembers) * 100, 1) : 0.0;

        $mmrDistribution = $mmrSignIns
            ->groupBy(fn ($signIn) => (int) floor($signIn->mmr_score / 10) * 10)
            ->map(fn ($group, $bucket) => [
                'bucket' => (int) $bucket,
                'total' => $group->count(),
            ])
            ->sortBy('bucket')
            ->values();

        $chartWindowStart = $now->copy()->subDays(13)->startOfDay();
        $chartWindowStartDay = $chartWindowStart->toDateString();

        $thisWeekStart = $now->copy()->subDays(6)->startOfDay();
        $lastWeekStart = $now->copy()->subDays(13)->startOfDay();
        $lastWeekEnd = $now->copy()->subDays(7)->endOfDay();

        $activeThreshold = $now->copy()->subHours(48);
        $previousThreshold = $now->copy()->subHours(96);

        $activeMembers = $memberNationQuery()
            ->where('updated_at', '>=', $activeThreshold)
            ->count();

        $previousActiveMembers = $memberNationQuery()
            ->whereBetween('updated_at', [$previousThreshold, $activeThreshold])
            ->count();

        $activeMemberTrend = $previousActiveMembers > 0
            ? round((($activeMembers - $previousActiveMembers) / $previousActiveMembers) * 100, 1)
            : null;

        $taxBaseQuery = Taxes::query()
            ->where('day', '>=', $chartWindowStartDay)
            ->whereIn('receiver_id', $memberAllianceIdList);

        $taxMoneyDaily = (clone $taxBaseQuery)
            ->selectRaw('day, SUM(money) AS money')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => Carbon::parse($row->day)->format('M d'),
                'money' => (float) $row->money,
            ])
            ->values();

        $taxResourceDaily = (clone $taxBaseQuery)
            ->selectRaw('day,
                SUM(steel) AS steel,
                SUM(munitions) AS munitions,
                SUM(aluminum) AS aluminum,
                SUM(food) AS food')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => Carbon::parse($row->day)->format('M d'),
                'steel' => (float) $row->steel,
                'munitions' => (float) $row->munitions,
                'aluminum' => (float) $row->aluminum,
                'food' => (float) $row->food,
            ])
            ->values();

        $taxMoneyThisWeek = (float) Taxes::query()
            ->where('date', '>=', $thisWeekStart)
            ->whereIn('receiver_id', $memberAllianceIdList)
            ->sum('money');

        $taxMoneyLastWeek = (float) Taxes::query()
            ->whereBetween('date', [$lastWeekStart, $lastWeekEnd])
            ->whereIn('receiver_id', $memberAllianceIdList)
            ->sum('money');

        $taxMoneyTrend = $taxMoneyLastWeek > 0 ? round((($taxMoneyThisWeek - $taxMoneyLastWeek) / $taxMoneyLastWeek) * 100, 1) : null;

        $warBaseQuery = War::query()
            ->where('date', '>=', $chartWindowStart)
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            });

        $warDaily = (clone $warBaseQuery)
            ->selectRaw('DATE(date) AS day,
                COUNT(*) AS wars_started,
                SUM(att_infra_destroyed) AS att_infra,
                SUM(def_infra_destroyed) AS def_infra,
                SUM(att_money_looted) AS att_money,
                SUM(def_money_looted) AS def_money')
            ->groupBy(DB::raw('DATE(date)'))
            ->orderBy(DB::raw('DATE(date)'))
            ->get()
            ->map(fn ($row) => [
                'day' => Carbon::parse($row->day)->format('M d'),
                'wars_started' => (int) $row->wars_started,
                'infra_destroyed' => (float) ($row->att_infra + $row->def_infra),
                'money_looted' => (float) ($row->att_money + $row->def_money),
            ])
            ->values();

        $activeWars = War::query()
            ->active()
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            })
            ->count();

        $warsThisWeek = War::query()
            ->where('date', '>=', $thisWeekStart)
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            })
            ->count();

        $warsLastWeek = War::query()
            ->whereBetween('date', [$lastWeekStart, $lastWeekEnd])
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            })
            ->count();

        $warTrend = $warsLastWeek > 0 ? round((($warsThisWeek - $warsLastWeek) / $warsLastWeek) * 100, 1) : null;

        $activeWarDetails = War::query()
            ->active()
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            })
            ->with([
                'attacker:id,leader_name,nation_name',
                'defender:id,leader_name,nation_name',
            ])
            ->orderByRaw('LEAST(turns_left, att_resistance, def_resistance) asc')
            ->orderBy('updated_at')
            ->limit(5)
            ->get([
                'id',
                'att_id',
                'def_id',
                'war_type',
                'turns_left',
                'att_points',
                'def_points',
                'att_resistance',
                'def_resistance',
                'att_money_looted',
                'def_money_looted',
                'att_infra_destroyed',
                'def_infra_destroyed',
                'att_missiles_used',
                'def_missiles_used',
                'att_nukes_used',
                'def_nukes_used',
                'att_fortify',
                'def_fortify',
                'updated_at',
            ]);

        $recentWars = War::query()
            ->where(function ($query) use ($memberAllianceIdList) {
                $query->whereIn('att_alliance_id', $memberAllianceIdList)
                    ->orWhereIn('def_alliance_id', $memberAllianceIdList);
            })
            ->with([
                'attacker:id,leader_name,nation_name',
                'defender:id,leader_name,nation_name',
            ])
            ->orderByDesc('date')
            ->limit(30)
            ->get([
                'id',
                'date',
                'war_type',
                'att_id',
                'def_id',
                'winner_id',
                'att_infra_destroyed',
                'def_infra_destroyed',
                'att_money_looted',
                'def_money_looted',
            ]);

        $loanAvgInterest = Loan::query()
            ->whereIn('nation_id', $memberNationIds)
            ->whereNotNull('interest_rate')
            ->avg('interest_rate');

        $loanAvgTerm = Loan::query()
            ->whereIn('nation_id', $memberNationIds)
            ->whereNotNull('term_weeks')
            ->avg('term_weeks');

        $loanStats = [
            'pending' => Loan::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'pending')
                ->count(),
            'active' => Loan::query()
                ->whereIn('nation_id', $memberNationIds)
                ->whereIn('status', ['approved', 'missed'])
                ->count(),
            'paid' => Loan::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'paid')
                ->count(),
            'outstanding_balance' => (float) Loan::query()
                ->whereIn('nation_id', $memberNationIds)
                ->whereIn('status', ['approved', 'missed'])
                ->sum('remaining_balance'),
            'avg_interest' => $loanAvgInterest !== null ? round((float) $loanAvgInterest, 2) : null,
            'avg_term' => $loanAvgTerm !== null ? round((float) $loanAvgTerm, 1) : null,
        ];

        $grantStats = [
            'pending' => GrantApplication::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'pending')
                ->count(),
            'approved_this_week' => GrantApplication::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'approved')
                ->where('approved_at', '>=', $thisWeekStart)
                ->count(),
            'approved_total' => GrantApplication::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'approved')
                ->count(),
            'money_disbursed_30d' => (float) GrantApplication::query()
                ->whereIn('nation_id', $memberNationIds)
                ->where('status', 'approved')
                ->where('approved_at', '>=', $now->copy()->subDays(30))
                ->sum('money'),
        ];

        $topCashHolders = $latestSignIns
            ->sortByDesc('money')
            ->take(6)
            ->map(fn ($signIn) => (object) [
                'nation_id' => $signIn->nation_id,
                'leader_name' => $signIn->leader_name,
                'nation_name' => $signIn->nation_name,
                'money' => (float) $signIn->money,
                'snapshot_at' => $signIn->created_at,
            ]);

        $topScoringNations = $memberNationQuery()
            ->orderByDesc('score')
            ->limit(8)
            ->get([
                'id',
                'leader_name',
                'nation_name',
                'score',
                'num_cities',
                'gross_national_income',
                'gross_domestic_product',
            ]);

        $kpis = [
            [
                'icon' => 'bi bi-people-fill',
                'bg' => 'text-bg-primary',
                'title' => 'Active Members',
                'value' => number_format($activeMembers),
                'helper' => 'Seen in the last 48 hours',
                'trend' => $activeMemberTrend,
            ],
            [
                'icon' => 'bi bi-graph-up-arrow',
                'bg' => 'text-bg-success',
                'title' => 'Alliance Score',
                'value' => number_format($avgScore, 1).' avg',
                'helper' => "{$avgCitiesPerMember} cities per member",
                'trend' => null,
            ],
            [
                'icon' => 'bi bi-shield-lock',
                'bg' => 'text-bg-info',
                'title' => 'MMR Compliance',
                'value' => "{$mmrCoverage}%",
                'helper' => "{$mmrCompliantCount} nations ≥ {$mmrThreshold}",
                'trend' => null,
            ],
            [
                'icon' => 'bi bi-lightning-charge',
                'bg' => 'text-bg-warning',
                'title' => 'Power Coverage',
                'value' => "{$powerCoverage}%",
                'helper' => "{$poweredCities} of {$totalCities} cities online",
                'trend' => null,
            ],
            [
                'icon' => 'bi bi-cash-stack',
                'bg' => 'text-bg-dark',
                'title' => 'Alliance Cash',
                'value' => '$'.number_format($cashTotal, 0),
                'helper' => $totalMembers > 0 ? '$'.number_format($cashPerMember, 0).' per member' : 'No members',
                'trend' => null,
            ],
            [
                'icon' => 'bi bi-exclamation-octagon',
                'bg' => 'text-bg-danger',
                'title' => 'Active Wars',
                'value' => number_format($activeWars),
                'helper' => "{$warsThisWeek} wars started last 7 days",
                'trend' => $warTrend,
            ],
        ];

        return [
            'kpis' => $kpis,
            'totalMembers' => (int) $totalMembers,
            'totalCities' => (int) $totalCities,
            'poweredCities' => (int) $poweredCities,
            'powerCoverage' => (float) $powerCoverage,
            'totalInfrastructure' => (float) $totalInfrastructure,
            'avgInfrastructure' => (float) $avgInfrastructure,
            'topInfrastructureCities' => $topInfrastructureCities
                ->map(fn ($city) => [
                    'id' => (int) $city->id,
                    'name' => (string) $city->name,
                    'infrastructure' => (float) $city->infrastructure,
                    'land' => (float) $city->land,
                    'nation' => $city->nation ? [
                        'id' => (int) $city->nation->id,
                        'leader_name' => (string) $city->nation->leader_name,
                        'nation_name' => (string) $city->nation->nation_name,
                    ] : null,
                ])
                ->all(),
            'cashTotal' => (float) $cashTotal,
            'cashPerMember' => (float) $cashPerMember,
            'resourceTotals' => $resourceTotals,
            'resourceTotalValue' => (float) $resourceTotalValue,
            'resourceValueBreakdown' => collect($resourceValueBreakdown)
                ->map(fn ($value) => (float) $value)
                ->all(),
            'latestTradePriceDate' => $latestTradePrice?->date?->format('M d, Y'),
            'militaryTotals' => collect($militaryTotals)->map(fn ($value) => (int) $value)->all(),
            'militaryReadiness' => $militaryReadiness->all(),
            'militaryCapacity' => collect($militaryCapacity)->map(fn ($value) => (int) $value)->all(),
            'militaryPerUnitAverage' => collect($militaryPerUnitAverage)->map(fn ($value) => (float) $value)->all(),
            'averageMmrScore' => (float) $averageMmrScore,
            'mmrThreshold' => (int) $mmrThreshold,
            'mmrCompliantCount' => (int) $mmrCompliantCount,
            'mmrCoverage' => (float) $mmrCoverage,
            'mmrDistribution' => $mmrDistribution,
            'taxMoneyDaily' => $taxMoneyDaily,
            'taxResourceDaily' => $taxResourceDaily,
            'taxMoneyThisWeek' => (float) $taxMoneyThisWeek,
            'taxMoneyTrend' => $taxMoneyTrend,
            'warDaily' => $warDaily,
            'warsThisWeek' => (int) $warsThisWeek,
            'warTrend' => $warTrend,
            'activeWars' => (int) $activeWars,
            'loanStats' => [
                'pending' => (int) $loanStats['pending'],
                'active' => (int) $loanStats['active'],
                'paid' => (int) $loanStats['paid'],
                'outstanding_balance' => (float) $loanStats['outstanding_balance'],
                'avg_interest' => $loanStats['avg_interest'] !== null ? (float) $loanStats['avg_interest'] : null,
                'avg_term' => $loanStats['avg_term'] !== null ? (float) $loanStats['avg_term'] : null,
            ],
            'grantStats' => [
                'pending' => (int) $grantStats['pending'],
                'approved_this_week' => (int) $grantStats['approved_this_week'],
                'approved_total' => (int) $grantStats['approved_total'],
                'money_disbursed_30d' => (float) $grantStats['money_disbursed_30d'],
            ],
            'topCashHolders' => $topCashHolders
                ->map(fn ($holder) => [
                    'nation_id' => (int) $holder->nation_id,
                    'leader_name' => (string) $holder->leader_name,
                    'nation_name' => (string) $holder->nation_name,
                    'money' => (float) $holder->money,
                    'snapshot_at' => $this->serializeDate($holder->snapshot_at),
                ])
                ->values()
                ->all(),
            'topScoringNations' => $topScoringNations
                ->map(fn ($nation) => [
                    'id' => (int) $nation->id,
                    'leader_name' => (string) $nation->leader_name,
                    'nation_name' => (string) $nation->nation_name,
                    'score' => (float) $nation->score,
                    'num_cities' => (int) $nation->num_cities,
                ])
                ->values()
                ->all(),
            'activeWarDetails' => $activeWarDetails
                ->map(fn ($war) => [
                    'id' => (int) $war->id,
                    'att_id' => (int) $war->att_id,
                    'def_id' => (int) $war->def_id,
                    'war_type' => (string) $war->war_type,
                    'turns_left' => (int) $war->turns_left,
                    'att_points' => (int) $war->att_points,
                    'def_points' => (int) $war->def_points,
                    'att_resistance' => (int) $war->att_resistance,
                    'def_resistance' => (int) $war->def_resistance,
                    'att_money_looted' => (float) $war->att_money_looted,
                    'def_money_looted' => (float) $war->def_money_looted,
                    'att_infra_destroyed' => (float) $war->att_infra_destroyed,
                    'def_infra_destroyed' => (float) $war->def_infra_destroyed,
                    'att_fortify' => (bool) $war->att_fortify,
                    'def_fortify' => (bool) $war->def_fortify,
                    'attacker' => $war->attacker ? [
                        'id' => (int) $war->attacker->id,
                        'leader_name' => (string) $war->attacker->leader_name,
                    ] : null,
                    'defender' => $war->defender ? [
                        'id' => (int) $war->defender->id,
                        'leader_name' => (string) $war->defender->leader_name,
                    ] : null,
                ])
                ->values()
                ->all(),
            'recentWars' => $recentWars
                ->map(fn ($war) => [
                    'id' => (int) $war->id,
                    'date' => $this->serializeDate($war->date),
                    'att_id' => (int) $war->att_id,
                    'def_id' => (int) $war->def_id,
                    'winner_id' => $war->winner_id !== null ? (int) $war->winner_id : null,
                    'att_infra_destroyed' => (float) $war->att_infra_destroyed,
                    'def_infra_destroyed' => (float) $war->def_infra_destroyed,
                    'att_money_looted' => (float) $war->att_money_looted,
                    'def_money_looted' => (float) $war->def_money_looted,
                    'attacker' => $war->attacker ? [
                        'id' => (int) $war->attacker->id,
                        'leader_name' => (string) $war->attacker->leader_name,
                    ] : null,
                    'defender' => $war->defender ? [
                        'id' => (int) $war->defender->id,
                        'leader_name' => (string) $war->defender->leader_name,
                    ] : null,
                ])
                ->values()
                ->all(),
            'memberNationIds' => $memberNationIds,
        ];
    }

    /**
     * Normalize cached dashboard payloads so first render and refresh share the same shape.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function normalizeMetricsForView(array $metrics): array
    {
        $metrics['topInfrastructureCities'] = $this->objectCollection(
            $metrics['topInfrastructureCities'] ?? [],
            fn (array $city): object => (object) [
                'id' => (int) ($city['id'] ?? 0),
                'name' => (string) ($city['name'] ?? ''),
                'infrastructure' => (float) ($city['infrastructure'] ?? 0),
                'land' => (float) ($city['land'] ?? 0),
                'nation' => isset($city['nation']) && is_array($city['nation'])
                    ? (object) [
                        'id' => (int) ($city['nation']['id'] ?? 0),
                        'leader_name' => (string) ($city['nation']['leader_name'] ?? ''),
                        'nation_name' => (string) ($city['nation']['nation_name'] ?? ''),
                    ]
                    : null,
            ]
        );

        $metrics['topCashHolders'] = $this->objectCollection(
            $metrics['topCashHolders'] ?? [],
            fn (array $holder): object => (object) [
                'nation_id' => (int) ($holder['nation_id'] ?? 0),
                'leader_name' => (string) ($holder['leader_name'] ?? ''),
                'nation_name' => (string) ($holder['nation_name'] ?? ''),
                'money' => (float) ($holder['money'] ?? 0),
                'snapshot_at' => ! empty($holder['snapshot_at']) ? Carbon::parse($holder['snapshot_at']) : null,
            ]
        );

        $metrics['topScoringNations'] = $this->objectCollection(
            $metrics['topScoringNations'] ?? [],
            fn (array $nation): object => (object) [
                'id' => (int) ($nation['id'] ?? 0),
                'leader_name' => (string) ($nation['leader_name'] ?? ''),
                'nation_name' => (string) ($nation['nation_name'] ?? ''),
                'score' => (float) ($nation['score'] ?? 0),
                'num_cities' => (int) ($nation['num_cities'] ?? 0),
            ]
        );

        $metrics['activeWarDetails'] = $this->objectCollection(
            $metrics['activeWarDetails'] ?? [],
            fn (array $war): object => (object) [
                'id' => (int) ($war['id'] ?? 0),
                'att_id' => (int) ($war['att_id'] ?? 0),
                'def_id' => (int) ($war['def_id'] ?? 0),
                'war_type' => (string) ($war['war_type'] ?? ''),
                'turns_left' => (int) ($war['turns_left'] ?? 0),
                'att_points' => (int) ($war['att_points'] ?? 0),
                'def_points' => (int) ($war['def_points'] ?? 0),
                'att_resistance' => (int) ($war['att_resistance'] ?? 0),
                'def_resistance' => (int) ($war['def_resistance'] ?? 0),
                'att_money_looted' => (float) ($war['att_money_looted'] ?? 0),
                'def_money_looted' => (float) ($war['def_money_looted'] ?? 0),
                'att_infra_destroyed' => (float) ($war['att_infra_destroyed'] ?? 0),
                'def_infra_destroyed' => (float) ($war['def_infra_destroyed'] ?? 0),
                'att_fortify' => (bool) ($war['att_fortify'] ?? false),
                'def_fortify' => (bool) ($war['def_fortify'] ?? false),
                'attacker' => isset($war['attacker']) && is_array($war['attacker'])
                    ? (object) [
                        'id' => (int) ($war['attacker']['id'] ?? 0),
                        'leader_name' => (string) ($war['attacker']['leader_name'] ?? ''),
                    ]
                    : null,
                'defender' => isset($war['defender']) && is_array($war['defender'])
                    ? (object) [
                        'id' => (int) ($war['defender']['id'] ?? 0),
                        'leader_name' => (string) ($war['defender']['leader_name'] ?? ''),
                    ]
                    : null,
            ]
        );

        $metrics['recentWars'] = $this->objectCollection(
            $metrics['recentWars'] ?? [],
            fn (array $war): object => (object) [
                'id' => (int) ($war['id'] ?? 0),
                'date' => ! empty($war['date']) ? Carbon::parse($war['date']) : null,
                'att_id' => (int) ($war['att_id'] ?? 0),
                'def_id' => (int) ($war['def_id'] ?? 0),
                'winner_id' => isset($war['winner_id']) ? (int) $war['winner_id'] : null,
                'att_infra_destroyed' => (float) ($war['att_infra_destroyed'] ?? 0),
                'def_infra_destroyed' => (float) ($war['def_infra_destroyed'] ?? 0),
                'att_money_looted' => (float) ($war['att_money_looted'] ?? 0),
                'def_money_looted' => (float) ($war['def_money_looted'] ?? 0),
                'attacker' => isset($war['attacker']) && is_array($war['attacker'])
                    ? (object) [
                        'id' => (int) ($war['attacker']['id'] ?? 0),
                        'leader_name' => (string) ($war['attacker']['leader_name'] ?? ''),
                    ]
                    : null,
                'defender' => isset($war['defender']) && is_array($war['defender'])
                    ? (object) [
                        'id' => (int) ($war['defender']['id'] ?? 0),
                        'leader_name' => (string) ($war['defender']['leader_name'] ?? ''),
                    ]
                    : null,
            ]
        );

        return $metrics;
    }

    /**
     * @param  iterable<mixed>  $items
     * @param  callable(array<string, mixed>): object  $mapper
     * @return Collection<int, object>
     */
    private function objectCollection(iterable $items, callable $mapper): Collection
    {
        return collect($items)
            ->map(function ($item) use ($mapper) {
                if (is_object($item)) {
                    return $item;
                }

                return $mapper(is_array($item) ? $item : []);
            })
            ->values();
    }

    private function serializeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Base query for active alliance members excluding applicants and nations in vacation mode.
     */
    private function constrainedNationQuery(array $allianceIds)
    {
        return Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function ($query) {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            });
    }
}
