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
                    <a href="{{ route('admin.dashboard') }}"
                       class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-speedometer"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                {{-- User Management --}}
                <li class="nav-item {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*') ? 'menu-open' : '' }}">
                    <a href="#"
                       class="nav-link {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                        <i class="bi bi-person-badge-fill"></i>
                        <p>
                            User Management
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ route('admin.users.index') }}"
                               class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }} {{ auth()->user()->can('view-users') ? '' : 'disabled' }}">
                                <i class="bi bi-person-lines-fill ms-3"></i>
                                <p>Manage Users</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.roles.index') }}"
                               class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }} {{ auth()->user()->can('view-roles') ? '' : 'disabled' }}">
                                <i class="bi bi-shield-lock ms-3"></i>
                                <p>Manage Roles</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.members') }}"
                       class="nav-link {{ request()->routeIs('admin.members') ? 'active' : '' }} {{ auth()->user()->can('view-members') ? '' : 'disabled' }}">
                        <i class="bi bi-people-fill"></i>
                        <p>Members</p>
                    </a>
                </li>

                <li class="nav-header">Economics</li>

                <li class="nav-item">
                    <a href="{{ route('admin.accounts.dashboard') }}"
                       class="nav-link {{ request()->routeIs('admin.accounts.*') ? 'active' : '' }} {{ auth()->user()->can('view-accounts') ? '' : 'disabled' }}">
                        <i class="bi bi-bank"></i>
                        <p>Accounts</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.grants.city') }}"
                       class="nav-link {{ request()->routeIs('admin.grants.city') ? 'active' : '' }} {{ auth()->user()->can('view-city-grants') ? '' : 'disabled' }}">
                        <i class="bi bi-houses-fill"></i>
                        <p>City Grants</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.grants') }}"
                       class="nav-link {{ request()->routeIs('admin.grants') ? 'active' : '' }} {{ auth()->user()->can('view-grants') ? '' : 'disabled' }}">
                        <i class="bi bi-bandaid-fill"></i>
                        <p>Grants</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.loans') }}"
                       class="nav-link {{ request()->routeIs('admin.loans') ? 'active' : '' }} {{ auth()->user()->can('view-loans') ? '' : 'disabled' }}">
                        <i class="bi bi-piggy-bank-fill"></i>
                        <p>Loans</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.taxes') }}"
                       class="nav-link {{ request()->routeIs('admin.taxes') ? 'active' : '' }} {{ auth()->user()->can('view-taxes') ? '' : 'disabled' }}">
                        <i class="bi bi-hand-thumbs-down-fill"></i>
                        <p>Taxes</p>
                    </a>
                </li>

                <li class="nav-header">Defense</li>

                <li class="nav-item">
                    <a href="{{ route('admin.wars') }}"
                       class="nav-link {{ request()->routeIs('admin.wars') ? 'active' : '' }} {{ auth()->user()->can('view-wars') ? '' : 'disabled' }}">
                        <i class="bi bi-speedometer"></i>
                        <p>Wars</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.war-aid') }}"
                       class="nav-link {{ request()->routeIs('admin.war-aid') ? 'active' : '' }} {{ auth()->user()->can('view-war-aid') ? '' : 'disabled' }}">
                        <i class="bi bi-wallet-fill"></i>
                        <p>War Aid</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('admin.raids.index') }}"
                       class="nav-link {{ request()->routeIs('admin.raids.*') ? 'active' : '' }} {{ auth()->user()->can('view-raids') ? '' : 'disabled' }}">
                        <i class="bi bi-currency-exchange"></i>
                        <p>Raids</p>
                    </a>
                </li>

                <li class="nav-header">System</li>

                <li class="nav-item">
                    <a href="{{ url("/telescope") }}"
                       class="nav-link {{ request()->is('telescope') ? 'active' : '' }} {{ auth()->user()->can('view-diagnostic-info') ? '' : 'disabled' }}">
                        <i class="bi bi-bug-fill"></i>
                        <p>Telescope</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ url("/pulse") }}"
                       class="nav-link {{ request()->is('pulse') ? 'active' : '' }} {{ auth()->user()->can('view-diagnostic-info') ? '' : 'disabled' }}">
                        <i class="bi bi-activity"></i>
                        <p>Pulse</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>