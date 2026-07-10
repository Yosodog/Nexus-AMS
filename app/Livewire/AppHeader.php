<?php

namespace App\Livewire;

use App\Models\Grants;
use App\Services\AllianceMembershipService;
use App\Services\PendingRequestsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppHeader extends Component
{
    public function logout(): RedirectResponse
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('home');
    }

    public function render(): View
    {
        $user = Auth::user();
        $pendingRequests = $user
            ? app(PendingRequestsService::class)->getCountsForUser($user)
            : ['counts' => [], 'total' => 0];
        $pendingTotal = $pendingRequests['total'] ?? 0;

        $membershipService = app(AllianceMembershipService::class);
        $allianceId = data_get($user, 'nation.alliance_id');
        $showMemberNavigation = $user !== null && $membershipService->contains($allianceId);

        $enabledGrants = $showMemberNavigation
            ? Grants::query()->where('is_enabled', true)->orderBy('name')->get()
            : collect();

        $showPendingIndicator = $user && $pendingTotal > 0;

        return view('livewire.app-header', [
            'user' => $user,
            'showMemberNavigation' => $showMemberNavigation,
            'enabledGrants' => $enabledGrants,
            'navigation' => $this->navigation($enabledGrants->all()),
            'pendingTotal' => $pendingTotal,
            'showPendingIndicator' => $showPendingIndicator,
        ]);
    }

    /**
     * @param  array<int, Grants>  $enabledGrants
     * @return array<int, array{
     *     label: string,
     *     icon: string,
     *     active: bool,
     *     route?: string,
     *     items?: array<int, array{label: string, route: string, active: bool}>
     * }>
     */
    private function navigation(array $enabledGrants): array
    {
        $grantItems = array_map(
            fn (Grants $grant): array => [
                'label' => str($grant->name)->headline()->toString(),
                'route' => route('grants.show_grants', $grant->slug),
                'active' => request()->routeIs('grants.show_grants') && request()->route('grant')?->is($grant),
            ],
            $enabledGrants,
        );

        return [
            [
                'label' => 'Overview',
                'icon' => 'o-squares-2x2',
                'route' => route('user.dashboard'),
                'active' => request()->routeIs('user.dashboard'),
            ],
            [
                'label' => 'Finance',
                'icon' => 'o-banknotes',
                'active' => request()->routeIs('accounts*', 'member-transfers.*', 'market.*', 'loans.*'),
                'items' => [
                    ['label' => 'Accounts', 'route' => route('accounts'), 'active' => request()->routeIs('accounts*', 'member-transfers.*')],
                    ['label' => 'Alliance market', 'route' => route('market.index'), 'active' => request()->routeIs('market.*')],
                    ['label' => 'Loans', 'route' => route('loans.index'), 'active' => request()->routeIs('loans.*')],
                ],
            ],
            [
                'label' => 'Assistance',
                'icon' => 'o-lifebuoy',
                'active' => request()->routeIs('grants.*', 'defense.war-aid*', 'defense.rebuilding*'),
                'items' => [
                    ['label' => 'City grants', 'route' => route('grants.city'), 'active' => request()->routeIs('grants.city*')],
                    ...$grantItems,
                    ['label' => 'War aid', 'route' => route('defense.war-aid'), 'active' => request()->routeIs('defense.war-aid*')],
                    ['label' => 'Rebuilding', 'route' => route('defense.rebuilding'), 'active' => request()->routeIs('defense.rebuilding*')],
                ],
            ],
            [
                'label' => 'Readiness',
                'icon' => 'o-shield-check',
                'active' => request()->routeIs('audit.*', 'defense.counters*', 'defense.war-stats*', 'defense.simulators*'),
                'items' => [
                    ['label' => 'Audit recommendations', 'route' => route('audit.index'), 'active' => request()->routeIs('audit.*')],
                    ['label' => 'Counter finder', 'route' => route('defense.counters'), 'active' => request()->routeIs('defense.counters*')],
                    ['label' => 'War statistics', 'route' => route('defense.war-stats'), 'active' => request()->routeIs('defense.war-stats*')],
                    ['label' => 'War simulators', 'route' => route('defense.simulators'), 'active' => request()->routeIs('defense.simulators*')],
                ],
            ],
            [
                'label' => 'Intelligence',
                'icon' => 'o-magnifying-glass',
                'active' => request()->routeIs('defense.intel*', 'defense.raid-finder*'),
                'items' => [
                    ['label' => 'Intel library', 'route' => route('defense.intel'), 'active' => request()->routeIs('defense.intel*')],
                    ['label' => 'Raid finder', 'route' => route('defense.raid-finder'), 'active' => request()->routeIs('defense.raid-finder*')],
                ],
            ],
            [
                'label' => 'Performance',
                'icon' => 'o-chart-bar',
                'active' => request()->routeIs('leaderboards.*', 'defense.raid-leaderboard*'),
                'items' => [
                    ['label' => 'Leaderboards', 'route' => route('leaderboards.index'), 'active' => request()->routeIs('leaderboards.*') && in_array(request()->route('board'), [null, 'dashboard'], true)],
                    ['label' => 'Profitability leaderboard', 'route' => route('leaderboards.index', ['board' => 'profitability']), 'active' => request()->routeIs('leaderboards.*') && request()->route('board') === 'profitability'],
                    ['label' => 'Raid leaderboard', 'route' => route('defense.raid-leaderboard'), 'active' => request()->routeIs('defense.raid-leaderboard*')],
                ],
            ],
        ];
    }
}
