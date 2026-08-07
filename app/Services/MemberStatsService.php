<?php

namespace App\Services;

use App\Enums\InactivityAction;
use App\Models\Account;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\InactivityEvent;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\NationSignIn;
use App\Models\Taxes;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MemberStatsService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly NationProfitabilityService $nationProfitabilityService
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    public function getOverviewData(User $viewer): array
    {
        $canViewAccounts = $viewer->can('view-accounts');
        $canViewFinancialReports = $viewer->can('view-financial-reports');
        $canViewMilitary = $viewer->can('view-mmr') || $viewer->can('view-wars');

        $relationships = ['accountProfile:nation_id,last_active'];

        if ($canViewAccounts) {
            $relationships[] = 'resources:nation_id,money,steel,gasoline,aluminum,munitions,uranium,food';
        }

        if ($canViewMilitary) {
            $relationships[] = 'military:nation_id,soldiers,tanks,aircraft,ships,spies';
        }

        $nations = Nation::query()
            ->select(['id', 'leader_name', 'nation_name', 'score', 'num_cities', 'update_tz'])
            ->with($relationships)
            ->whereIn('alliance_id', $this->membershipService->getAllianceIds())
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0)
            ->get();

        $accountTotals = collect();

        if ($canViewAccounts) {
            $accountTotals = Account::query()
                ->selectRaw('nation_id, SUM(money) as money, SUM(steel) as steel, SUM(gasoline) as gasoline, SUM(aluminum) as aluminum, SUM(munitions) as munitions, SUM(uranium) as uranium, SUM(food) as food')
                ->whereIn('nation_id', $nations->pluck('id'))
                ->groupBy('nation_id')
                ->get()
                ->keyBy('nation_id');
        }

        $openEvents = InactivityEvent::query()
            ->select([
                'nation_id',
                'episode_started_at',
                'last_notified_at',
                'dd_autoenrolled_at',
                'dd_opted_out_at',
            ])
            ->whereNull('episode_ended_at')
            ->get()
            ->keyBy('nation_id');

        $maxTier = $nations->max('num_cities') ?? 0;
        $cityCountsByTier = $nations->countBy('num_cities');
        $profitabilityLeaderboard = $canViewFinancialReports
            ? $this->nationProfitabilityService->getLeaderboard()
            : ['rows' => [], 'radiation_snapshot_at' => null];
        $profitabilityByNation = collect($profitabilityLeaderboard['rows'] ?? [])->keyBy('nation_id');

        $cityTiers = collect(range(1, $maxTier))->mapWithKeys(fn ($tier) => [
            $tier => (int) ($cityCountsByTier[$tier] ?? 0),
        ])->toArray();

        return [
            'totalMembers' => $nations->count(),
            'avgScore' => round($nations->avg('score'), 2),
            'totalCities' => $nations->sum('num_cities'),
            'cityTiers' => $cityTiers,
            'cityGrowthHistory' => $this->getCityGrowthHistory(),
            'members' => $nations->map(fn ($nation) => $this->formatNation(
                $nation,
                $openEvents->get($nation->id),
                $accountTotals->get($nation->id),
                $profitabilityByNation->get($nation->id)
            )),
            'profitabilityLeaderboard' => $profitabilityLeaderboard,
            'canViewAccounts' => $canViewAccounts,
            'canViewFinancialReports' => $canViewFinancialReports,
            'canViewMilitary' => $canViewMilitary,
            'inactivitySettings' => [
                'enabled' => SettingService::isInactivityModeEnabled(),
                'threshold_hours' => SettingService::getInactivityThresholdHours(),
                'cooldown_hours' => SettingService::getInactivityCooldownHours(),
                'actions' => SettingService::getInactivityActions(),
                'discord_channel_id' => SettingService::getInactivityDiscordChannelId(),
            ],
            'inactivityActionOptions' => collect(InactivityAction::cases())
                ->map(fn (InactivityAction $action) => [
                    'value' => $action->value,
                    'label' => $action->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function getCityGrowthHistory(): array
    {
        return NationSignIn::selectRaw('sign_in_day as date, SUM(num_cities) as total_cities')
            ->where('sign_in_day', '>=', now()->subDays(30)->toDateString())
            ->groupBy('sign_in_day')
            ->orderBy('sign_in_day')
            ->pluck('total_cities', 'date')
            ->toArray();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function formatNation(
        Nation $nation,
        ?InactivityEvent $event = null,
        ?object $accountTotals = null,
        ?array $profitability = null
    ): array {
        $cities = $nation->num_cities;
        $military = $nation->relationLoaded('military') ? $nation->military : null;
        $nationResources = $nation->relationLoaded('resources') ? $nation->resources : null;
        $max = [
            'soldiers' => $cities * 15000,
            'tanks' => $cities * 1250,
            'aircraft' => $cities * 75,
            'ships' => $cities * 15,
        ];

        $current = [
            'soldiers' => $military?->soldiers ?? 0,
            'tanks' => $military?->tanks ?? 0,
            'aircraft' => $military?->aircraft ?? 0,
            'ships' => $military?->ships ?? 0,
        ];

        $militaryPercent = collect($max)->mapWithKeys(fn ($maxVal, $type) => [
            $type => $maxVal > 0 ? round(($current[$type] / $maxVal) * 100, 2) : 0,
        ])->toArray();

        $resources = [
            'money',
            'steel',
            'gasoline',
            'aluminum',
            'munitions',
            'uranium',
            'food',
        ];

        $resourceValues = collect($resources)->mapWithKeys(function ($res) use ($nationResources, $accountTotals) {
            $accountTotal = (float) ($accountTotals?->{$res} ?? 0);
            $inGame = $nationResources?->{$res} ?? 0;

            return [
                $res => [
                    'total' => $accountTotal + $inGame,
                    'in_game' => $inGame,
                ],
            ];
        });

        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'score' => $nation->score,
            'cities' => $cities,
            'timezone' => $nation->update_tz,
            'spies' => $military?->spies ?? 0,
            'military_percent' => $militaryPercent,
            'military_current' => $current,
            'resources' => $resourceValues,
            'profitability' => $profitability,
            'is_inactive' => (bool) $event,
            'inactive_since_at' => $event?->episode_started_at,
            'last_pw_last_active_at' => $nation->accountProfile?->last_active,
            'current_inactivity_event' => $event ? [
                'episode_started_at' => $event->episode_started_at,
                'last_notified_at' => $event->last_notified_at,
                'dd_autoenrolled_at' => $event->dd_autoenrolled_at,
                'dd_opted_out_at' => $event->dd_opted_out_at,
            ] : null,
        ];
    }

    /**
     * Gets stats for the admin/members/{nations} page
     */
    public function getNationStats(Nation $nation, User $viewer): array
    {
        $nationId = $nation->id;
        $canViewAccounts = $viewer->can('view-accounts');
        $canViewCityGrants = $viewer->can('view-city-grants');
        $canViewGrants = $viewer->can('view-grants');
        $canManageGrants = $viewer->can('manage-grants');
        $canViewLoans = $viewer->can('view-loans');
        $canViewMmr = $viewer->can('view-mmr');
        $canViewTaxes = $viewer->can('view-taxes');

        // 1. Info Boxes
        $lastSignIn = NationSignIn::query()
            ->where('nation_id', $nationId)
            ->latest('created_at')
            ->first(['score', 'num_cities']);
        $lastUpdatedAt = optional($nation)->updated_at;
        $lastScore = optional($lastSignIn)->score ?? $nation->score;
        $lastCities = optional($lastSignIn)->num_cities ?? $nation->cities;

        // 2. Resource History (30 days)
        $resourceHistory = collect();

        if ($canViewMmr) {
            $resourceHistory = NationSignIn::where('nation_id', $nationId)
                ->where('created_at', '>=', now()->subDays(30))
                ->orderBy('created_at')
                ->get(['created_at', 'steel', 'aluminum', 'munitions', 'gasoline'])
                ->map(function ($row) {
                    return [
                        'date' => $row->created_at->format('Y-m-d'),
                        'steel' => $row->steel,
                        'aluminum' => $row->aluminum,
                        'munitions' => $row->munitions,
                        'gasoline' => $row->gasoline,
                    ];
                });
        }

        // 3. Score History (365 days)
        $scoreHistory = NationSignIn::where('nation_id', $nationId)
            ->where('created_at', '>=', now()->subDays(365))
            ->orderBy('created_at')
            ->get(['created_at', 'score']);

        $taxHistory = $canViewTaxes ? $this->taxHistory($nationId) : collect();
        $taxSummary = $canViewTaxes ? $this->thirtyDayTaxSummary($nationId) : null;

        // 5. Recent Requests
        $recentCityGrants = $canViewCityGrants
            ? CityGrantRequest::where('nation_id', $nationId)->latest()->take(5)->get()
            : collect();
        $recentCustomGrants = collect();

        if ($canViewGrants) {
            $grantHistoryColumns = array_merge([
                'id',
                'grant_id',
                'program_name_snapshot',
                'program_version_snapshot',
                'nation_id',
                'account_id',
                'status',
                'decision_reason_code',
                'decision_explanation',
                'reviewed_by_user_id',
                'submitted_at',
                'approved_at',
                'denied_at',
                'decided_at',
                'disbursed_at',
                'created_at',
                'updated_at',
            ], GrantApplication::PAYOUT_COLUMNS);

            if ($canManageGrants) {
                $grantHistoryColumns[] = 'decision_internal_note';
            }

            $recentCustomGrants = GrantApplication::query()
                ->select($grantHistoryColumns)
                ->with('reviewer:id,name')
                ->where('nation_id', $nationId)
                ->latest()
                ->take(5)
                ->get();
        }
        $recentLoans = $canViewLoans
            ? Loan::where('nation_id', $nationId)->latest()->take(5)->get()
            : collect();
        $recentTaxes = $canViewTaxes ? $this->recentTaxes($nationId) : collect();

        $resourceSignInHistory = collect();

        if ($canViewMmr) {
            $resourceSignInHistory = NationSignIn::where('nation_id', $nation->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->orderBy('created_at')
                ->get(['created_at', 'money', 'steel', 'aluminum', 'gasoline', 'munitions'])
                ->map(fn ($row) => [
                    'date' => $row->created_at->format('Y-m-d'),
                    'money' => $row->money,
                    'steel' => $row->steel,
                    'aluminum' => $row->aluminum,
                    'gasoline' => $row->gasoline,
                    'munitions' => $row->munitions,
                ])
                ->values();
        }

        $memberAccounts = collect();

        if ($canViewAccounts) {
            $memberAccounts = Account::query()
                ->where('nation_id', $nationId)
                ->orderBy('id')
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'frozen' => (bool) $account->frozen,
                    'resources' => collect(PWHelperService::resources())
                        ->mapWithKeys(fn (string $resource) => [$resource => (float) ($account->$resource ?? 0.0)])
                        ->all(),
                    'updated_at' => $account->updated_at,
                ])
                ->values();
        }

        return [
            'nation' => $nation,
            'lastScore' => $lastScore,
            'lastCities' => $lastCities,
            'lastUpdatedAt' => $lastUpdatedAt,

            'resourceHistory' => $resourceHistory,
            'scoreHistory' => $scoreHistory,
            'taxHistory' => $taxHistory,
            'taxSummary' => $taxSummary,

            'recentCityGrants' => $recentCityGrants,
            'recentCustomGrants' => $recentCustomGrants,
            'recentLoans' => $recentLoans,
            'recentTaxes' => $recentTaxes,

            'resourceSignInHistory' => $resourceSignInHistory,
            'memberAccounts' => $memberAccounts,
            'canViewAccounts' => $canViewAccounts,
            'canViewCityGrants' => $canViewCityGrants,
            'canViewGrants' => $canViewGrants,
            'canManageGrants' => $canManageGrants,
            'canViewLoans' => $canViewLoans,
            'canViewMmr' => $canViewMmr,
            'canViewTaxes' => $canViewTaxes,
        ];
    }

    /**
     * @return Collection<int, array<string, float|string>>
     */
    private function taxHistory(int $nationId): Collection
    {
        [$windowStartsAt, $windowEndsAt] = $this->utcWindow(365);

        return Taxes::query()
            ->selectRaw('day AS date, SUM(money) AS money, SUM(steel) AS steel, SUM(gasoline) AS gasoline, SUM(aluminum) AS aluminum, SUM(munitions) AS munitions, SUM(uranium) AS uranium, SUM(food) AS food')
            ->where('sender_id', $nationId)
            ->whereBetween('date', [$windowStartsAt, $windowEndsAt])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'money' => (float) ($row->money ?? 0),
                'steel' => (float) ($row->steel ?? 0),
                'gasoline' => (float) ($row->gasoline ?? 0),
                'aluminum' => (float) ($row->aluminum ?? 0),
                'munitions' => (float) ($row->munitions ?? 0),
                'uranium' => (float) ($row->uranium ?? 0),
                'food' => (float) ($row->food ?? 0),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, float|string>>
     */
    private function recentTaxes(int $nationId): Collection
    {
        [$windowStartsAt, $windowEndsAt] = $this->utcWindow(7);

        return Taxes::query()
            ->selectRaw('day AS date, SUM(money) AS money, SUM(steel) AS steel, SUM(munitions) AS munitions, SUM(food) AS food')
            ->where('sender_id', $nationId)
            ->whereBetween('date', [$windowStartsAt, $windowEndsAt])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'money' => (float) ($row->money ?? 0),
                'steel' => (float) ($row->steel ?? 0),
                'munitions' => (float) ($row->munitions ?? 0),
                'food' => (float) ($row->food ?? 0),
            ])
            ->values();
    }

    /**
     * Uses the synchronized post-Direct Deposit money amount that TaxService records in the finance ledger.
     *
     * @return array{
     *     total_money: float,
     *     window_starts_at: CarbonInterface,
     *     window_ends_at: CarbonInterface,
     *     latest_recorded_at: CarbonInterface|null,
     *     timezone: string
     * }
     */
    private function thirtyDayTaxSummary(int $nationId): array
    {
        [$windowStartsAt, $windowEndsAt] = $this->utcWindow(30);

        $summary = Taxes::query()
            ->where('sender_id', $nationId)
            ->whereBetween('date', [$windowStartsAt, $windowEndsAt])
            ->selectRaw('COALESCE(SUM(`money`), 0) AS `total_money`, MAX(`date`) AS `latest_recorded_at`')
            ->first();

        $latestRecordedAt = $summary?->latest_recorded_at
            ? Carbon::parse((string) $summary->latest_recorded_at, 'UTC')->utc()
            : null;

        return [
            'total_money' => (float) ($summary?->total_money ?? 0.0),
            'window_starts_at' => $windowStartsAt,
            'window_ends_at' => $windowEndsAt,
            'latest_recorded_at' => $latestRecordedAt,
            'timezone' => 'UTC',
        ];
    }

    /**
     * Tax records are imported and stored as UTC instants, so member tax windows remain UTC
     * regardless of the configured application display timezone.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function utcWindow(int $days): array
    {
        $windowEndsAt = now('UTC');

        return [$windowEndsAt->copy()->subDays($days), $windowEndsAt];
    }
}
