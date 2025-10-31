<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
        <a href="{{ route('admin.dashboard') }}" class="brand-link">
            <span class="brand-text fw-light">NexusAMS - Admin</span>
        </a>
    </div>
    <div class="sidebar-wrapper" data-simplebar>
        <nav>
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">
                <li class="nav-header">Command Center</li>
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

                <li class="nav-header">Economics</li>

                @php
                    $economyActive = request()->routeIs(
                        'admin.accounts.*',
                        'admin.offshores.*',
                        'admin.loans*',
                        'admin.taxes*'
                    );
                    $aidActive = request()->routeIs('admin.grants*', 'admin.grants.city');
                @endphp

                <li class="nav-item {{ $economyActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $economyActive ? 'active' : '' }}">
                        <i class="bi bi-cash-stack"></i>
                        <p>
                            Economic Ops
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.accounts.dashboard') }}"
                                        icon="bi bi-bank ms-3"
                                        permission="view-accounts"
                                        :active="request()->routeIs('admin.accounts.*')">
                                Accounts
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.offshores.index') }}"
                                        icon="bi bi-compass ms-3"
                                        permission="view-offshores"
                                        :active="request()->routeIs('admin.offshores.*')">
                                Offshores
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.loans') }}"
                                        icon="bi bi-piggy-bank-fill ms-3"
                                        permission="view-loans"
                                        :active="request()->routeIs('admin.loans*')">
                                Loans
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.taxes') }}"
                                        icon="bi bi-hand-thumbs-down-fill ms-3"
                                        permission="view-taxes"
                                        :active="request()->routeIs('admin.taxes*')">
                                Taxes
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-item {{ $aidActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $aidActive ? 'active' : '' }}">
                        <i class="bi bi-bandaid-fill"></i>
                        <p>
                            Aid Programs
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.grants') }}"
                                        icon="bi bi-gift-fill ms-3"
                                        permission="view-grants"
                                        :active="request()->routeIs('admin.grants*')">
                                Grants
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.grants.city') }}"
                                        icon="bi bi-houses-fill ms-3"
                                        permission="view-city-grants"
                                        :active="request()->routeIs('admin.grants.city')">
                                City Grants
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-header">Defense</li>

                @php
                    $defenseActive = request()->routeIs(
                        'admin.wars*',
                        'admin.war-aid*',
                        'admin.raids.*',
                        'admin.mmr.*'
                    );
                @endphp

                <li class="nav-item {{ $defenseActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $defenseActive ? 'active' : '' }}">
                        <i class="bi bi-shield-shaded"></i>
                        <p>
                            Operations Hub
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.wars') }}"
                                        icon="bi bi-lightning-charge-fill ms-3"
                                        permission="view-wars"
                                        :active="request()->routeIs('admin.wars*')">
                                Wars
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
                            <x-nav.link href="{{ route('admin.mmr.index') }}"
                                        icon="bi bi-basket-fill ms-3"
                                        permission="view-raids"
                                        :active="request()->routeIs('admin.mmr.*')">
                                MMR
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.war-aid') }}"
                                        icon="bi bi-wallet-fill ms-3"
                                        permission="view-war-aid"
                                        :active="request()->routeIs('admin.war-aid*')">
                                War Aid
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-header">Internal Affairs</li>

                @php
                    $internalActive = request()->routeIs(
                        'admin.members*',
                        'admin.cities.*',
                        'admin.recruitment.*'
                    );
                @endphp

                <li class="nav-item {{ $internalActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $internalActive ? 'active' : '' }}">
                        <i class="bi bi-people-fill"></i>
                        <p>
                            Alliance Admin
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.members') }}"
                                        icon="bi bi-person-rolodex ms-3"
                                        permission="view-members"
                                        :active="request()->routeIs('admin.members*')">
                                Members
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.cities.index') }}"
                                        icon="bi bi-buildings ms-3"
                                        permission="view-members"
                                        :active="request()->routeIs('admin.cities.*')">
                                Cities
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

                <li class="nav-header">System</li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.settings') }}"
                                icon="bi bi-gear"
                                permission="view-diagnostic-info"
                                :active="request()->routeIs('admin.settings')">
                        Settings
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

                @php
                    $diagnosticsActive = request()->is('telescope*') || request()->is('pulse*');
                @endphp

                <li class="nav-item {{ $diagnosticsActive ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ $diagnosticsActive ? 'active' : '' }}">
                        <i class="bi bi-activity"></i>
                        <p>
                            Diagnostics
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <x-nav.link href="{{ url('/telescope') }}"
                                        icon="bi bi-bug-fill ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->is('telescope*')">
                                Telescope
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ url('/pulse') }}"
                                        icon="bi bi-activity ms-3"
                                        permission="view-diagnostic-info"
                                        :active="request()->is('pulse*')">
                                Pulse
                            </x-nav.link>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>
    </div>
</aside>
