<x-nav sticky full-width>

    <x-slot:brand>
        {{-- Sidebar Hamburger --}}
        <label for="admin-sidebar" class="lg:hidden mr-3 cursor-pointer">
            <x-icon name="o-bars-3" class="size-6 text-base-content/70" />
        </label>

        {{-- App Name --}}
        <a href="{{ route('admin.dashboard') }}" class="text-sm font-semibold text-base-content mr-6 hidden lg:block">
            {{ config('app.name') }} <span class="text-base-content/50 font-normal">/ Admin</span>
        </a>

        {{-- Quick Nav Links --}}
        @auth
            <div class="hidden md:flex items-center gap-1">
                <a href="{{ route('home') }}"
                   class="btn btn-sm btn-ghost btn-neutral @if(request()->routeIs('home')) btn-active @endif">
                    Home
                </a>
                <a href="{{ route('accounts') }}"
                   class="btn btn-sm btn-ghost btn-neutral @if(request()->routeIs('accounts')) btn-active @endif">
                    Accounts
                </a>
                <a href="{{ route('audit.index') }}"
                   class="btn btn-sm btn-ghost btn-neutral @if(request()->routeIs('audit.*')) btn-active @endif">
                    Audits
                </a>
                <a href="{{ route('loans.index') }}"
                   class="btn btn-sm btn-ghost btn-neutral @if(request()->routeIs('loans.*')) btn-active @endif">
                    Loans
                </a>
            </div>
        @endauth
    </x-slot:brand>

    <x-slot:actions>
        {{-- Theme Toggle --}}
        <x-theme-toggle light-theme="light" dark-theme="night" class="order-1" />

        {{-- User Dropdown --}}
        @if($nation)
            <x-dropdown>
                <x-slot:trigger>
                    <button class="btn btn-ghost btn-circle avatar">
                        <div class="w-8 rounded-full ring ring-primary/20 ring-offset-1">
                            <img src="{{ $nation->flag }}" alt="{{ $nation->leader_name }}" class="object-cover" />
                        </div>
                    </button>
                </x-slot:trigger>

                {{-- Profile Header --}}
                <div class="px-4 py-3 bg-primary/10 border-b border-base-300 min-w-72">
                    <div class="flex items-center gap-3">
                        <div class="avatar">
                            <div class="w-12 rounded-full ring ring-primary/30">
                                <img src="{{ $nation->flag }}" alt="{{ $nation->leader_name }}" class="object-cover" />
                            </div>
                        </div>
                        <div>
                            <p class="font-semibold text-base-content text-sm">{{ $nation->leader_name }}</p>
                            <p class="text-xs text-base-content/60">{{ $nation->nation_name }}</p>
                            <span class="badge badge-primary badge-xs mt-0.5">{{ $nation->alliance_position ?? 'Member' }}</span>
                        </div>
                    </div>
                    {{-- Nation Stats --}}
                    <div class="grid grid-cols-3 gap-2 mt-3">
                        <div class="bg-base-100/60 rounded-lg p-2 text-center">
                            <div class="text-xs text-base-content/50">Cities</div>
                            <div class="font-semibold text-sm text-base-content">{{ $nation->num_cities }}</div>
                        </div>
                        <div class="bg-base-100/60 rounded-lg p-2 text-center">
                            <div class="text-xs text-base-content/50">Score</div>
                            <div class="font-semibold text-sm text-base-content">{{ number_format($nation->score, 0) }}</div>
                        </div>
                        <div class="bg-base-100/60 rounded-lg p-2 text-center">
                            <div class="text-xs text-base-content/50">Wars</div>
                            <div class="font-semibold text-sm text-base-content">{{ $nation->offensive_wars_count + $nation->defensive_wars_count }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1 mt-2">
                        <span class="badge badge-ghost badge-xs">
                            <x-icon name="o-shield-check" class="size-3 mr-0.5" /> D:{{ $nation->defensive_wars_count }}
                        </span>
                        <span class="badge badge-ghost badge-xs">
                            <x-icon name="o-bolt" class="size-3 mr-0.5" /> O:{{ $nation->offensive_wars_count }}
                        </span>
                        <span class="badge badge-success badge-xs">W:{{ $nation->wars_won }}</span>
                        <span class="badge badge-error badge-xs">L:{{ $nation->wars_lost }}</span>
                        <span class="badge badge-neutral badge-xs">Pop: {{ $abbreviateNumber($nation->population) }}</span>
                    </div>
                </div>

                {{-- Navigation Links --}}
                <x-menu-item
                    title="User Dashboard"
                    icon="o-squares-2x2"
                    :link="route('user.dashboard')"
                    no-wire-navigate
                />
                <x-menu-item
                    title="Admin Overview"
                    icon="o-building-office"
                    :link="route('admin.dashboard')"
                    no-wire-navigate
                />
                <x-menu-item
                    title="Member Profile"
                    icon="o-identification"
                    :link="route('admin.members.show', Auth::user()->nation_id)"
                    no-wire-navigate
                />

                <x-menu-separator />

                {{-- Logout --}}
                <x-menu-item
                    title="Logout"
                    icon="o-arrow-right-on-rectangle"
                    wire:click="logout"
                    class="text-error"
                />
            </x-dropdown>
        @endif
    </x-slot:actions>

</x-nav>
