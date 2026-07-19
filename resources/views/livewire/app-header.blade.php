<div
    class="member-chrome"
    x-data="{ menuOpen: false }"
    @keydown.escape.window="menuOpen = false"
    data-member-navigation
>
    <header class="member-header">
        <div class="member-header__topbar">
            <div class="member-header__inner">
                @if($showMemberNavigation)
                    <button
                        type="button"
                        class="btn btn-ghost btn-circle btn-sm lg:hidden"
                        @click="menuOpen = true"
                        aria-label="Open member navigation"
                        :aria-expanded="menuOpen.toString()"
                        aria-controls="member-navigation-drawer"
                    >
                        <x-icon name="o-bars-3" class="size-5" />
                    </button>
                @endif

                <a href="{{ $showMemberNavigation ? route('user.dashboard') : route('home') }}" class="member-brand">
                    <span class="member-brand__mark" aria-hidden="true">{{ Str::of(config('app.name'))->substr(0, 1)->upper() }}</span>
                    <span class="min-w-0">
                        <span class="member-brand__name">{{ config('app.name') }}</span>
                        <span class="member-brand__descriptor">Member operations</span>
                    </span>
                </a>

                @if($showMemberNavigation)
                    <div class="member-header__context">
                        @foreach($navigation as $entry)
                            @if($entry['active'])
                                <span>{{ $entry['label'] }}</span>
                                @break
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="member-header__actions">
                    <x-theme-picker />

                    @if($user?->is_admin)
                        <a href="{{ route('admin.dashboard') }}" class="btn btn-ghost btn-sm hidden md:inline-flex">
                            <x-icon name="o-building-office-2" class="size-4" />
                            Admin
                        </a>
                    @endif

                    @if($user)
                        <details class="account-control">
                            <summary class="account-control__trigger" aria-label="Open account menu">
                                @if(data_get($user, 'nation.flag'))
                                    <img
                                        src="{{ data_get($user, 'nation.flag') }}"
                                        alt=""
                                        class="account-control__avatar"
                                    >
                                @else
                                    <span class="account-control__fallback" aria-hidden="true">
                                        {{ str($user->name)->substr(0, 1)->upper() }}
                                    </span>
                                @endif
                                @if($showPendingIndicator)
                                    <span class="account-control__pending" aria-hidden="true"></span>
                                @endif
                            </summary>

                            <div class="account-menu">
                                <div class="account-menu__identity">
                                    <p>{{ data_get($user, 'nation.leader_name', $user->name) }}</p>
                                    <p>{{ data_get($user, 'nation.nation_name', $user->email) }}</p>
                                    @if($showPendingIndicator)
                                        <span class="nexus-status nexus-status--warning mt-2">
                                            {{ $pendingTotal }} {{ \Illuminate\Support\Str::plural('request', $pendingTotal) }} pending
                                        </span>
                                    @endif
                                </div>

                                <nav class="account-menu__links" aria-label="Account navigation">
                                    @if($showMemberNavigation)
                                        <a href="{{ route('user.dashboard') }}">
                                            <x-icon name="o-squares-2x2" class="size-4" />
                                            Overview
                                        </a>
                                        <a href="{{ route('user.settings') }}">
                                            <x-icon name="o-cog-6-tooth" class="size-4" />
                                            Settings and security
                                        </a>
                                        <a href="{{ route('user.settings.api-docs') }}">
                                            <x-icon name="o-code-bracket" class="size-4" />
                                            API documentation
                                        </a>
                                        <a href="{{ route('user.discord-bot-guide') }}">
                                            <x-icon name="o-command-line" class="size-4" />
                                            Discord bot guide
                                        </a>
                                    @endif
                                    @if($user->is_admin)
                                        <a href="{{ route('admin.dashboard') }}">
                                            <x-icon name="o-building-office-2" class="size-4" />
                                            Admin application
                                        </a>
                                    @endif
                                    <button type="button" wire:click="logout" class="account-menu__logout">
                                        <x-icon name="o-arrow-right-on-rectangle" class="size-4" />
                                        Sign out
                                    </button>
                                </nav>
                            </div>
                        </details>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Create account</a>
                    @endif
                </div>
            </div>
        </div>

        @if($showMemberNavigation)
            <nav class="member-domain-bar" aria-label="Member navigation">
                <div class="member-domain-bar__inner">
                    @foreach($navigation as $entry)
                        @if(isset($entry['route']))
                            <a
                                href="{{ $entry['route'] }}"
                                @class(['member-domain-link', 'is-active' => $entry['active']])
                                @if($entry['active']) aria-current="page" @endif
                            >
                                <x-icon :name="$entry['icon']" class="size-4" />
                                {{ $entry['label'] }}
                            </a>
                        @else
                            <details @class(['member-domain', 'is-active' => $entry['active']])>
                                <summary>
                                    <x-icon :name="$entry['icon']" class="size-4" />
                                    {{ $entry['label'] }}
                                    <x-icon name="o-chevron-down" class="member-domain__chevron size-3.5" />
                                </summary>
                                <div class="member-domain__menu">
                                    @foreach($entry['items'] as $item)
                                        <a href="{{ $item['route'] }}" @if($item['active']) aria-current="page" @endif>
                                            {{ $item['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    @endforeach
                </div>
            </nav>
        @endif
    </header>

    @if($showMemberNavigation)
        <div
            x-cloak
            x-show="menuOpen"
            x-transition.opacity
            class="member-drawer-backdrop"
            @click="menuOpen = false"
            aria-hidden="true"
        ></div>

        <aside
            id="member-navigation-drawer"
            x-cloak
            x-show="menuOpen"
            class="member-drawer"
            :class="menuOpen ? 'is-open' : ''"
            :aria-hidden="(! menuOpen).toString()"
            x-trap.noscroll="menuOpen"
        >
            <div class="member-drawer__header">
                <div>
                    <p class="nexus-kicker">Member app</p>
                    <p class="member-drawer__title">Navigate</p>
                </div>
                <button type="button" class="btn btn-ghost btn-circle btn-sm" @click="menuOpen = false" aria-label="Close member navigation">
                    <x-icon name="o-x-mark" class="size-5" />
                </button>
            </div>

            <nav class="member-drawer__nav" aria-label="Member navigation drawer">
                @foreach($navigation as $entry)
                    @if(isset($entry['route']))
                        <a href="{{ $entry['route'] }}" @if($entry['active']) aria-current="page" @endif>
                            <x-icon :name="$entry['icon']" class="size-5" />
                            {{ $entry['label'] }}
                        </a>
                    @else
                        <details @if($entry['active']) open @endif>
                            <summary>
                                <x-icon :name="$entry['icon']" class="size-5" />
                                {{ $entry['label'] }}
                                <x-icon name="o-chevron-down" class="ml-auto size-4" />
                            </summary>
                            <div>
                                @foreach($entry['items'] as $item)
                                    <a href="{{ $item['route'] }}" @if($item['active']) aria-current="page" @endif>
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endforeach
            </nav>
        </aside>

        <nav class="member-bottom-nav" aria-label="Primary member navigation">
            <a href="{{ route('user.dashboard') }}" @if(request()->routeIs('user.dashboard')) aria-current="page" @endif>
                <x-icon name="o-squares-2x2" class="size-5" />
                <span>Overview</span>
            </a>
            <a href="{{ route('accounts') }}" @if(request()->routeIs('accounts*', 'member-transfers.*', 'market.*', 'lottery.*', 'loans.*')) aria-current="page" @endif>
                <x-icon name="o-banknotes" class="size-5" />
                <span>Finance</span>
            </a>
            <a href="{{ route('grants.city') }}" @if(request()->routeIs('grants.*', 'defense.war-aid*', 'defense.rebuilding*')) aria-current="page" @endif>
                <x-icon name="o-lifebuoy" class="size-5" />
                <span>Aid</span>
            </a>
            <a href="{{ route('audit.index') }}" @if(request()->routeIs('audit.*', 'defense.counters*', 'defense.war-stats*', 'defense.simulators*')) aria-current="page" @endif>
                <x-icon name="o-shield-check" class="size-5" />
                <span>Readiness</span>
            </a>
            <button type="button" @click="menuOpen = true" :aria-expanded="menuOpen.toString()" aria-controls="member-navigation-drawer">
                <x-icon name="o-ellipsis-horizontal" class="size-5" />
                <span>More</span>
            </button>
        </nav>
    @endif
</div>
