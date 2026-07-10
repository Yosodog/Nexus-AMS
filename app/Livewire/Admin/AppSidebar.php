<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\PendingRequestsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppSidebar extends Component
{
    public function render(): View
    {
        /** @var User|null $user */
        $user = Auth::user();
        $pendingRequests = $user
            ? app(PendingRequestsService::class)->getCountsForUser($user)
            : ['counts' => [], 'total' => 0];
        $pendingCounts = $pendingRequests['counts'] ?? [];

        return view('livewire.admin.app-sidebar', [
            'navigation' => $user ? $this->navigation($user, $pendingCounts) : [],
            'pendingTotal' => $pendingRequests['total'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, int>  $pendingCounts
     * @return array<int, array{label: string, items: array<int, array{label: string, icon: string, route: string, active: bool, badge: int|null}>}>
     */
    private function navigation(User $user, array $pendingCounts): array
    {
        $grantsPending = ($pendingCounts['city_grants'] ?? 0) + ($pendingCounts['grants'] ?? 0);
        $warSupportPending = ($pendingCounts['war_aid'] ?? 0) + ($pendingCounts['rebuilding'] ?? 0);

        return array_values(array_filter([
            $this->group('Workspace', [
                $this->item('Overview', 'o-squares-2x2', route('admin.dashboard'), request()->routeIs('admin.dashboard')),
                $this->item('Accounts', 'o-building-library', route('admin.accounts.dashboard'), request()->routeIs('admin.accounts.*', 'admin.withdrawals.*'), $pendingCounts['withdrawals'] ?? 0, $user->can('view-accounts')),
                $this->item('Applications', 'o-document-text', route('admin.applications.index'), request()->routeIs('admin.applications.*'), $pendingCounts['applications'] ?? 0, $user->can('view-applications')),
                $this->item('Grants', 'o-gift', route('admin.grants'), request()->routeIs('admin.grants', 'admin.grants.city'), $grantsPending, $user->can('view-grants') || $user->can('view-city-grants')),
                $this->item('Loans', 'o-banknotes', route('admin.loans'), request()->routeIs('admin.loans*'), $pendingCounts['loans'] ?? 0, $user->can('view-loans')),
                $this->item('War support', 'o-lifebuoy', route('admin.war-aid'), request()->routeIs('admin.war-aid', 'admin.rebuilding.*'), $warSupportPending, $user->can('view-war-aid') || $user->can('view-rebuilding')),
            ]),
            $this->group('Alliance', [
                $this->item('Members', 'o-users', route('admin.members'), request()->routeIs('admin.members*'), null, $user->can('view-members')),
                $this->item('Cities', 'o-building-office-2', route('admin.cities.index'), request()->routeIs('admin.cities.*'), null, $user->can('view-members')),
                $this->item('Users', 'o-user-group', route('admin.users.index'), request()->routeIs('admin.users.*'), null, $user->can('view-users')),
                $this->item('Roles', 'o-identification', route('admin.roles.index'), request()->routeIs('admin.roles.*'), null, $user->can('view-roles')),
                $this->item('Audits', 'o-shield-check', route('admin.audits.index'), request()->routeIs('admin.audits.*'), null, $user->can('view-audits')),
                $this->item('Recruitment', 'o-envelope', route('admin.recruitment.index'), request()->routeIs('admin.recruitment.*'), null, $user->can('view-recruitment')),
            ]),
            $this->group('Finance', [
                $this->item('City grants', 'o-home-modern', route('admin.grants.city'), request()->route()?->getName() === 'admin.grants.city', $pendingCounts['city_grants'] ?? 0, $user->can('view-city-grants')),
                $this->item('Grant programs', 'o-gift', route('admin.grants'), request()->route()?->getName() === 'admin.grants', $pendingCounts['grants'] ?? 0, $user->can('view-grants')),
                $this->item('Growth Circles', 'o-arrow-trending-up', route('admin.growth-circles.index'), request()->routeIs('admin.growth-circles.*'), null, $user->can('view-growth-circles')),
                $this->item('Taxes', 'o-receipt-percent', route('admin.taxes'), request()->routeIs('admin.taxes'), null, $user->can('view-taxes')),
                $this->item('Offshores', 'o-globe-alt', route('admin.offshores.index'), request()->routeIs('admin.offshores.*'), null, $user->can('view-offshores')),
                $this->item('Finance ledger', 'o-book-open', route('admin.finance.index'), request()->routeIs('admin.finance.*'), null, $user->can('view-financial-reports')),
                $this->item('Payroll', 'o-currency-dollar', route('admin.payroll.index'), request()->routeIs('admin.payroll.*'), null, $user->can('view_payroll')),
                $this->item('Alliance market', 'o-shopping-bag', route('admin.market.index'), request()->routeIs('admin.market.*'), null, $user->can('view-market')),
            ]),
            $this->group('Defense', [
                $this->item('War room', 'o-command-line', route('admin.war-room'), request()->routeIs('admin.war-room', 'admin.war-plans.*', 'admin.war-counters.*'), null, $user->can('view-wars')),
                $this->item('Wars', 'o-bolt', route('admin.wars'), request()->routeIs('admin.wars'), null, $user->can('view-wars')),
                $this->item('War aid', 'o-heart', route('admin.war-aid'), request()->routeIs('admin.war-aid'), $pendingCounts['war_aid'] ?? 0, $user->can('view-war-aid')),
                $this->item('Rebuilding', 'o-wrench-screwdriver', route('admin.rebuilding.index'), request()->routeIs('admin.rebuilding.*'), $pendingCounts['rebuilding'] ?? 0, $user->can('view-rebuilding')),
                $this->item('Raids', 'o-arrow-trending-up', route('admin.raids.index'), request()->routeIs('admin.raids.*'), null, $user->can('view-raids')),
                $this->item('Beige alerts', 'o-bell-alert', route('admin.beige-alerts.index'), request()->routeIs('admin.beige-alerts.*'), null, $user->can('view-raids')),
                $this->item('Spy campaigns', 'o-eye', route('admin.spy-campaigns.index'), request()->routeIs('admin.spy-campaigns.*'), null, $user->can('view-spies')),
                $this->item('MMR', 'o-shield-exclamation', route('admin.mmr.index'), request()->routeIs('admin.mmr.*'), null, $user->can('view-mmr')),
            ]),
            $this->group('System', [
                $this->item(
                    'Settings',
                    'o-adjustments-horizontal',
                    route('admin.settings'),
                    request()->routeIs('admin.settings'),
                    null,
                    $user->canAny(['view-diagnostic-info', 'manage-accounts', 'manage-loans', 'manage-grants']),
                ),
                $this->item('Custom pages', 'o-paint-brush', route('admin.customization.index'), request()->routeIs('admin.customization.*'), null, $user->can('manage-custom-pages')),
                $this->item('Audit logs', 'o-clipboard-document-list', route('admin.audit-logs.index'), request()->routeIs('admin.audit-logs.*'), null, $user->can('view-diagnostic-info')),
                $this->item('NEL reference', 'o-code-bracket', route('admin.nel.docs'), request()->routeIs('admin.nel.docs'), null, $user->can('view-diagnostic-info')),
                $this->item('Telescope', 'o-bug-ant', url('/telescope'), request()->is('telescope*'), null, $user->can('view-diagnostic-info')),
                $this->item('Pulse', 'o-signal', url('/pulse'), request()->is('pulse*'), null, $user->can('view-diagnostic-info')),
                $this->item('Log viewer', 'o-document-magnifying-glass', url('/log-viewer'), request()->is('log-viewer*'), null, $user->can('view-diagnostic-info')),
            ]),
        ]));
    }

    /**
     * @param  array<int, array{label: string, icon: string, route: string, active: bool, badge: int|null}|null>  $items
     * @return array{label: string, items: array<int, array{label: string, icon: string, route: string, active: bool, badge: int|null}>}|null
     */
    private function group(string $label, array $items): ?array
    {
        $visibleItems = array_values(array_filter($items));

        return $visibleItems === [] ? null : ['label' => $label, 'items' => $visibleItems];
    }

    /**
     * @return array{label: string, icon: string, route: string, active: bool, badge: int|null}|null
     */
    private function item(
        string $label,
        string $icon,
        string $route,
        bool $active,
        ?int $badge = null,
        bool $visible = true,
    ): ?array {
        if (! $visible) {
            return null;
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'active' => $active,
            'badge' => $badge > 0 ? $badge : null,
        ];
    }
}
