@php
    $generalActive = request()->routeIs('admin.dashboard')
        || request()->routeIs('admin.users.*')
        || request()->routeIs('admin.roles.*');

    $economicsActive = request()->routeIs('admin.accounts.*')
        || request()->routeIs('admin.offshores.*')
        || request()->routeIs('admin.grants.city')
        || request()->routeIs('admin.grants')
        || request()->routeIs('admin.loans')
        || request()->routeIs('admin.taxes');

    $defenseActive = request()->routeIs('admin.wars')
        || request()->routeIs('admin.war-aid')
        || request()->routeIs('admin.raids.*')
        || request()->routeIs('admin.mmr.*');

    $internalAffairsActive = request()->routeIs('admin.members')
        || request()->routeIs('admin.cities.*')
        || request()->routeIs('admin.recruitment.*');

    $systemActive = request()->routeIs('admin.settings')
        || request()->routeIs('admin.customization.*')
        || request()->is('telescope')
        || request()->is('pulse');
@endphp

<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
        <a href="{{ route('admin.dashboard') }}" class="brand-link">
            <span class="brand-text fw-light">NexusAMS - Admin</span>
        </a>
    </div>
    <div class="sidebar-wrapper">
        <nav>
            <ul class="nav sidebar-menu flex-column gap-1" data-lte-toggle="treeview" role="menu">
                <li class="px-3 pt-3 pb-2" role="presentation">
                    <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold small {{ $generalActive ? 'text-primary' : 'text-secondary' }}">
                        <i class="bi bi-grid-fill"></i>
                        Command Center
                    </div>
                    <p class="text-muted small mb-0">Quick access to day-to-day alliance tools.</p>
                </li>
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
                            <span class="badge text-bg-primary ms-2">Team</span>
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
                                <small class="d-block text-muted">Audit permissions &amp; status</small>
                            </x-nav.link>
                        </li>
                        <li class="nav-item">
                            <x-nav.link href="{{ route('admin.roles.index') }}"
                                        icon="bi bi-shield-lock ms-3"
                                        permission="view-roles"
                                        :active="request()->routeIs('admin.roles.*')">
                                Manage Roles
                                <small class="d-block text-muted">Policies, ranks &amp; access</small>
                            </x-nav.link>
                        </li>
                    </ul>
                </li>

                <li class="nav-divider border-top border-secondary-subtle mx-3"></li>

                <li class="px-3 pt-3 pb-2" role="presentation">
                    <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold small {{ $economicsActive ? 'text-primary' : 'text-secondary' }}">
                        <i class="bi bi-cash-coin"></i>
                        Economics
                    </div>
                    <p class="text-muted small mb-0">Finance, grants and revenue flows.</p>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.accounts.dashboard') }}"
                                icon="bi bi-bank"
                                permission="view-accounts"
                                :active="request()->routeIs('admin.accounts.*')">
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

                <li class="nav-divider border-top border-secondary-subtle mx-3"></li>

                <li class="px-3 pt-3 pb-2" role="presentation">
                    <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold small {{ $defenseActive ? 'text-primary' : 'text-secondary' }}">
                        <i class="bi bi-shield-shaded"></i>
                        Defense
                    </div>
                    <p class="text-muted small mb-0">Preparedness, conflict, and rapid response.</p>
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
                        <span class="badge text-bg-warning text-dark ms-2">Live</span>
                    </x-nav.link>
                </li>

                <li class="nav-divider border-top border-secondary-subtle mx-3"></li>

                <li class="px-3 pt-3 pb-2" role="presentation">
                    <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold small {{ $internalAffairsActive ? 'text-primary' : 'text-secondary' }}">
                        <i class="bi bi-people"></i>
                        Internal Affairs
                    </div>
                    <p class="text-muted small mb-0">Member services and city logistics.</p>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.members') }}"
                                icon="bi bi-people-fill"
                                permission="view-members"
                                :active="request()->routeIs('admin.members')">
                        Members
                        <small class="d-block text-muted">Rosters, activity, onboarding</small>
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.cities.index') }}"
                                icon="bi bi-buildings"
                                permission="view-members"
                                :active="request()->routeIs('admin.cities.*')">
                        Cities
                        <small class="d-block text-muted">Infrastructure snapshots</small>
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.recruitment.index') }}"
                                icon="bi bi-envelope-paper"
                                permission="view-recruitment"
                                :active="request()->routeIs('admin.recruitment.*')">
                        Recruitment
                        <small class="d-block text-muted">Applicant pipeline &amp; outreach</small>
                    </x-nav.link>
                </li>

                <li class="nav-divider border-top border-secondary-subtle mx-3"></li>

                <li class="px-3 pt-3 pb-2" role="presentation">
                    <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold small {{ $systemActive ? 'text-primary' : 'text-secondary' }}">
                        <i class="bi bi-hdd-network"></i>
                        System
                    </div>
                    <p class="text-muted small mb-0">Configuration, customization, and diagnostics.</p>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.settings') }}"
                                icon="bi bi-gear"
                                permission="view-diagnostic-info"
                                :active="request()->routeIs('admin.settings')">
                        Settings
                        <small class="d-block text-muted">Alliance configuration &amp; automation</small>
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ route('admin.customization.index') }}"
                                icon="bi bi-palette"
                                permission="manage-custom-pages"
                                :active="request()->routeIs('admin.customization.*')">
                        Customize Pages
                        <small class="d-block text-muted">Landing pages &amp; portals</small>
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ url('/telescope') }}"
                                icon="bi bi-bug-fill"
                                permission="view-diagnostic-info"
                                :active="request()->is('telescope')">
                        Telescope
                        <small class="d-block text-muted">Laravel debugging suite</small>
                    </x-nav.link>
                </li>

                <li class="nav-item">
                    <x-nav.link href="{{ url('/pulse') }}"
                                icon="bi bi-activity"
                                permission="view-diagnostic-info"
                                :active="request()->is('pulse')">
                        Pulse
                        <small class="d-block text-muted">Queue &amp; scheduling monitor</small>
                    </x-nav.link>
                </li>
            </ul>
        </nav>
    </div>
</aside>
