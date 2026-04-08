@php
    $mainLinks = [
        ['label' => 'Accounts', 'route' => route('accounts'), 'active' => request()->routeIs('accounts')],
        ['label' => 'Market', 'route' => route('market.index'), 'active' => request()->routeIs('market.*')],
        ['label' => 'Audits', 'route' => route('audit.index'), 'active' => request()->routeIs('audit.*')],
        ['label' => 'Loans', 'route' => route('loans.index'), 'active' => request()->routeIs('loans.*')],
    ];

    $groups = [
        [
            'label' => 'Leaderboards',
            'active' => request()->routeIs('leaderboards.*'),
            'items' => [
                ['label' => 'Dashboard', 'route' => route('leaderboards.index')],
                ['label' => 'Profitability', 'route' => route('leaderboards.index', ['board' => 'profitability'])],
                ['label' => 'Raid Performance', 'route' => route('leaderboards.index', ['board' => 'raid-performance'])],
            ],
        ],
        [
            'label' => 'Grants',
            'active' => request()->routeIs('grants.*'),
            'items' => array_merge(
                [['label' => 'City Grants', 'route' => route('grants.city')]],
                $enabledGrants->map(fn ($grant) => [
                    'label' => ucwords($grant->name),
                    'route' => route('grants.show_grants', $grant->slug),
                ])->all(),
            ),
        ],
        [
            'label' => 'Defense',
            'active' => request()->routeIs('defense.*'),
            'items' => [
                ['label' => 'Counter Finder', 'route' => route('defense.counters')],
                ['label' => 'Intel Library', 'route' => route('defense.intel')],
                ['label' => 'War Aid', 'route' => route('defense.war-aid')],
                ['label' => 'Rebuilding', 'route' => route('defense.rebuilding')],
                ['label' => 'War Stats', 'route' => route('defense.war-stats')],
                ['label' => 'War Simulators', 'route' => route('defense.simulators')],
                ['label' => 'Raid Finder', 'route' => route('defense.raid-finder')],
            ],
        ],
    ];
@endphp

<div>
    <div class="mx-auto w-full max-w-[1600px] px-2 sm:px-4">
        <x-nav sticky full-width class="relative overflow-visible rounded-b-box border-b border-base-300/60 bg-base-100/95 backdrop-blur">
            <x-slot:brand>
                <div class="flex items-center gap-2">
                    <label for="user-mobile-menu" class="btn btn-ghost btn-circle lg:hidden">
                        <x-icon name="o-bars-3" class="size-5" />
                    </label>

                    <a href="{{ route('home') }}" class="flex items-center gap-2 px-2 text-sm font-black uppercase tracking-[0.22em] text-base-content sm:text-base">
                        <span class="grid size-9 place-items-center rounded-full bg-primary/15 text-primary">{{ str(config('app.name'))->substr(0, 2)->upper() }}</span>
                        <span class="hidden sm:block">{{ config('app.name') }}</span>
                    </a>
                </div>

                @if($showMemberNavigation)
                    <div class="absolute left-1/2 hidden -translate-x-1/2 xl:flex items-center gap-1">
                        @foreach($mainLinks as $link)
                            <a href="{{ $link['route'] }}" class="btn btn-sm {{ $link['active'] ? 'btn-primary' : 'btn-ghost' }} rounded-full" wire:navigate.hover>
                                {{ $link['label'] }}
                            </a>
                        @endforeach

                        @foreach($groups as $group)
                            <div class="dropdown dropdown-bottom">
                                <button tabindex="0" class="btn btn-sm {{ $group['active'] ? 'btn-primary' : 'btn-ghost' }} rounded-full">
                                    {{ $group['label'] }}
                                    <x-icon name="o-chevron-down" class="size-4" />
                                </button>
                                <ul tabindex="0" class="menu dropdown-content z-[80] mt-2 w-64 rounded-box border border-base-300 bg-base-100 p-2 shadow-xl">
                                    @foreach($group['items'] as $item)
                                        <li><a href="{{ $item['route'] }}">{{ $item['label'] }}</a></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="absolute left-1/2 hidden -translate-x-1/2 xl:flex items-center gap-2">
                        <a href="{{ route('apply.show') }}" class="btn btn-sm rounded-full {{ request()->routeIs('apply.show') ? 'btn-primary' : 'btn-ghost' }}">Apply</a>
                    </div>
                @endif
            </x-slot:brand>

            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-theme-toggle light-theme="light" dark-theme="night" />

                    @if($user)
                        <x-dropdown class="dropdown-end">
                            <x-slot:trigger>
                                <button class="btn btn-ghost btn-circle avatar">
                                    <div class="w-9 rounded-full @if($showPendingIndicator) ring ring-primary ring-offset-1 ring-offset-base-100 @endif">
                                        <img src="{{ data_get($user, 'nation.flag') }}" alt="{{ data_get($user, 'nation.leader_name', 'User') }}" />
                                    </div>
                                </button>
                            </x-slot:trigger>

                            <x-menu-item title="Dashboard" icon="o-squares-2x2" :link="route('user.dashboard')" no-wire-navigate />
                            <x-menu-item title="Settings" icon="o-cog-6-tooth" :link="route('user.settings')" no-wire-navigate />

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
                            <x-menu-item title="Logout" icon="o-arrow-right-on-rectangle" wire:click="logout" class="text-error" />
                        </x-dropdown>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm hidden sm:flex">Login</a>
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:flex">Register</a>

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
                </div>
            </x-slot:actions>
        </x-nav>
    </div>

    @if($showMemberNavigation || ! $user)
        <div x-data="{ open: false }" @keydown.escape.window="open = false">
            <input id="user-mobile-menu" type="checkbox" class="drawer-toggle sr-only" x-model="open" />

            <div class="fixed inset-0 z-40 bg-black/30 lg:hidden" x-show="open" x-transition.opacity @click="open = false"></div>

            <div class="fixed inset-y-0 left-0 z-50 w-72 bg-base-100 shadow-xl transition-transform duration-200 lg:hidden"
                 :class="open ? 'translate-x-0' : '-translate-x-full'">
                <div class="flex items-center justify-between border-b border-base-300 p-4">
                    <span class="font-bold">{{ config('app.name') }}</span>
                    <button @click="open = false" class="btn btn-ghost btn-circle btn-sm">
                        <x-icon name="o-x-mark" class="size-5" />
                    </button>
                </div>
                <ul class="menu gap-1 p-4">
                    @if($showMemberNavigation)
                        @foreach($mainLinks as $link)
                            <li><a href="{{ $link['route'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                        @foreach($groups as $group)
                            <li>
                                <details>
                                    <summary>{{ $group['label'] }}</summary>
                                    <ul>
                                        @foreach($group['items'] as $item)
                                            <li><a href="{{ $item['route'] }}">{{ $item['label'] }}</a></li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endforeach
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
