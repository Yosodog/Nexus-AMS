<nav
    class="admin-sidebar-nav"
    aria-label="Administrative navigation"
    data-admin-navigation
    data-admin-drawer="admin-sidebar"
>
    <a href="{{ route('admin.dashboard') }}" class="admin-sidebar-nav__heading">
        <span class="admin-sidebar-nav__mark" aria-hidden="true">A</span>
        <span class="mary-hideable min-w-0">
            <span class="admin-sidebar-nav__title">Administration</span>
            <span class="admin-sidebar-nav__subtitle">Alliance operations</span>
        </span>
    </a>

    <p class="sr-only" data-admin-pin-status role="status" aria-live="polite" aria-atomic="true"></p>

    <div class="admin-sidebar-nav__primary">
        <button
            type="button"
            class="admin-sidebar-nav__search tooltip tooltip-right"
            data-command-palette-open
            hidden
            aria-label="Search permitted staff tools"
            aria-haspopup="dialog"
            aria-controls="admin-command-palette"
            :data-tip="collapsed ? 'Search staff tools' : ''"
        >
            <x-icon name="o-magnifying-glass" class="size-5" />
            <span class="admin-sidebar-nav__search-label mary-hideable">Search staff tools</span>
            <kbd class="admin-sidebar-nav__shortcut mary-hideable" aria-label="Control or Command K">⌘K</kbd>
        </button>

        <ul class="admin-sidebar-nav__primary-list">
            @foreach($primaryNavigation as $item)
                @php($queueIncomplete = $item['id'] === 'work-queue' && ! $pendingComplete)
                <li @class(['admin-nav-item', 'is-active' => $item['active']])>
                    <a
                        href="{{ $item['route'] }}"
                        @class([
                            'admin-nav-link',
                            'admin-nav-link--primary',
                            'tooltip',
                            'tooltip-right',
                            'is-active' => $item['active'],
                            'has-warning' => $queueIncomplete,
                        ])
                        data-admin-navigation-id="{{ $item['id'] }}"
                        @if($item['active']) aria-current="page" @endif
                        aria-label="{{ $item['label'] }}{{ $item['badge'] ? ', '.$item['badge'].' pending' : '' }}{{ $queueIncomplete ? ', queue data incomplete' : '' }}"
                        :data-tip="collapsed ? @js($item['label']) : ''"
                    >
                        <x-icon :name="$item['icon']" class="admin-nav-link__icon size-5" />
                        <span class="admin-nav-link__label mary-hideable">{{ $item['label'] }}</span>

                        @if($queueIncomplete)
                            <span class="admin-nav-link__badge-slot mary-hideable" title="Queue data incomplete">
                                <x-icon name="o-exclamation-triangle" class="size-4 text-warning" />
                            </span>
                            <span class="admin-nav-link__attention-dot display-when-collapsed hidden" aria-hidden="true"></span>
                            <span class="sr-only" role="status">
                                {{ count($pendingUnavailable) }} {{ str('queue source')->plural(count($pendingUnavailable)) }} unavailable.
                            </span>
                        @elseif($item['badge'])
                            <span class="admin-nav-link__badge-slot mary-hideable">
                                <span class="admin-nav-link__badge" aria-label="{{ $item['badge'] }} pending">
                                    {{ $item['badge'] }}
                                </span>
                            </span>
                            <span class="admin-nav-link__attention-dot display-when-collapsed hidden" aria-hidden="true"></span>
                        @endif
                    </a>

                    <x-admin.navigation-pin :navigation-id="$item['id']" :label="$item['label']" />
                </li>
            @endforeach
        </ul>
    </div>

    @if($quickAccessItems !== [])
        <section class="admin-sidebar-nav__quick-access" data-admin-quick-access hidden>
            <div class="mary-hideable">
                <div class="admin-sidebar-nav__section-heading">
                    <h2>Pinned</h2>
                    <span>Up to 5</span>
                </div>
            </div>

            <ul class="admin-sidebar-nav__quick-list" data-admin-quick-access-list></ul>

            <div class="mary-hideable">
                <p
                    class="admin-sidebar-nav__quick-empty"
                    data-admin-quick-access-empty
                    hidden
                >
                    <x-icon name="o-star" class="size-4" />
                    Use the star beside any link
                </p>
            </div>

            <div class="mary-hideable">
                <p
                    class="admin-pin-limit-message"
                    data-admin-pin-limit-status
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    hidden
                ></p>
            </div>
        </section>

        <div hidden aria-hidden="true" data-admin-quick-access-templates>
            @foreach($quickAccessItems as $item)
                <template data-admin-quick-access-template="{{ $item['id'] }}">
                    <li @class(['admin-nav-item', 'is-active' => $item['active']])>
                        <a
                            href="{{ $item['route'] }}"
                            @class([
                                'admin-nav-link',
                                'admin-nav-link--quick',
                                'tooltip',
                                'tooltip-right',
                                'is-active' => $item['active'],
                            ])
                            data-admin-navigation-id="{{ $item['id'] }}"
                            @if($item['active']) aria-current="page" @endif
                            aria-label="{{ $item['label'] }}"
                            :data-tip="collapsed ? @js($item['label']) : ''"
                        >
                            <x-icon :name="$item['icon']" class="admin-nav-link__icon size-5" />
                            <span class="admin-nav-link__label mary-hideable">{{ $item['label'] }}</span>

                            @if($item['badge'])
                                <span class="admin-nav-link__badge-slot mary-hideable">
                                    <span class="admin-nav-link__badge" aria-label="{{ $item['badge'] }} pending">
                                        {{ $item['badge'] }}
                                    </span>
                                </span>
                            @endif
                        </a>

                        <x-admin.navigation-pin :navigation-id="$item['id']" :label="$item['label']" />
                    </li>
                </template>
            @endforeach
        </div>
    @endif

    @if($departments !== [])
        <div class="admin-sidebar-nav__departments" aria-label="Departments">
            @foreach($departments as $department)
                @include('livewire.admin.partials.sidebar-department', ['group' => $department, 'system' => false])
            @endforeach
        </div>
    @endif

    @if($systemNavigation)
        <div class="admin-sidebar-nav__system">
            @include('livewire.admin.partials.sidebar-department', ['group' => $systemNavigation, 'system' => true])
        </div>
    @endif
</nav>
