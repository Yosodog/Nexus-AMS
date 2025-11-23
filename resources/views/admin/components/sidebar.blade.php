<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    @php
        $pendingCounts = $pendingRequests['counts'] ?? [];
        $pendingTotal = $pendingRequests['total'] ?? 0;
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

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.offshores.index') }}"
                                icon="bi bi-compass"
                                permission="view-offshores"
                                :active="request()->routeIs('admin.offshores.*')">
                        Offshores
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.grants.city') }}"
                                icon="bi bi-houses-fill"
                                permission="view-city-grants"
                                :active="request()->routeIs('admin.grants.city')"
                                :badge="($pendingCounts['city_grants'] ?? 0) > 0 ? $pendingCounts['city_grants'] : null">
                        City Grants
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.grants') }}"
                                icon="bi bi-bandaid-fill"
                                permission="view-grants"
                                :active="request()->routeIs('admin.grants')"
                                :badge="($pendingCounts['grants'] ?? 0) > 0 ? $pendingCounts['grants'] : null">
                        Grants
                    </x-nav.link>
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

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.finance.index') }}"
                                icon="bi bi-cash-coin"
                                permission="view-financial-reports"
                                :active="request()->routeIs('admin.finance.*')">
                        Finance Ledger
                    </x-nav.link>
                </li>

                <li class="nav-header">Defense</li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.war-room') }}"
                                icon="bi bi-command"
                                permission="view-wars"
                                :active="request()->routeIs('admin.war-room')">
                        War Room
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.wars') }}"
                                icon="bi bi-speedometer"
                                permission="view-wars"
                                :active="request()->routeIs('admin.wars')">
                        Wars
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.war-aid') }}"
                                icon="bi bi-wallet-fill"
                                permission="view-war-aid"
                                :active="request()->routeIs('admin.war-aid')"
                                :badge="($pendingCounts['war_aid'] ?? 0) > 0 ? $pendingCounts['war_aid'] : null">
                        War Aid
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.raids.index') }}"
                                icon="bi bi-currency-exchange"
                                permission="view-raids"
                                :active="request()->routeIs('admin.raids.*')">
                        Raids
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.mmr.index') }}"
                                icon="bi bi-basket-fill"
                                permission="view-raids"
                                :active="request()->routeIs('admin.mmr.*')">
                        MMR
                    </x-nav.link>
                </li>

                <li class="nav-header">System</li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.recruitment.index') }}"
                                icon="bi bi-envelope-paper"
                                permission="view-recruitment"
                                :active="request()->routeIs('admin.recruitment.*')">
                        Recruitment
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.settings') }}"
                                icon="bi bi-gear"
                                permission="view-diagnostic-info"
                                :active="request()->routeIs('admin.settings')">
                        Settings
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.nel.docs') }}"
                                icon="bi bi-braces-asterisk"
                                permission="view-diagnostic-info"
                                :active="request()->routeIs('admin.nel.docs')">
                        NEL Docs
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.customization.index') }}"
                                icon="bi bi-palette"
                                permission="manage-custom-pages"
                                :active="request()->routeIs('admin.customization.*')">
                        Customize Pages
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ url('/telescope') }}"
                                icon="bi bi-bug-fill"
                                permission="view-diagnostic-info"
                                :active="request()->is('telescope')">
                        Telescope
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ url('/pulse') }}"
                                icon="bi bi-activity"
                                permission="view-diagnostic-info"
                                :active="request()->is('pulse')">
                        Pulse
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ url('/log-viewer') }}"
                                icon="bi bi-diagram-2-fill"
                                permission="view-diagnostic-info"
                                :active="request()->is('pulse')">
                        Log Viewer
                    </x-nav.link>
                </li>
            </ul>
        </nav>
    </div>
</aside>
