<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
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

                <li class="nav-header">Economics</li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.accounts.dashboard') }}"
                                icon="bi bi-bank"
                                permission="view-accounts"
                                :active="request()->routeIs('admin.accounts.*')">
                        Accounts
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.grants.city') }}"
                                icon="bi bi-houses-fill"
                                permission="view-city-grants"
                                :active="request()->routeIs('admin.grants.city')">
                        City Grants
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.grants') }}"
                                icon="bi bi-bandaid-fill"
                                permission="view-grants"
                                :active="request()->routeIs('admin.grants')">
                        Grants
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.loans') }}"
                                icon="bi bi-piggy-bank-fill"
                                permission="view-loans"
                                :active="request()->routeIs('admin.loans')">
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

                <li class="nav-header">Defense</li>

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
                                :active="request()->routeIs('admin.war-aid')">
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
                    <x-nav.link href="{{ route('admin.settings') }}"
                                icon="bi bi-gear"
                                permission="view-diagnostic-info"
                                :active="request()->is('telescope')">
                        Settings
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
            </ul>
        </nav>
    </div>
</aside>