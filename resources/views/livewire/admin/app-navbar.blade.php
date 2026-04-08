@php
    $adminGroups = [
        [
            'label' => 'Alliance',
            'active' => request()->routeIs('admin.members*', 'admin.cities.*', 'admin.users.*', 'admin.roles.*', 'admin.applications.*', 'admin.recruitment.*'),
            'items' => [
                ['label' => 'Members', 'route' => route('admin.members')],
                ['label' => 'Cities', 'route' => route('admin.cities.index')],
                ['label' => 'Users', 'route' => route('admin.users.index')],
                ['label' => 'Roles', 'route' => route('admin.roles.index')],
                ['label' => 'Applications', 'route' => route('admin.applications.index')],
                ['label' => 'Recruitment', 'route' => route('admin.recruitment.index')],
            ],
        ],
        [
            'label' => 'Finance',
            'active' => request()->routeIs('admin.accounts.*', 'admin.grants*', 'admin.loans*', 'admin.taxes', 'admin.finance.*', 'admin.payroll.*', 'admin.market.*', 'admin.offshores.*'),
            'items' => [
                ['label' => 'Accounts', 'route' => route('admin.accounts.dashboard')],
                ['label' => 'City Grants', 'route' => route('admin.grants.city')],
                ['label' => 'Grants', 'route' => route('admin.grants')],
                ['label' => 'Loans', 'route' => route('admin.loans')],
                ['label' => 'Taxes', 'route' => route('admin.taxes')],
                ['label' => 'Finance Ledger', 'route' => route('admin.finance.index')],
                ['label' => 'Payroll', 'route' => route('admin.payroll.index')],
                ['label' => 'Alliance Market', 'route' => route('admin.market.index')],
                ['label' => 'Offshores', 'route' => route('admin.offshores.index')],
            ],
        ],
        [
            'label' => 'Defense',
            'active' => request()->routeIs('admin.war-room*', 'admin.wars', 'admin.war-aid*', 'admin.rebuilding.*', 'admin.raids.*', 'admin.beige-alerts.*', 'admin.spy-campaigns.*', 'admin.mmr.*'),
            'items' => [
                ['label' => 'War Room', 'route' => route('admin.war-room')],
                ['label' => 'Wars', 'route' => route('admin.wars')],
                ['label' => 'War Aid', 'route' => route('admin.war-aid')],
                ['label' => 'Rebuilding', 'route' => route('admin.rebuilding.index')],
                ['label' => 'Raids', 'route' => route('admin.raids.index')],
                ['label' => 'Beige Alerts', 'route' => route('admin.beige-alerts.index')],
                ['label' => 'Spy Campaigns', 'route' => route('admin.spy-campaigns.index')],
                ['label' => 'MMR', 'route' => route('admin.mmr.index')],
            ],
        ],
        [
            'label' => 'System',
            'active' => request()->routeIs('admin.audits.*', 'admin.audit-logs.*', 'admin.settings', 'admin.nel.docs', 'admin.customization.*') || request()->is('telescope*', 'pulse*', 'log-viewer*'),
            'items' => [
                ['label' => 'Audits', 'route' => route('admin.audits.index')],
                ['label' => 'Audit Logs', 'route' => route('admin.audit-logs.index')],
                ['label' => 'Settings', 'route' => route('admin.settings')],
                ['label' => 'NEL Docs', 'route' => route('admin.nel.docs')],
                ['label' => 'Customize Pages', 'route' => route('admin.customization.index')],
                ['label' => 'Telescope', 'route' => url('/telescope'), 'external' => true],
                ['label' => 'Pulse', 'route' => url('/pulse'), 'external' => true],
                ['label' => 'Log Viewer', 'route' => url('/log-viewer'), 'external' => true],
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

            <a href="{{ route('admin.dashboard') }}" class="hidden items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-base-content lg:flex">
                <span class="grid size-9 place-items-center rounded-full bg-primary/15 text-primary">{{ str(config('app.name'))->substr(0, 2)->upper() }}</span>
                <span>{{ config('app.name') }} <span class="font-medium text-base-content/45">/ Admin</span></span>
            </a>
        </div>

        <div class="absolute left-1/2 hidden -translate-x-1/2 xl:flex items-center gap-1">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-sm rounded-full {{ request()->routeIs('admin.dashboard') ? 'btn-primary' : 'btn-ghost' }}">Dashboard</a>
            @foreach($adminGroups as $group)
                <div class="dropdown dropdown-bottom">
                    <button tabindex="0" class="btn btn-sm rounded-full {{ $group['active'] ? 'btn-primary' : 'btn-ghost' }}">
                        {{ $group['label'] }}
                        <x-icon name="o-chevron-down" class="size-4" />
                    </button>
                    <ul tabindex="0" class="menu dropdown-content z-[80] mt-2 w-64 rounded-box border border-base-300 bg-base-100 p-2 shadow-xl">
                        @foreach($group['items'] as $item)
                            <li>
                                <a href="{{ $item['route'] }}" @if(! empty($item['external'])) rel="noopener" @endif>
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </x-slot:brand>

    <x-slot:actions>
        <div class="flex items-center gap-2">
            <x-theme-toggle light-theme="light" dark-theme="night" class="order-1" />

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
