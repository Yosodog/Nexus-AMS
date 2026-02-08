@php
    use Carbon\Carbon;
    use App\Models\Grants;
    use Illuminate\Support\Facades\Auth;

    $enabledGrants = Auth::check()
        ? Grants::where('is_enabled', true)->orderBy('name')->get()
        : collect();

    $nation = Auth::user()?->nation;

    $abbreviateNumber = function (?float $value): string {
        if ($value === null) {
            return '-';
        }

        $absValue = abs($value);
        $suffixes = [
            1_000_000_000_000 => 'T',
            1_000_000_000 => 'B',
            1_000_000 => 'M',
            1_000 => 'K',
        ];

        foreach ($suffixes as $threshold => $suffix) {
            if ($absValue >= $threshold) {
                return number_format($value / $threshold, 1).$suffix;
            }
        }

        return number_format($value, 0);
    };

    $abbreviateCurrency = function (?float $value) use ($abbreviateNumber): string {
        if ($value === null) {
            return '-';
        }

        $formatted = $abbreviateNumber(abs($value));

        return ($value < 0 ? '-$' : '$').$formatted;
    };
@endphp

<nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="{{ route('home') }}" class="nav-link">Home</a>
            </li>

            @if(Auth::check())
                <li class="nav-item d-none d-md-block">
                    <a href="{{ route('accounts') }}" class="nav-link">Accounts</a>
                </li>

                <li class="nav-item d-none d-md-block">
                    <a href="{{ route('audit.index') }}" class="nav-link">Audits</a>
                </li>

                <li class="nav-item dropdown d-none d-md-block">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Grants</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="{{ route('grants.city') }}">City Grants</a></li>
                        @foreach($enabledGrants as $grant)
                            <li>
                                <a class="dropdown-item" href="{{ route('grants.show_grants', $grant->slug) }}">
                                    {{ ucwords($grant->name) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>

                <li class="nav-item d-none d-md-block">
                    <a href="{{ route('loans.index') }}" class="nav-link">Loans</a>
                </li>

                <li class="nav-item dropdown d-none d-md-block">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Defense</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="{{ route('defense.counters') }}">Counter Finder</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.intel') }}">Intel Library</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.war-aid') }}">War Aid</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.war-stats') }}">War Stats</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.simulators') }}">War Simulators</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.raid-leaderboard') }}">Raid Leaderboard</a></li>
                        <li><a class="dropdown-item" href="{{ route('defense.raid-finder') }}">Raid Finder</a></li>
                    </ul>
                </li>
            @endif
        </ul>

        <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
                <a href="#" class="nav-link" data-bs-toggle="dropdown" role="button">
                    <i class="bi bi-sun-fill theme-icon-active"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" data-set-theme="light"><i class="bi bi-sun-fill me-2"></i>
                            Light</a></li>
                    <li><a class="dropdown-item" href="#" data-set-theme="night"><i class="bi bi-moon-fill me-2"></i>
                            Dark</a></li>
                    <li><a class="dropdown-item" href="#" data-set-theme="auto"><i class="bi bi-circle-half me-2"></i>
                            Auto</a></li>
                </ul>
            </li>
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img src="{{ $nation->flag }}" class="user-image rounded-circle shadow" alt="Nation Flag"
                        style="object-fit: cover;">
                    <span class="d-none d-md-inline">{{ $nation->leader_name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <li class="user-header text-bg-primary bg-gradient">
                        <img src="{{ $nation->flag }}" class="rounded-circle shadow" alt="Nation Flag"
                            style="object-fit: cover;">
                        <p>
                            {{ $nation->leader_name }}
                            <span class="d-block small opacity-75">{{ $nation->nation_name }}</span>
                            <span
                                class="badge text-bg-light text-uppercase me-1">{{ $nation->alliance_position ?? 'Member' }}</span>
                            <small>Member
                                since
                                {{ Carbon::now()->subDays($nation->alliance_seniority)->toFormattedDateString() }}</small>
                        </p>
                    </li>
                    <li class="user-body">
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="border rounded text-center py-2 h-100">
                                    <div class="text-muted small">Cities</div>
                                    <div class="fw-semibold">{{ $nation->num_cities }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded text-center py-2 h-100">
                                    <div class="text-muted small">Score</div>
                                    <div class="fw-semibold">{{ number_format($nation->score, 2) }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded text-center py-2 h-100">
                                    <div class="text-muted small">Nation ID</div>
                                    <div class="fw-semibold">{{ Auth::user()->nation_id }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-secondary">
                                    <i class="bi bi-shield-fill me-1"></i>
                                    Defense {{ $nation->defensive_wars_count }}
                                </span>
                                <span class="badge text-bg-secondary">
                                    <i class="bi bi-lightning-fill me-1"></i>
                                    Offense {{ $nation->offensive_wars_count }}
                                </span>
                                <span class="badge text-bg-secondary">
                                    <i class="bi bi-trophy-fill me-1"></i>
                                    Wins {{ $nation->wars_won }}
                                </span>
                                <span class="badge text-bg-secondary">
                                    <i class="bi bi-x-circle-fill me-1"></i>
                                    Losses {{ $nation->wars_lost }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="border rounded text-center py-2 h-100">
                                        <div class="text-muted small">Population</div>
                                        <div class="fw-semibold">{{ $abbreviateNumber($nation->population) }}</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded text-center py-2 h-100">
                                        <div class="text-muted small">GNI</div>
                                        <div class="fw-semibold">
                                            {{ $abbreviateCurrency($nation->gross_national_income) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="list-group list-group-flush">
                                <a href="{{ route('user.dashboard') }}"
                                    class="list-group-item list-group-item-action d-flex align-items-center">
                                    <i class="bi bi-speedometer2 me-2"></i>
                                    <span>User Dashboard</span>
                                    <span class="ms-auto text-muted small">View</span>
                                </a>
                                <a href="{{ route('admin.dashboard') }}"
                                    class="list-group-item list-group-item-action d-flex align-items-center">
                                    <i class="bi bi-layout-sidebar-inset-reverse me-2"></i>
                                    <span>Admin Overview</span>
                                    <span class="ms-auto text-muted small">Open</span>
                                </a>
                                <a href="{{ route('admin.members.show', Auth::user()->nation_id) }}"
                                    class="list-group-item list-group-item-action d-flex align-items-center">
                                    <i class="bi bi-person-badge-fill me-2"></i>
                                    <span>Member Profile</span>
                                    <span class="ms-auto text-muted small">Details</span>
                                </a>
                            </div>
                        </div>
                    </li>
                    <li class="user-footer">
                        <a href="{{ route('user.dashboard') }}" class="btn btn-primary btn-flat">Dashboard</a>
                        <a href="{{ route('admin.members.show', Auth::user()->nation_id) }}"
                            class="btn btn-default btn-flat">Profile</a>
                        <a class="btn btn-default btn-flat float-end"
                            onclick="event.preventDefault(); document.getElementById('admin-logout-form').submit();">Logout</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<form id="admin-logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
    @csrf
</form>
