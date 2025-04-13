@php use App\Models\Grants; @endphp
<div class="container mx-auto">
    <div class="navbar bg-base-100">
        <div class="navbar-start">
            <div class="dropdown">
                <div tabindex="0" role="button" class="btn btn-ghost lg:hidden">
                    <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor">
                        <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6h16M4 12h8m-8 6h16"/>
                    </svg>
                </div>
                @if (Auth::check())
                    @php
                        $enabledGrants = Grants::where('is_enabled', true)->orderBy('name')->get();
                    @endphp
                    <ul
                            tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                        <li><a href="{{ route("accounts") }}">Accounts</a></li>
                        <li>
                            <a>Grants</a>
                            <ul class="p-2">
                                <li><a href="{{ route("grants.city") }}">City Grants</a></li>
                                @foreach ($enabledGrants as $grant)
                                    <li>
                                        <a href="{{ route('grants.show_grants', $grant->slug) }}">{{ ucwords($grant->name) }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                        <li><a href="{{ route("loans.index") }}">Loans</a></li>
                        <li>
                            <a>Defense</a>
                            <ul class="p-2">
                                <li><a href="{{ route("defense.counters") }}">Counter Finder</a></li>
                                <li><a href="{{ route("defense.war-aid") }}">War Aid</a></li>
                                <li><a href="{{ route("defense.raid-finder") }}">Raid Finder</a></li>
                            </ul>
                        </li>
                    </ul>
                @else
                    <ul
                            tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                        <li><a>Apply</a></li>
                    </ul>
                @endif

            </div>
            <a class="btn btn-ghost text-xl" href="{{ route("home") }}">Nexus AMS</a>
        </div>
        {{-- End mobile nav and begin desktop nav --}}
        <div class="navbar-center hidden lg:flex">
            @if (Auth::check())
                <ul class="menu menu-horizontal px-1 z-50">
                    <li><a href="{{ route("accounts") }}">Accounts</a></li>
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
                                        <a href="{{ route('grants.show_grants', $grant->slug) }}">{{ ucwords($grant->name) }}</a>
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
                                <li><a href="{{ route("defense.war-aid") }}">War Aid</a></li>
                                <li><a href="{{ route("defense.raid-finder") }}">Raid Finder</a></li>
                            </ul>
                        </details>
                    </li>
                </ul>
            @else
                <ul class="menu menu-horizontal px-1">
                    <li><a>Apply</a></li>
                </ul>
            @endif
        </div>
        <div class="navbar-end">
            <label class="swap swap-rotate">
                <input type="checkbox" class="theme-controller" id="theme-toggle"/>

                <!-- Sun Icon -->
                <svg class="swap-off h-10 w-10 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/>
                </svg>

                <!-- Moon Icon -->
                <svg class="swap-on h-10 w-10 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z"/>
                </svg>
            </label>
            @if (Auth::check())
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-circle avatar">
                        <div class="w-10 rounded-full">
                            <img
                                    alt="{{ Auth::user()->nation->leader_name }} Flag"
                                    src="{{ Auth::user()->nation->flag }}"/>
                        </div>
                    </div>
                    <ul
                            tabindex="0"
                            class="menu menu-sm dropdown-content bg-base-100 rounded-box z-[1] mt-3 w-52 p-2 shadow">
                        <li><a href="{{ route("user.settings") }}">Settings</a></li>
                        @if (Auth::user()->is_admin)
                            <li><a href="{{ route("admin.dashboard") }}">Admin</a></li>
                        @endif
                        <li>
                            <a onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Logout</a>
                        </li>
                    </ul>
                </div>
            @else
                <ul class="menu menu-horizontal px-1">
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
