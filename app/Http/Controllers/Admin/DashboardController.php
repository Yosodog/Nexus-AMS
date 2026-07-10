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
use App\Models\User;
use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\PWHelperService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            fn (): array => $this->buildPayload()
        );

        if ($this->containsIncompleteObject($payload)) {
            Cache::forget(self::CACHE_KEY);

            $payload = Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                fn (): array => $this->buildPayload()
            );
        }

        $generatedAt = isset($payload['generated_at'])
            ? Carbon::parse($payload['generated_at'])
            : Carbon::now();

        /** @var User $user */
        $user = $request->user();
        $metrics = $this->filterMetricsForView($payload['metrics'] ?? $payload, $user);
        $metrics = $this->normalizeMetricsForView($metrics);

        return view('admin.dashboard', array_merge($metrics, [
            'lastRefreshedAt' => $generatedAt,
            'cacheTtlMinutes' => self::CACHE_TTL_MINUTES,
        ]));
    }

    /**
     * Build the cached dashboard payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'metrics' => $this->buildMetrics(),
        ];
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

        $totalMembers = count($memberNationIds);
        $totalCities = (int) ($totalMembers > 0
            ? $this->constrainedNationQuery($memberAllianceIdList)->sum('num_cities')
            : 0);

        $cityStatsBase = City::query()->whereIn('nation_id', $memberNationIds);

        $cityStats = (clone $cityStatsBase)
            ->selectRaw('COALESCE(SUM(infrastructure), 0) as infrastructure_total, COALESCE(SUM(land), 0) as land_total')
            ->first();

        $totalInfrastructure = $cityStats ? (float) $cityStats->infrastructure_total : 0.0;
        $avgInfrastructure = $totalCities > 0 ? round($totalInfrastructure / $totalCities, 1) : 0.0;
        $poweredCities = (clone $cityStatsBase)->where('powered', true)->count();
        $powerCoverage = $totalCities > 0 ? round(($poweredCities / $totalCities) * 100, 1) : 0.0;

        $latestTradePrice = TradePrice::query()->orderByDesc('date')->first();

        $latestSignInsSub = NationSignIn::query()
            ->selectRaw('MAX(id) as id, nation_id')
            ->whereIn('nation_id', $memberNationIds)
            ->groupBy('nation_id');

        $latestSignIns = NationSignIn::query()
            ->joinSub($latestSignInsSub, 'latest_sign_ins', fn ($join) => $join->on('nation_sign_ins.id', '=', 'latest_sign_ins.id'))
            ->select([
                'nation_sign_ins.id',
                'nation_sign_ins.nation_id as nation_id',
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

        $militaryTotals = $militaryTotalsCollection->all();

        $mmrThreshold = 85;
        $mmrSignIns = $latestSignIns->filter(fn ($signIn) => $signIn->mmr_score !== null);
        $mmrCompliantCount = $mmrSignIns->where('mmr_score', '>=', $mmrThreshold)->count();
        $mmrCoverage = $totalMembers > 0 ? round(($mmrCompliantCount / $totalMembers) * 100, 1) : 0.0;

        $thisWeekStart = $now->copy()->subDays(6)->startOfDay();
        $lastWeekStart = $now->copy()->subDays(13)->startOfDay();
        $lastWeekEnd = $now->copy()->subDays(7)->endOfDay();

        $taxMoneyThisWeek = (float) Taxes::query()
            ->where('date', '>=', $thisWeekStart)
            ->whereIn('receiver_id', $memberAllianceIdList)
            ->sum('money');

        $taxMoneyLastWeek = (float) Taxes::query()
            ->whereBetween('date', [$lastWeekStart, $lastWeekEnd])
            ->whereIn('receiver_id', $memberAllianceIdList)
            ->sum('money');

        $taxMoneyTrend = $taxMoneyLastWeek > 0 ? round((($taxMoneyThisWeek - $taxMoneyLastWeek) / $taxMoneyLastWeek) * 100, 1) : null;

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
            ->orderByRaw(<<<'SQL'
                CASE
                    WHEN turns_left <= att_resistance AND turns_left <= def_resistance THEN turns_left
                    WHEN att_resistance <= def_resistance THEN att_resistance
                    ELSE def_resistance
                END asc
                SQL)
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

        return [
            'totalMembers' => (int) $totalMembers,
            'totalCities' => (int) $totalCities,
            'poweredCities' => (int) $poweredCities,
            'powerCoverage' => (float) $powerCoverage,
            'totalInfrastructure' => (float) $totalInfrastructure,
            'avgInfrastructure' => (float) $avgInfrastructure,
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
            'mmrThreshold' => (int) $mmrThreshold,
            'mmrCompliantCount' => (int) $mmrCompliantCount,
            'mmrCoverage' => (float) $mmrCoverage,
            'taxMoneyThisWeek' => (float) $taxMoneyThisWeek,
            'taxMoneyTrend' => $taxMoneyTrend,
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
        $metrics['activeWarDetails'] = $this->objectList(
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

        return $metrics;
    }

    /**
     * Limit cached telemetry to the metric families the current staff member may view.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function filterMetricsForView(array $metrics, User $user): array
    {
        $defaults = [
            'totalMembers' => 0,
            'totalCities' => 0,
            'poweredCities' => 0,
            'powerCoverage' => 0.0,
            'totalInfrastructure' => 0.0,
            'avgInfrastructure' => 0.0,
            'cashTotal' => 0.0,
            'cashPerMember' => 0.0,
            'resourceTotals' => [],
            'resourceTotalValue' => 0.0,
            'resourceValueBreakdown' => [],
            'latestTradePriceDate' => null,
            'taxMoneyThisWeek' => 0.0,
            'taxMoneyTrend' => null,
            'militaryTotals' => [],
            'militaryReadiness' => [],
            'militaryCapacity' => [],
            'mmrThreshold' => 85,
            'mmrCompliantCount' => 0,
            'mmrCoverage' => 0.0,
            'warsThisWeek' => 0,
            'warTrend' => null,
            'activeWars' => 0,
            'activeWarDetails' => [],
            'memberNationIds' => [],
            'loanStats' => [
                'pending' => 0,
                'active' => 0,
                'paid' => 0,
                'outstanding_balance' => 0.0,
                'avg_interest' => null,
                'avg_term' => null,
            ],
            'grantStats' => [
                'pending' => 0,
                'approved_this_week' => 0,
                'approved_total' => 0,
                'money_disbursed_30d' => 0.0,
            ],
        ];

        $visibleKeys = [];

        if ($user->can('view-members')) {
            $visibleKeys = array_merge($visibleKeys, [
                'totalMembers',
                'totalCities',
                'poweredCities',
                'powerCoverage',
                'totalInfrastructure',
                'avgInfrastructure',
            ]);
        }

        if ($user->can('view-accounts') || $user->can('view-financial-reports')) {
            $visibleKeys = array_merge($visibleKeys, [
                'cashTotal',
                'cashPerMember',
                'resourceTotals',
                'resourceTotalValue',
                'resourceValueBreakdown',
                'latestTradePriceDate',
            ]);
        }

        if ($user->can('view-financial-reports')) {
            $visibleKeys = array_merge($visibleKeys, ['taxMoneyThisWeek', 'taxMoneyTrend']);
        }

        if ($user->can('view-loans')) {
            $visibleKeys[] = 'loanStats';
        }

        if ($user->can('view-grants')) {
            $visibleKeys[] = 'grantStats';
        }

        if ($user->can('view-mmr')) {
            $visibleKeys = array_merge($visibleKeys, [
                'totalMembers',
                'mmrThreshold',
                'mmrCompliantCount',
                'mmrCoverage',
            ]);
        }

        if ($user->can('view-mmr') || $user->can('view-wars')) {
            $visibleKeys = array_merge($visibleKeys, [
                'militaryTotals',
                'militaryReadiness',
                'militaryCapacity',
            ]);
        }

        if ($user->can('view-wars')) {
            $visibleKeys = array_merge($visibleKeys, [
                'warsThisWeek',
                'warTrend',
                'activeWars',
                'activeWarDetails',
                'memberNationIds',
            ]);
        }

        return array_replace(
            $defaults,
            collect($metrics)->only(array_unique($visibleKeys))->all(),
        );
    }

    /**
     * @param  iterable<mixed>|mixed  $items
     * @param  callable(array<string, mixed>): object  $mapper
     * @return array<int, object>
     */
    private function objectList(mixed $items, callable $mapper): array
    {
        return collect($this->normalizeIterable($items))
            ->map(function ($item) use ($mapper) {
                if (is_object($item) && get_class($item) !== '__PHP_Incomplete_Class') {
                    return $item;
                }

                return $mapper($this->normalizeItemArray($item));
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeIterable(mixed $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof \Traversable) {
            return iterator_to_array($items, false);
        }

        if (is_object($items) && get_class($items) === '__PHP_Incomplete_Class') {
            $normalized = $this->normalizeItemArray($items);
            $storedItems = $normalized["\0*\0items"] ?? $normalized["\0Illuminate\\Support\\Collection\0items"] ?? null;

            return is_array($storedItems) ? array_values($storedItems) : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeItemArray(mixed $item): array
    {
        if (is_array($item)) {
            return $item;
        }

        if (is_object($item)) {
            return array_map(
                fn ($value) => $value,
                get_object_vars($item)
            );
        }

        return [];
    }

    private function containsIncompleteObject(mixed $value): bool
    {
        if (is_object($value)) {
            if (get_class($value) === '__PHP_Incomplete_Class') {
                return true;
            }

            foreach (get_object_vars($value) as $property) {
                if ($this->containsIncompleteObject($property)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsIncompleteObject($item)) {
                    return true;
                }
            }
        }

        return false;
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
