@php
    use Carbon\Carbon;
    use App\Models\Grants;

    $enabledGrants = \Auth::check()
        ? Grants::where('is_enabled', true)->orderBy('name')->get()
        : collect();
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
                        <li><a class="dropdown-item" href="{{ route('defense.war-aid') }}">War Aid</a></li>
                    </ul>
                </li>
            @endif
        </ul>

        <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img src="{{ Auth::user()->nation->flag }}" class="user-image rounded-circle shadow"
                         alt="User Image" style="object-fit: cover;">
                    <span class="d-none d-md-inline">{{ Auth::user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <li class="user-header text-bg-primary">
                        <img src="{{ Auth::user()->nation->flag }}" class="rounded-circle shadow" alt="User Image"
                             style="object-fit: cover;">
                        <p>
                            {{ Auth::user()->name }} - Admin
                            <small>Member since {{ Carbon::now()->subDays(Auth::user()->nation->alliance_seniority)->toFormattedDateString() }}</small>
                        </p>
                    </li>
                    <li class="user-body">
                        <div class="row">
                            <div class="col-4 text-center"><a href="#">Followers</a></div>
                            <div class="col-4 text-center"><a href="#">Sales</a></div>
                            <div class="col-4 text-center"><a href="#">Friends</a></div>
                        </div>
                    </li>
                    <li class="user-footer">
                        <a href="#" class="btn btn-default btn-flat">Profile</a>
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