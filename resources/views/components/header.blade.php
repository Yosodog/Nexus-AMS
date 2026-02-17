@php
    use App\Models\Grants;
    use App\Services\AllianceMembershipService;
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();
    $pendingRequests = $pendingRequests ?? ['counts' => [], 'total' => 0];
    $pendingTotal = $pendingRequests['total'] ?? 0;
    $showPendingIndicator = $user && $pendingTotal > 0;
    $membershipService = app(AllianceMembershipService::class);
    $allianceId = data_get($user, 'nation.alliance_id');
    $showMemberNavigation = $user !== null && $membershipService->contains($allianceId);
    $enabledGrants = $showMemberNavigation
        ? Grants::query()->where('is_enabled', true)->orderBy('name')->get()
        : collect();
@endphp
<div class="w-full bg-base-100">
    <div class="container mx-auto px-2 sm:px-4">
        <div class="navbar relative gap-1 sm:gap-2">
            <div class="navbar-start min-w-0 gap-1">
                <div class="dropdown">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-circle shrink-0 text-base-content lg:hidden" aria-label="Open navigation">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h8m-8 6h16" />
                        </svg>
                    </div>
                    @if ($showMemberNavigation)
                        <ul tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                            <li><a href="{{ route("accounts") }}">Accounts</a></li>
                            <li><a href="{{ route('market.index') }}">Market</a></li>
                            <li><a href="{{ route('audit.index') }}">Audits</a></li>
                            <li>
                                <details>
                                    <summary>Grants</summary>
                                    <ul class="p-2">
                                        <li><a href="{{ route("grants.city") }}">City Grants</a></li>
                                        @foreach ($enabledGrants as $grant)
                                            <li>
                                                <a
                                                    href="{{ route('grants.show_grants', $grant->slug) }}">{{ ucwords($grant->name) }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                            <li><a href="{{ route("loans.index") }}">Loans</a></li>
                            <li>
                                <details>
                                    <summary>Defense</summary>
                                    <ul class="p-2">
                                        <li><a href="{{ route("defense.counters") }}">Counter Finder</a></li>
                                        <li><a href="{{ route("defense.intel") }}">Intel Library</a></li>
                                        <li><a href="{{ route("defense.war-aid") }}">War Aid</a></li>
                                        <li><a href="{{ route("defense.rebuilding") }}">Rebuilding</a></li>
                                        <li><a href="{{ route("defense.war-stats") }}">War Stats</a></li>
                                        <li><a href="{{ route("defense.simulators") }}">War Simulators</a></li>
                                        <li><a href="{{ route("defense.raid-leaderboard") }}">Raid Leaderboard</a></li>
                                        <li><a href="{{ route("defense.raid-finder") }}">Raid Finder</a></li>
                                    </ul>
                                </details>
                            </li>
                        </ul>
                    @elseif($user)
                        <ul tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                            <li><a href="{{ route('apply.show') }}">Apply</a></li>
                        </ul>
                    @else
                        <ul tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                            <li><a href="{{ route('apply.show') }}">Apply</a></li>
                        </ul>
                    @endif

                </div>
                <a class="btn btn-ghost max-w-[8.5rem] truncate px-2 text-base sm:max-w-none sm:px-3 sm:text-xl" href="{{ route("home") }}">{{ config('app.name') }}</a>
            </div>
            {{-- End mobile nav and begin desktop nav --}}
            <div class="navbar-center hidden lg:absolute lg:left-1/2 lg:flex lg:-translate-x-1/2">
                @if ($showMemberNavigation)
                    <ul class="menu menu-horizontal px-1 z-50">
                        <li><a href="{{ route("accounts") }}">Accounts</a></li>
                        <li><a href="{{ route('market.index') }}">Market</a></li>
                        <li><a href="{{ route('audit.index') }}">Audits</a></li>
                        <li>
                            <details class="relative">
                                <summary
                                    class="cursor-pointer px-4 py-2 text-base-content hover:bg-base-200 rounded-md transition">
                                    Grants
                                </summary>
                                <ul class="absolute left-0 mt-2 w-64 menu bg-base-100 p-2 shadow rounded-box z-[1]">
                                    <li><a href="{{ route("grants.city") }}">City Grants</a></li>
                                    @foreach ($enabledGrants as $grant)
                                        <li>
                                            <a
                                                href="{{ route('grants.show_grants', $grant->slug) }}">{{ ucwords($grant->name) }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                        <li><a href="{{ route("loans.index") }}">Loans</a></li>
                        <li>
                            <details class="relative">
                                <summary
                                    class="cursor-pointer px-4 py-2 text-base-content hover:bg-base-200 rounded-md transition">
                                    Defense
                                </summary>
                                <ul class="absolute left-0 mt-2 w-64 menu bg-base-100 p-2 shadow rounded-box z-[1]">
                                    <li><a href="{{ route("defense.counters") }}">Counter Finder</a></li>
                                    <li><a href="{{ route("defense.intel") }}">Intel Library</a></li>
                                    <li><a href="{{ route("defense.war-aid") }}">War Aid</a></li>
                                    <li><a href="{{ route("defense.rebuilding") }}">Rebuilding</a></li>
                                    <li><a href="{{ route("defense.war-stats") }}">War Stats</a></li>
                                    <li><a href="{{ route("defense.simulators") }}">War Simulators</a></li>
                                    <li><a href="{{ route("defense.raid-leaderboard") }}">Raid Leaderboard</a></li>
                                    <li><a href="{{ route("defense.raid-finder") }}">Raid Finder</a></li>
                                </ul>
                            </details>
                        </li>
                    </ul>
                @elseif($user)
                    <ul class="menu menu-horizontal px-1">
                        <li><a href="{{ route('apply.show') }}">Apply</a></li>
                    </ul>
                @else
                    <ul class="menu menu-horizontal px-1">
                        <li><a href="{{ route('apply.show') }}">Apply</a></li>
                    </ul>
                @endif
            </div>
            <div class="navbar-end shrink-0 gap-1 sm:gap-2">
                <!-- Theme Dropdown -->
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-circle">
                        <!-- Sun Icon -->
                        <svg class="theme-icon-sun h-6 w-6 fill-current sm:h-7 sm:w-7" xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24">
                            <path
                                d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z" />
                        </svg>

                        <!-- Moon Icon -->
                        <svg class="theme-icon-moon hidden h-6 w-6 fill-current sm:h-7 sm:w-7" xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24">
                            <path
                                d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z" />
                        </svg>
                    </div>
                    <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                        <li>
                            <a data-set-theme="light">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2 fill-current" viewBox="0 0 24 24"><path d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/></svg>
                                Light
                            </a>
                        </li>
                        <li>
                            <a data-set-theme="night">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2 fill-current" viewBox="0 0 24 24"><path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z"/></svg>
                                Dark
                            </a>
                        </li>
                        <li>
                            <a data-set-theme="auto">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2 fill-current" viewBox="0 0 24 24"><path d="M12,2A7,7,0,0,0,5,9c0,5.25,7,13,7,13s7-7.75,7-13A7,7,0,0,0,12,2Zm0,17.3c-2.07-2.9-5-6.86-5-10.3A5,5,0,0,1,12,4a5,5,0,0,1,5,5C17,12.44,14.07,16.4,12,19.3ZM12,6a3,3,0,1,0,3,3A3,3,0,0,0,12,6Z"/></svg>
                                Auto
                            </a>
                        </li>
                    </ul>
                </div>
                @if ($user)
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-ghost btn-circle avatar">
                            <div
                                class="w-10 rounded-full {{ $showPendingIndicator ? 'ring ring-primary ring-offset-base-100 ring-offset-2' : '' }}">
                                <img alt="{{ data_get($user, 'nation.leader_name', 'User') }} Flag"
                                    src="{{ data_get($user, 'nation.flag') }}" />
                            </div>
                        </div>
                        <ul tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                            <li><a href="{{ route("user.dashboard") }}">Dashboard</a></li>
                            <li><a href="{{ route("user.settings") }}">Settings</a></li>
                            @if ($user->is_admin)
                                <li>
                                    <a href="{{ route("admin.dashboard") }}" class="flex items-center gap-2">
                                        <span>Admin</span>
                                        @if ($showPendingIndicator)
                                            <span class="badge badge-primary badge-sm">{{ $pendingTotal }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endif
                            <li>
                                <a
                                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Logout</a>
                            </li>
                        </ul>
                    </div>
                @else
                    <div class="dropdown dropdown-end sm:hidden">
                        <div tabindex="0" role="button" class="btn btn-ghost btn-circle" aria-label="Open user menu">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5.121 17.804A9.004 9.004 0 0112 15c2.466 0 4.7.99 6.364 2.596M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <ul tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-44 p-2 shadow">
                            <li><a href="{{ route("login") }}">Login</a></li>
                            <li><a href="{{ route("register") }}">Register</a></li>
                        </ul>
                    </div>
                    <ul class="menu menu-horizontal hidden px-1 sm:flex">
                        <li><a href="{{ route("login") }}">Login</a></li>
                        <li><a href="{{ route("register") }}">Register</a></li>
                    </ul>
                @endif
            </div>
        </div>
        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
            @csrf
        </form>
    </div>
</div>
