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

class MemberStatsService
{
    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * @return array<int|string, mixed>
     */
    public function getOverviewData(): array
    {
        $nations = Nation::with(['resources', 'accounts', 'military', 'accountProfile'])
            ->whereIn('alliance_id', $this->membershipService->getAllianceIds())
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', '=', 0)
            ->get();

        $openEvents = InactivityEvent::query()
            ->whereNull('episode_ended_at')
            ->get()
            ->keyBy('nation_id');

        $maxTier = $nations->max('num_cities') ?? 0;

        $cityTiers = collect(range(1, $maxTier))->mapWithKeys(fn ($tier) => [
            $tier => $nations->where('num_cities', $tier)->count(),
        ])->toArray();

        return [
            'totalMembers' => $nations->count(),
            'avgScore' => round($nations->avg('score'), 2),
            'totalCities' => $nations->sum('num_cities'),
            'cityTiers' => $cityTiers,
            'cityGrowthHistory' => $this->getCityGrowthHistory(),
            'members' => $nations->map(fn ($nation) => $this->formatNation($nation, $openEvents->get($nation->id))),
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
        return NationSignIn::selectRaw('DATE(created_at) as date, SUM(num_cities) as total_cities')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total_cities', 'date')
            ->toArray();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function formatNation(Nation $nation, ?InactivityEvent $event = null): array
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

        $resourceValues = collect($resources)->mapWithKeys(function ($res) use ($nation) {
            $accountTotal = $nation->accounts->sum($res);
            $inGame = optional($nation->resources)->$res ?? 0;

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
            'score' => $nation->score,
            'cities' => $cities,
            'timezone' => $nation->update_tz,
            'spies' => $nation->military->spies,
            'military_percent' => $militaryPercent,
            'military_current' => $current,
            'resources' => $resourceValues,
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
    public function getNationStats(Nation $nation): array
    {
        $nationId = $nation->id;

        // 1. Info Boxes
        $lastSignIn = NationSignIn::where('nation_id', $nationId)->latest()->first();
        $lastUpdatedAt = optional($nation)->updated_at;
        $lastScore = optional($lastSignIn)->score ?? $nation->score;
        $lastCities = optional($lastSignIn)->num_cities ?? $nation->cities;

        // 2. Resource History (30 days)
        $resourceHistory = NationSignIn::where('nation_id', $nationId)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->created_at->format('Y-m-d'),
                    'steel' => $row->steel,
                    'aluminum' => $row->aluminum,
                    'munitions' => $row->munitions,
                    'gasoline' => $row->gasoline,
                ];
            });

        // 3. Score History (365 days)
        $scoreHistory = NationSignIn::where('nation_id', $nationId)
            ->where('created_at', '>=', now()->subDays(365))
            ->orderBy('created_at')
            ->get(['created_at', 'score']);

        $taxHistory = Taxes::query()
            ->selectRaw('day AS date, SUM(money) AS money, SUM(steel) AS steel, SUM(gasoline) AS gasoline, SUM(aluminum) AS aluminum, SUM(munitions) AS munitions, SUM(uranium) AS uranium, SUM(food) AS food')
            ->where('sender_id', $nationId)
            ->where('date', '>=', now()->subDays(365))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => (string) $row->date,
                    'money' => (float) ($row->money ?? 0),
                    'steel' => (float) ($row->steel ?? 0),
                    'gasoline' => (float) ($row->gasoline ?? 0),
                    'aluminum' => (float) ($row->aluminum ?? 0),
                    'munitions' => (float) ($row->munitions ?? 0),
                    'uranium' => (float) ($row->uranium ?? 0),
                    'food' => (float) ($row->food ?? 0),
                ];
            })
            ->values();

        // 5. Recent Requests
        $recentCityGrants = CityGrantRequest::where('nation_id', $nationId)->latest()->take(5)->get();
        $recentCustomGrants = GrantApplication::where('nation_id', $nationId)->latest()->take(5)->get();
        $recentLoans = Loan::where('nation_id', $nationId)->latest()->take(5)->get();
        $recentTaxes = Taxes::query()
            ->selectRaw('day AS date, SUM(money) AS money, SUM(steel) AS steel, SUM(munitions) AS munitions, SUM(food) AS food')
            ->where('sender_id', $nationId)
            ->where('date', '>=', now()->subDays(7))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => (string) $row->date,
                    'money' => (float) ($row->money ?? 0),
                    'steel' => (float) ($row->steel ?? 0),
                    'munitions' => (float) ($row->munitions ?? 0),
                    'food' => (float) ($row->food ?? 0),
                ];
            })
            ->values();

        $resourceSignInHistory = NationSignIn::where('nation_id', $nation->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->created_at->format('Y-m-d'),
                'money' => $row->money,
                'steel' => $row->steel,
                'aluminum' => $row->aluminum,
                'gasoline' => $row->gasoline,
                'munitions' => $row->munitions,
            ])
            ->values();

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

        return [
            'nation' => $nation,
            'lastScore' => $lastScore,
            'lastCities' => $lastCities,
            'lastUpdatedAt' => $lastUpdatedAt,

            'resourceHistory' => $resourceHistory,
            'scoreHistory' => $scoreHistory,
            'taxHistory' => $taxHistory,

            'recentCityGrants' => $recentCityGrants,
            'recentCustomGrants' => $recentCustomGrants,
            'recentLoans' => $recentLoans,
            'recentTaxes' => $recentTaxes,

            'resourceSignInHistory' => $resourceSignInHistory,
            'memberAccounts' => $memberAccounts,
        ];
    }
}
