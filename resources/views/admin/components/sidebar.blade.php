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
                    <a href="{{ route('admin.dashboard') }}" class="nav-link">
                        <i class="nav-icon bi bi-speedometer"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.members') }}" class="nav-link">
                        <i class="bi bi-people-fill"></i>
                        <p>Members</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.accounts.dashboard') }}" class="nav-link">
                        <i class="bi bi-bank"></i>
                        <p>Accounts</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.grants.city') }}" class="nav-link">
                        <i class="bi bi-houses-fill"></i>
                        <p>City Grants</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.grants') }}" class="nav-link">
                        <i class="bi bi-bandaid-fill"></i>
                        <p>Grants</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.loans') }}" class="nav-link">
                        <i class="bi bi-piggy-bank-fill"></i>
                        <p>Loans</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.taxes') }}" class="nav-link">
                        <i class="bi bi-hand-thumbs-down-fill"></i>
                        <p>Taxes</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ url("/telescope") }}" class="nav-link">
                        <i class="bi bi-bug-fill"></i>
                        <p>Telescope</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>
