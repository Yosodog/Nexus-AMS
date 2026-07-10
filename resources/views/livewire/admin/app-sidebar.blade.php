<nav class="admin-sidebar-nav" aria-label="Administrative navigation" data-admin-navigation>
    <div class="admin-sidebar-nav__heading">
        <span class="admin-sidebar-nav__mark" aria-hidden="true">A</span>
        <div class="mary-hideable min-w-0">
            <p class="admin-sidebar-nav__title">Administration</p>
            <p class="admin-sidebar-nav__subtitle">Operations workspace</p>
        </div>
    </div>

    @if($pendingTotal > 0)
        <div class="admin-sidebar-nav__workload mary-hideable" role="status">
            <span class="nexus-status nexus-status--warning">{{ $pendingTotal }} pending</span>
            <p>Requests need staff review.</p>
        </div>
    @endif

    <div class="admin-sidebar-nav__groups">
        @foreach($navigation as $group)
            <section class="admin-nav-group" aria-labelledby="admin-nav-{{ str($group['label'])->slug() }}">
                <h2 id="admin-nav-{{ str($group['label'])->slug() }}" class="admin-nav-group__label mary-hideable">
                    {{ $group['label'] }}
                </h2>

                <ul class="admin-nav-group__items">
                    @foreach($group['items'] as $item)
                        <li>
                            <a
                                href="{{ $item['route'] }}"
                                @class(['admin-nav-link', 'tooltip', 'tooltip-right', 'is-active' => $item['active']])
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
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</nav>
