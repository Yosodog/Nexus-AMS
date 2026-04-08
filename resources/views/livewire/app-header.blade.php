<div>
<div class="container mx-auto px-2 sm:px-4 max-w-[1600px]">
<x-nav sticky class="bg-base-100 border-b border-base-300/50 rounded-b-box">

    <x-slot:brand>
        {{-- Mobile Hamburger + Drawer --}}
        <label for="user-mobile-menu" class="btn btn-ghost btn-circle lg:hidden mr-1">
            <x-icon name="o-bars-3" class="size-5" />
        </label>

        {{-- App Logo/Name --}}
        <a href="{{ route('home') }}" class="btn btn-ghost text-base font-bold px-2 sm:px-3 sm:text-lg">
            {{ config('app.name') }}
        </a>

        {{-- Desktop Navigation --}}
        @if($showMemberNavigation)
            <div class="hidden lg:flex items-center ml-2">
                <ul class="menu menu-horizontal menu-sm flex-nowrap gap-0.5 px-0">
                    <li>
                        <a href="{{ route('accounts') }}"
                           class="{{ request()->routeIs('accounts') ? 'active' : '' }}">
                            Accounts
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('market.index') }}"
                           class="{{ request()->routeIs('market.*') ? 'active' : '' }}">
                            Market
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('audit.index') }}"
                           class="{{ request()->routeIs('audit.*') ? 'active' : '' }}">
                            Audits
                        </a>
                    </li>
                    <li>
                        <details class="relative">
                            <summary class="{{ request()->routeIs('leaderboards.*') ? 'active' : '' }}">
                                Leaderboards
                            </summary>
                            <ul class="absolute left-0 mt-1 w-52 bg-base-100 shadow-lg rounded-box z-50 p-2">
                                <li><a href="{{ route('leaderboards.index') }}">Dashboard</a></li>
                                <li><a href="{{ route('leaderboards.index', ['board' => 'profitability']) }}">Profitability</a></li>
                                <li><a href="{{ route('leaderboards.index', ['board' => 'raid-performance']) }}">Raid Performance</a></li>
                            </ul>
                        </details>
                    </li>
                    <li>
                        <details class="relative">
                            <summary class="{{ request()->routeIs('grants.*') ? 'active' : '' }}">
                                Grants
                            </summary>
                            <ul class="absolute left-0 mt-1 w-52 bg-base-100 shadow-lg rounded-box z-50 p-2">
                                <li><a href="{{ route('grants.city') }}">City Grants</a></li>
                                @foreach($enabledGrants as $grant)
                                    <li>
                                        <a href="{{ route('grants.show_grants', $grant->slug) }}">
                                            {{ ucwords($grant->name) }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    </li>
                    <li>
                        <a href="{{ route('loans.index') }}"
                           class="{{ request()->routeIs('loans.*') ? 'active' : '' }}">
                            Loans
                        </a>
                    </li>
                    <li>
                        <details class="relative">
                            <summary class="{{ request()->routeIs('defense.*') ? 'active' : '' }}">
                                Defense
                            </summary>
                            <ul class="absolute left-0 mt-1 w-52 bg-base-100 shadow-lg rounded-box z-50 p-2">
                                <li><a href="{{ route('defense.counters') }}">Counter Finder</a></li>
                                <li><a href="{{ route('defense.intel') }}">Intel Library</a></li>
                                <li><a href="{{ route('defense.war-aid') }}">War Aid</a></li>
                                <li><a href="{{ route('defense.rebuilding') }}">Rebuilding</a></li>
                                <li><a href="{{ route('defense.war-stats') }}">War Stats</a></li>
                                <li><a href="{{ route('defense.simulators') }}">War Simulators</a></li>
                                <li><a href="{{ route('defense.raid-finder') }}">Raid Finder</a></li>
                            </ul>
                        </details>
                    </li>
                </ul>
            </div>
        @elseif($user)
            <div class="hidden lg:flex ml-2">
                <a href="{{ route('apply.show') }}" class="btn btn-sm btn-ghost">Apply</a>
            </div>
        @else
            <div class="hidden lg:flex ml-2">
                <a href="{{ route('apply.show') }}" class="btn btn-sm btn-ghost">Apply</a>
            </div>
        @endif
    </x-slot:brand>

    <x-slot:actions>
        {{-- Theme Toggle --}}
        <x-theme-toggle light-theme="light" dark-theme="night" />

        {{-- User Menu --}}
        @if($user)
            <x-dropdown class="dropdown-end">
                <x-slot:trigger>
                    <button class="btn btn-ghost btn-circle avatar">
                        <div class="w-9 rounded-full @if($showPendingIndicator) ring ring-primary ring-offset-base-100 ring-offset-1 @endif">
                            <img src="{{ data_get($user, 'nation.flag') }}"
                                 alt="{{ data_get($user, 'nation.leader_name', 'User') }}" />
                        </div>
                    </button>
                </x-slot:trigger>

                <x-menu-item
                    title="Dashboard"
                    icon="o-squares-2x2"
                    :link="route('user.dashboard')"
                    no-wire-navigate
                />
                <x-menu-item
                    title="Settings"
                    icon="o-cog-6-tooth"
                    :link="route('user.settings')"
                    no-wire-navigate
                />

                @if($user->is_admin)
                    <x-menu-separator />
                    <x-menu-item
                        title="Admin Panel"
                        icon="o-building-office"
                        :link="route('admin.dashboard')"
                        :badge="$showPendingIndicator ? (string) $pendingTotal : null"
                        badge-classes="badge-primary badge-sm"
                        no-wire-navigate
                    />
                @endif

                <x-menu-separator />

                <x-menu-item
                    title="Logout"
                    icon="o-arrow-right-on-rectangle"
                    wire:click="logout"
                    class="text-error"
                />
            </x-dropdown>
        @else
            <a href="{{ route('login') }}" class="btn btn-ghost btn-sm hidden sm:flex">Login</a>
            <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:flex">Register</a>

            {{-- Mobile auth dropdown --}}
            <x-dropdown class="dropdown-end sm:hidden">
                <x-slot:trigger>
                    <button class="btn btn-ghost btn-circle">
                        <x-icon name="o-user-circle" class="size-6" />
                    </button>
                </x-slot:trigger>
                <x-menu-item title="Login" icon="o-arrow-right-on-rectangle" :link="route('login')" no-wire-navigate />
                <x-menu-item title="Register" icon="o-user-plus" :link="route('register')" no-wire-navigate />
            </x-dropdown>
        @endif
    </x-slot:actions>

</x-nav>
</div>

{{-- Mobile Navigation Drawer --}}
@if($showMemberNavigation || !$user)
<div x-data="{ open: false }" @keydown.escape.window="open = false">
    <input id="user-mobile-menu" type="checkbox" class="drawer-toggle sr-only" x-model="open" />

    <div class="fixed inset-0 bg-black/30 z-40 lg:hidden" x-show="open" x-transition.opacity @click="open = false"></div>

    <div class="fixed inset-y-0 left-0 z-50 w-72 bg-base-100 shadow-xl lg:hidden transition-transform duration-200"
         :class="open ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex items-center justify-between p-4 border-b border-base-300">
            <span class="font-bold">{{ config('app.name') }}</span>
            <button @click="open = false" class="btn btn-ghost btn-circle btn-sm">
                <x-icon name="o-x-mark" class="size-5" />
            </button>
        </div>
        <ul class="menu p-4 gap-1">
            @if($showMemberNavigation)
                <li><a href="{{ route('accounts') }}">Accounts</a></li>
                <li><a href="{{ route('market.index') }}">Market</a></li>
                <li><a href="{{ route('audit.index') }}">Audits</a></li>
                <li>
                    <details>
                        <summary>Leaderboards</summary>
                        <ul>
                            <li><a href="{{ route('leaderboards.index') }}">Dashboard</a></li>
                            <li><a href="{{ route('leaderboards.index', ['board' => 'profitability']) }}">Profitability</a></li>
                            <li><a href="{{ route('leaderboards.index', ['board' => 'raid-performance']) }}">Raid Performance</a></li>
                        </ul>
                    </details>
                </li>
                <li>
                    <details>
                        <summary>Grants</summary>
                        <ul>
                            <li><a href="{{ route('grants.city') }}">City Grants</a></li>
                            @foreach($enabledGrants as $grant)
                                <li><a href="{{ route('grants.show_grants', $grant->slug) }}">{{ ucwords($grant->name) }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                </li>
                <li><a href="{{ route('loans.index') }}">Loans</a></li>
                <li>
                    <details>
                        <summary>Defense</summary>
                        <ul>
                            <li><a href="{{ route('defense.counters') }}">Counter Finder</a></li>
                            <li><a href="{{ route('defense.intel') }}">Intel Library</a></li>
                            <li><a href="{{ route('defense.war-aid') }}">War Aid</a></li>
                            <li><a href="{{ route('defense.rebuilding') }}">Rebuilding</a></li>
                            <li><a href="{{ route('defense.war-stats') }}">War Stats</a></li>
                            <li><a href="{{ route('defense.simulators') }}">War Simulators</a></li>
                            <li><a href="{{ route('defense.raid-finder') }}">Raid Finder</a></li>
                        </ul>
                    </details>
                </li>
            @else
                <li><a href="{{ route('apply.show') }}">Apply</a></li>
                @guest
                    <li><a href="{{ route('login') }}">Login</a></li>
                    <li><a href="{{ route('register') }}">Register</a></li>
                @endguest
            @endif
        </ul>
    </div>
</div>
@endif
</div>
