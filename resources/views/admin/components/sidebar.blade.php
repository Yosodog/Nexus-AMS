<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    @php
        $pendingCounts = $pendingRequests['counts'] ?? [];
        $pendingTotal = $pendingRequests['total'] ?? 0;
        $grantsPending = ($pendingCounts['city_grants'] ?? 0) + ($pendingCounts['grants'] ?? 0);
        $financePending = ($pendingCounts['withdrawals'] ?? 0);
        $warsPending = ($pendingCounts['war_aid'] ?? 0) + ($pendingCounts['rebuilding'] ?? 0);
        $grantsActive = request()->routeIs('admin.grants.city', 'admin.grants');
        $financeActive = request()->routeIs('admin.offshores.*', 'admin.finance.*', 'admin.payroll.*');
        $warsActive = request()->routeIs('admin.war-room', 'admin.wars', 'admin.war-aid', 'admin.rebuilding.*', 'admin.raids.*', 'admin.beige-alerts.*');
        $intakeActive = request()->routeIs('admin.applications.*', 'admin.recruitment.*');
        $auditsActive = request()->routeIs('admin.audits.*');
        $systemConfigActive = request()->routeIs('admin.settings', 'admin.nel.docs', 'admin.customization.*');
        $systemMonitoringActive = request()->is('telescope', 'pulse', 'log-viewer') || request()->routeIs('admin.audit-logs.*');
    @endphp
    <div class="sidebar-brand">
        <a href="{{ route('admin.dashboard') }}" class="brand-link">
            <span class="brand-text fw-light">NexusAMS - Admin</span>
        </a>
    </div>
    <div class="sidebar-wrapper">
        <nav>
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">
                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.dashboard') }}"
                                icon="bi bi-speedometer"
                                :active="request()->routeIs('admin.dashboard')">
                        Dashboard
                    </x-nav.link>
                </li>

                {{-- User Management --}}
                <li class="nav-item {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*') ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                        <i class="bi bi-person-badge-fill"></i>
                        <p>
                            User Management
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.users.index') }}"
                                        icon="bi bi-person-lines-fill ms-3"
                                        permission="view-users"
                                        :active="request()->routeIs('admin.users.*')">
                                Manage Users
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.roles.index') }}"
                                        icon="bi bi-shield-lock ms-3"
                                        permission="view-roles"
                                        :active="request()->routeIs('admin.roles.*')">
                                Manage Roles
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.members') }}"
                                icon="bi bi-people-fill"
                                permission="view-members"
                                :active="request()->routeIs('admin.members')">
                        Members
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.cities.index') }}"
                                icon="bi bi-buildings"
                                permission="view-members"
                                :active="request()->routeIs('admin.cities.*')">
                        Cities
                    </x-nav.link>
                </li>

                <li class="nav-header">Economics</li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.accounts.dashboard') }}"
                                icon="bi bi-bank"
                                permission="view-accounts"
                                :active="request()->routeIs('admin.accounts.*')"
                                :badge="($pendingCounts['withdrawals'] ?? 0) > 0 ? $pendingCounts['withdrawals'] : null">
                        Accounts
                    </x-nav.link>
                </li>


                <li class="nav-item {{ $grantsActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $grantsActive ? 'active' : '' }}">
                        <i class="bi bi-houses-fill"></i>
                        <p class="d-flex align-items-center gap-2 w-100">
                            <span class="flex-grow-1">Grants</span>
                            <span class="d-flex align-items-center gap-2">
                                @if($grantsPending > 0)
                                    <span class="badge bg-primary">{{ $grantsPending }}</span>
                                @endif
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.grants.city') }}"
                                        icon="bi bi-houses-fill ms-3"
                                        permission="view-city-grants"
                                        :active="request()->routeIs('admin.grants.city')"
                                        :badge="($pendingCounts['city_grants'] ?? 0) > 0 ? $pendingCounts['city_grants'] : null">
                                City Grants
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.grants') }}"
                                        icon="bi bi-bandaid-fill ms-3"
                                        permission="view-grants"
                                        :active="request()->routeIs('admin.grants')"
                                        :badge="($pendingCounts['grants'] ?? 0) > 0 ? $pendingCounts['grants'] : null">
                                Grants
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.loans') }}"
                                icon="bi bi-piggy-bank-fill"
                                permission="view-loans"
                                :active="request()->routeIs('admin.loans')"
                                :badge="($pendingCounts['loans'] ?? 0) > 0 ? $pendingCounts['loans'] : null">
                        Loans
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.taxes') }}"
                                icon="bi bi-hand-thumbs-down-fill"
                                permission="view-taxes"
                                :active="request()->routeIs('admin.taxes')">
                        Taxes
                    </x-nav.link>
                </li>

                <li class="nav-item {{ $financeActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $financeActive ? 'active' : '' }}">
                        <i class="bi bi-cash-stack"></i>
                        <p class="d-flex align-items-center gap-2 w-100">
                            <span class="flex-grow-1">Finance</span>
                            <span class="d-flex align-items-center gap-2">
                                @if($financePending > 0)
                                    <span class="badge bg-primary">{{ $financePending }}</span>
                                @endif
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.offshores.index') }}"
                                        icon="bi bi-compass ms-3"
                                        permission="view-offshores"
                                        :active="request()->routeIs('admin.offshores.*')">
                                Offshores
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.finance.index') }}"
                                        icon="bi bi-cash-coin ms-3"
                                        permission="view-financial-reports"
                                        :active="request()->routeIs('admin.finance.*')">
                                Finance Ledger
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.payroll.index') }}"
                                        icon="bi bi-cash-stack ms-3"
                                        permission="view_payroll"
                                        :active="request()->routeIs('admin.payroll.*')">
                                Payroll
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.market.index') }}"
                                        icon="bi bi-shop-window ms-3"
                                        permission="view-market"
                                        :active="request()->routeIs('admin.market.*')">
                                Alliance Market
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-header">Defense</li>

                <li class="nav-item {{ $warsActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $warsActive ? 'active' : '' }}">
                        <i class="bi bi-command"></i>
                        <p class="d-flex align-items-center gap-2 w-100">
                            <span class="flex-grow-1">Wars</span>
                            <span class="d-flex align-items-center gap-2">
                                @if($warsPending > 0)
                                    <span class="badge bg-primary">{{ $warsPending }}</span>
                                @endif
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.war-room') }}"
                                        icon="bi bi-command ms-3"
                                        permission="view-wars"
                                        :active="request()->routeIs('admin.war-room')">
                                War Room
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.spy-campaigns.index') }}"
                                        icon="bi bi-eye ms-3"
                                        permission="view-spies"
                                        :active="request()->routeIs('admin.spy-campaigns.*')">
                                Spy Campaigns
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.wars') }}"
                                        icon="bi bi-speedometer ms-3"
                                        permission="view-wars"
                                        :active="request()->routeIs('admin.wars')">
                                Wars
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.war-aid') }}"
                                        icon="bi bi-wallet-fill ms-3"
                                        permission="view-war-aid"
                                        :active="request()->routeIs('admin.war-aid')"
                                        :badge="($pendingCounts['war_aid'] ?? 0) > 0 ? $pendingCounts['war_aid'] : null">
                                War Aid
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.rebuilding.index') }}"
                                        icon="bi bi-hammer ms-3"
                                        permission="view-rebuilding"
                                        :active="request()->routeIs('admin.rebuilding.*')"
                                        :badge="($pendingCounts['rebuilding'] ?? 0) > 0 ? $pendingCounts['rebuilding'] : null">
                                Rebuilding
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.raids.index') }}"
                                        icon="bi bi-currency-exchange ms-3"
                                        permission="view-raids"
                                        :active="request()->routeIs('admin.raids.*')">
                                Raids
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.beige-alerts.index') }}"
                                        icon="bi bi-bell ms-3"
                                        permission="view-raids"
                                        :active="request()->routeIs('admin.beige-alerts.*')">
                                Beige Alerts
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.mmr.index') }}"
                                icon="bi bi-basket-fill"
                                permission="view-raids"
                                :active="request()->routeIs('admin.mmr.*')">
                        MMR
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.spy-campaigns.index') }}"
                                icon="bi bi-eye"
                                permission="view-spies"
                                :active="request()->routeIs('admin.spy-campaigns.*')">
                        Spy Campaigns
                    </x-nav.link>
                </li>

                <li class="nav-header">Internal Affairs</li>

                <li class="nav-item {{ $intakeActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $intakeActive ? 'active' : '' }}">
                        <i class="bi bi-people"></i>
                        <p>
                            Intake
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.applications.index') }}"
                                        icon="bi bi-people ms-3"
                                        permission="view-applications"
                                        :active="request()->routeIs('admin.applications.*')">
                                Applications
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.recruitment.index') }}"
                                        icon="bi bi-envelope-paper ms-3"
                                        permission="view-recruitment"
                                        :active="request()->routeIs('admin.recruitment.*')">
                                Recruitment
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.audits.index') }}"
                                icon="bi bi-shield-check"
                                permission="view-diagnostic-info"
                                :active="request()->routeIs('admin.audits.*')">
                        Audits
                    </x-nav.link>
                </li>

                <li class="nav-header">System</li>

                <li class="nav-item {{ $systemConfigActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $systemConfigActive ? 'active' : '' }}">
                        <i class="bi bi-gear"></i>
                        <p>
                            Configuration
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.settings') }}"
                                        icon="bi bi-gear ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->routeIs('admin.settings')">
                                Settings
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.nel.docs') }}"
                                        icon="bi bi-braces-asterisk ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->routeIs('admin.nel.docs')">
                                NEL Docs
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.customization.index') }}"
                                        icon="bi bi-palette ms-3"
                                        permission="manage-custom-pages"
                                        :active="request()->routeIs('admin.customization.*')">
                                Customize Pages
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item {{ $systemMonitoringActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $systemMonitoringActive ? 'active' : '' }}">
                        <i class="bi bi-activity"></i>
                        <p>
                            Monitoring
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.audit-logs.index') }}"
                                        icon="bi bi-clipboard-data ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->routeIs('admin.audit-logs.*')">
                                Audit Logs
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ url('/telescope') }}"
                                        icon="bi bi-bug-fill ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->is('telescope')">
                                Telescope
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ url('/pulse') }}"
                                        icon="bi bi-activity ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->is('pulse')">
                                Pulse
                            </x-nav.link>
                        </li>

                        <li class="nav-item">
                            <x-nav.link href="{{ url('/log-viewer') }}"
                                        icon="bi bi-diagram-2-fill ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->is('log-viewer')">
                                Log Viewer
                            </x-nav.link>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>
    </div>
</aside>
