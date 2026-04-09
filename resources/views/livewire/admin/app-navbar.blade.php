@php
    $mainLinks = [
        ['label' => 'Accounts', 'route' => route('accounts'), 'active' => request()->routeIs('accounts')],
        ['label' => 'Market', 'route' => route('market.index'), 'active' => request()->routeIs('market.*')],
        ['label' => 'Audits', 'route' => route('audit.index'), 'active' => request()->routeIs('audit.*')],
        ['label' => 'Loans', 'route' => route('loans.index'), 'active' => request()->routeIs('loans.*')],
    ];

    $navGroups = [
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
            'items' => [
                ['label' => 'City Grants', 'route' => route('grants.city')],
                ['label' => 'War Aid', 'route' => route('defense.war-aid')],
            ],
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

<x-nav sticky full-width class="relative overflow-visible border-b border-base-300/60 bg-base-100/95 backdrop-blur">
    <x-slot:brand>
        <div class="flex items-center gap-3">
            <label for="admin-sidebar" class="cursor-pointer lg:hidden">
                <x-icon name="o-bars-3" class="size-6 text-base-content/70" />
            </label>

            <a href="{{ route('admin.dashboard') }}" class="hidden text-sm font-black uppercase tracking-[0.18em] text-base-content lg:flex">
                {{ config('app.name') }} <span class="ml-1 font-medium text-base-content/45">/ Admin</span>
            </a>
        </div>

        <div class="pointer-events-none absolute left-1/2 hidden -translate-x-1/2 xl:flex items-center gap-1">
            @foreach($mainLinks as $link)
                <a href="{{ $link['route'] }}" class="pointer-events-auto btn btn-sm rounded-full {{ $link['active'] ? 'btn-primary' : 'btn-ghost' }}">{{ $link['label'] }}</a>
            @endforeach
            @foreach($navGroups as $group)
                <div class="pointer-events-auto dropdown dropdown-bottom">
                    <button tabindex="0" class="btn btn-sm rounded-full {{ $group['active'] ? 'btn-primary' : 'btn-ghost' }}">
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
    </x-slot:brand>

    <x-slot:actions>
        <div class="flex items-center gap-2">
            <x-theme-picker />

            @if($nation)
                <x-dropdown class="dropdown-end">
                    <x-slot:trigger>
                        <button class="btn btn-ghost btn-circle avatar">
                            <div class="w-8 rounded-full ring ring-primary/20 ring-offset-1">
                                <img src="{{ $nation->flag }}" alt="{{ $nation->leader_name }}" class="object-cover" />
                            </div>
                        </button>
                    </x-slot:trigger>

                    <div class="min-w-72 border-b border-base-300 bg-primary/10 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="avatar">
                                <div class="w-12 rounded-full ring ring-primary/30">
                                    <img src="{{ $nation->flag }}" alt="{{ $nation->leader_name }}" class="object-cover" />
                                </div>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-base-content">{{ $nation->leader_name }}</p>
                                <p class="text-xs text-base-content/60">{{ $nation->nation_name }}</p>
                                <span class="badge badge-primary badge-xs mt-0.5">{{ $nation->alliance_position ?? 'Member' }}</span>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            <div class="rounded-lg bg-base-100/70 p-2 text-center">
                                <div class="text-xs text-base-content/50">Cities</div>
                                <div class="text-sm font-semibold text-base-content">{{ $nation->num_cities }}</div>
                            </div>
                            <div class="rounded-lg bg-base-100/70 p-2 text-center">
                                <div class="text-xs text-base-content/50">Score</div>
                                <div class="text-sm font-semibold text-base-content">{{ number_format($nation->score, 0) }}</div>
                            </div>
                            <div class="rounded-lg bg-base-100/70 p-2 text-center">
                                <div class="text-xs text-base-content/50">Wars</div>
                                <div class="text-sm font-semibold text-base-content">{{ $nation->offensive_wars_count + $nation->defensive_wars_count }}</div>
                            </div>
                        </div>
                    </div>

                    <x-menu-item title="User Dashboard" icon="o-squares-2x2" :link="route('user.dashboard')" no-wire-navigate />
                    <x-menu-item title="Admin Overview" icon="o-building-office" :link="route('admin.dashboard')" no-wire-navigate />
                    <x-menu-item title="Member Profile" icon="o-identification" :link="route('admin.members.show', Auth::user()->nation_id)" no-wire-navigate />
                    <x-menu-separator />
                    <x-menu-item title="Logout" icon="o-arrow-right-on-rectangle" wire:click="logout" class="text-error" />
                </x-dropdown>
            @endif
        </div>
    </x-slot:actions>
</x-nav>
