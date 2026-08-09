<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Http\Request;

final class AdminNavigationCatalog
{
    public function __construct(
        private readonly Request $request,
        private readonly StaffWorkQueueRegistry $workQueueRegistry,
    ) {}

    /**
     * @param  array<string, int>  $pendingCounts
     * @return array<int, array{label: string, items: array<int, array{id: string, label: string, icon: string, route: string, active: bool, badge: int|null, keywords: string}>}>
     */
    public function groups(User $user, array $pendingCounts = []): array
    {
        $grantsPending = ($pendingCounts['city_grants'] ?? 0) + ($pendingCounts['grants'] ?? 0);
        $accountPending = ($pendingCounts['withdrawals'] ?? 0) + ($pendingCounts['member_transfers'] ?? 0);
        $warSupportPending = ($pendingCounts['war_aid'] ?? 0)
            + ($pendingCounts['rebuilding'] ?? 0)
            + ($pendingCounts['blockade_relief'] ?? 0);
        $workQueueTotal = array_sum($pendingCounts);
        $canUseWorkQueue = $this->workQueueRegistry->canView($user);

        return array_values(array_filter([
            $this->group('Workspace', [
                $this->item('overview', 'Overview', 'o-squares-2x2', route('admin.dashboard'), $this->request->routeIs('admin.dashboard'), keywords: 'home dashboard'),
                $this->item('work-queue', 'Work queue', 'o-clipboard-document-list', route('admin.work-queue.index'), $this->request->routeIs('admin.work-queue.*'), $workQueueTotal, $canUseWorkQueue, 'pending reviews staff tasks approvals'),
                $this->item('accounts', 'Accounts', 'o-building-library', route('admin.accounts.dashboard'), $this->request->routeIs('admin.accounts.*', 'admin.withdrawals.*'), $accountPending, $user->can('view-accounts'), 'bank balances withdrawals transfers'),
                $this->item('applications', 'Applications', 'o-document-text', route('admin.applications.index'), $this->request->routeIs('admin.applications.*'), $pendingCounts['applications'] ?? 0, $user->can('view-applications'), 'recruitment decisions'),
                $this->item('grants-workspace', 'Grants', 'o-gift', route('admin.grants'), $this->request->routeIs('admin.grants', 'admin.grants.city'), $grantsPending, $user->can('view-grants') || $user->can('view-city-grants'), 'programs city funding'),
                $this->item('loans', 'Loans', 'o-banknotes', route('admin.loans'), $this->request->routeIs('admin.loans*'), $pendingCounts['loans'] ?? 0, $user->can('view-loans'), 'applications repayment'),
                $this->item('war-support', 'War support', 'o-lifebuoy', route('admin.war-aid'), $this->request->routeIs('admin.war-aid', 'admin.rebuilding.*'), $warSupportPending, $user->can('view-war-aid') || $user->can('view-rebuilding'), 'aid rebuilding'),
            ]),
            $this->group('Alliance', [
                $this->item('members', 'Members', 'o-users', route('admin.members'), $this->request->routeIs('admin.members*'), visible: $user->can('view-members'), keywords: 'nations leaders'),
                $this->item('cities', 'Cities', 'o-building-office-2', route('admin.cities.index'), $this->request->routeIs('admin.cities.*'), visible: $user->can('view-members'), keywords: 'infrastructure land'),
                $this->item('users', 'Users', 'o-user-group', route('admin.users.index'), $this->request->routeIs('admin.users.*'), visible: $user->can('view-users'), keywords: 'accounts login'),
                $this->item('roles', 'Roles', 'o-identification', route('admin.roles.index'), $this->request->routeIs('admin.roles.*'), visible: $user->can('view-roles'), keywords: 'permissions access'),
                $this->item('audits', 'Audits', 'o-shield-check', route('admin.audits.index'), $this->request->routeIs('admin.audits.*'), $pendingCounts['audit_remediation'] ?? 0, $user->can('view-audits'), 'compliance findings remediation'),
                $this->item('recruitment', 'Recruitment', 'o-envelope', route('admin.recruitment.index'), $this->request->routeIs('admin.recruitment.*'), visible: $user->can('view-recruitment'), keywords: 'prospects messages'),
            ]),
            $this->group('Finance', [
                $this->item('city-grants', 'City grants', 'o-home-modern', route('admin.grants.city'), $this->request->route()?->getName() === 'admin.grants.city', $pendingCounts['city_grants'] ?? 0, $user->can('view-city-grants'), 'funding requests'),
                $this->item('grant-programs', 'Grant programs', 'o-gift', route('admin.grants'), $this->request->route()?->getName() === 'admin.grants', $pendingCounts['grants'] ?? 0, $user->can('view-grants'), 'funding requirements'),
                $this->item('growth-circles', 'Growth Circles', 'o-arrow-trending-up', route('admin.growth-circles.index'), $this->request->routeIs('admin.growth-circles.*'), visible: $user->can('view-growth-circles'), keywords: 'program enrollment'),
                $this->item('taxes', 'Taxes', 'o-receipt-percent', route('admin.taxes'), $this->request->routeIs('admin.taxes'), visible: $user->can('view-taxes'), keywords: 'revenue rates'),
                $this->item('offshores', 'Offshores', 'o-globe-alt', route('admin.offshores.index'), $this->request->routeIs('admin.offshores.*'), visible: $user->can('view-offshores'), keywords: 'banks transfers'),
                $this->item('finance-ledger', 'Finance ledger', 'o-book-open', route('admin.finance.index'), $this->request->routeIs('admin.finance.*'), visible: $user->can('view-financial-reports'), keywords: 'transactions reports'),
                $this->item('payroll', 'Payroll', 'o-currency-dollar', route('admin.payroll.index'), $this->request->routeIs('admin.payroll.*'), visible: $user->can('view_payroll'), keywords: 'salary grades'),
                $this->item('alliance-market', 'Alliance market', 'o-shopping-bag', route('admin.market.index'), $this->request->routeIs('admin.market.*'), visible: $user->can('view-market'), keywords: 'resources trades'),
                $this->item('lottery', 'Weekly lottery', 'o-ticket', route('admin.lottery.index'), $this->request->routeIs('admin.lottery.*'), visible: $user->canAny(['view-lottery', 'manage-lottery']), keywords: 'tickets drawing'),
            ]),
            $this->group('Defense', [
                (bool) config('milcom.v2_enabled', true)
                    ? $this->item('milcom', 'Milcom', 'o-command-line', route('admin.milcom.dashboard'), $this->request->routeIs('admin.milcom.*'), visible: $user->can('manage-war-room'), keywords: 'operations assignments')
                    : $this->item('war-room', 'War room', 'o-command-line', route('admin.war-room'), $this->request->routeIs('admin.war-room', 'admin.war-plans.*', 'admin.war-counters.*'), visible: $user->can('manage-war-room'), keywords: 'plans counters'),
                $this->item('wars', 'Wars', 'o-bolt', route('admin.wars'), $this->request->routeIs('admin.wars'), visible: $user->can('view-wars'), keywords: 'conflicts attacks'),
                $this->item('war-aid', 'War aid', 'o-heart', route('admin.war-aid'), $this->request->routeIs('admin.war-aid'), $pendingCounts['war_aid'] ?? 0, $user->can('view-war-aid'), 'reimbursement support'),
                $this->item('rebuilding', 'Rebuilding', 'o-wrench-screwdriver', route('admin.rebuilding.index'), $this->request->routeIs('admin.rebuilding.*'), $pendingCounts['rebuilding'] ?? 0, $user->can('view-rebuilding'), 'recovery infrastructure'),
                $this->item('raids', 'Raids', 'o-arrow-trending-up', route('admin.raids.index'), $this->request->routeIs('admin.raids.*'), visible: $user->can('view-raids'), keywords: 'targets performance'),
                $this->item('beige-alerts', 'Beige alerts', 'o-bell-alert', route('admin.beige-alerts.index'), $this->request->routeIs('admin.beige-alerts.*'), visible: $user->can('view-raids'), keywords: 'targets notifications'),
                $this->item('spy-campaigns', 'Spy campaigns', 'o-eye', route('admin.spy-campaigns.index'), $this->request->routeIs('admin.spy-campaigns.*'), visible: $user->can('view-spies'), keywords: 'espionage operations'),
                $this->item('mmr', 'MMR', 'o-shield-exclamation', route('admin.mmr.index'), $this->request->routeIs('admin.mmr.*'), visible: $user->can('view-mmr'), keywords: 'military readiness'),
            ]),
            $this->group('System', [
                $this->item('settings', 'Settings', 'o-adjustments-horizontal', route('admin.settings'), $this->request->routeIs('admin.settings'), visible: $user->canAny((array) config('admin-settings.access_permissions')), keywords: 'configuration'),
                $this->item('custom-pages', 'Custom pages', 'o-paint-brush', route('admin.customization.index'), $this->request->routeIs('admin.customization.*'), visible: $user->can('manage-custom-pages'), keywords: 'content editor'),
                $this->item('audit-logs', 'Audit logs', 'o-clipboard-document-list', route('admin.audit-logs.index'), $this->request->routeIs('admin.audit-logs.*'), visible: $user->can('view-diagnostic-info'), keywords: 'history events'),
                $this->item('telescope', 'Telescope', 'o-bug-ant', url('/telescope'), $this->request->is('telescope*'), visible: $user->can('view-diagnostic-info'), keywords: 'debug requests jobs'),
                $this->item('pulse', 'Pulse', 'o-signal', url('/pulse'), $this->request->is('pulse*'), visible: $user->can('view-diagnostic-info'), keywords: 'performance metrics'),
                $this->item('log-viewer', 'Log viewer', 'o-document-magnifying-glass', url('/log-viewer'), $this->request->is('log-viewer*'), visible: $user->is_admin && $user->can('view-application-logs'), keywords: 'errors diagnostics'),
            ]),
        ]));
    }

    /**
     * @return array<int, array{id: string, label: string, icon: string, route: string, group: string, keywords: string}>
     */
    public function commands(User $user): array
    {
        return collect($this->groups($user))
            ->flatMap(fn (array $group): array => array_map(
                fn (array $item): array => [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'route' => $item['route'],
                    'group' => $group['label'],
                    'keywords' => $item['keywords'],
                ],
                $group['items'],
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: string, label: string, icon: string, route: string, active: bool, badge: int|null, keywords: string}|null>  $items
     * @return array{label: string, items: array<int, array{id: string, label: string, icon: string, route: string, active: bool, badge: int|null, keywords: string}>}|null
     */
    private function group(string $label, array $items): ?array
    {
        $visibleItems = array_values(array_filter($items));

        return $visibleItems === [] ? null : ['label' => $label, 'items' => $visibleItems];
    }

    /**
     * @return array{id: string, label: string, icon: string, route: string, active: bool, badge: int|null, keywords: string}|null
     */
    private function item(
        string $id,
        string $label,
        string $icon,
        string $route,
        bool $active,
        ?int $badge = null,
        bool $visible = true,
        string $keywords = '',
    ): ?array {
        if (! $visible) {
            return null;
        }

        return [
            'id' => $id,
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'active' => $active,
            'badge' => $badge > 0 ? $badge : null,
            'keywords' => $keywords,
        ];
    }
}
