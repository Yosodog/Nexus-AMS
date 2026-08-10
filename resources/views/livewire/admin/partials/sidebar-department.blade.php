<section
    @class(['admin-nav-department', 'admin-nav-department--system' => $system])
    data-admin-department="{{ $group['id'] }}"
    data-admin-department-count="{{ $group['badge'] ?? 0 }}"
    aria-labelledby="admin-nav-department-{{ $group['id'] }}"
>
    <div class="mary-hideable">
        <div class="admin-nav-department__heading">
            <x-icon :name="$group['icon']" class="admin-nav-department__heading-icon size-4" />
            <h2 id="admin-nav-department-{{ $group['id'] }}" class="admin-nav-department__label">
                {{ $group['label'] }}
            </h2>

            @if($group['badge'])
                <span class="admin-nav-department__badge" aria-label="{{ $group['badge'] }} pending">
                    <span class="admin-nav-badge__value">{{ $group['badge'] }}</span>
                </span>
            @endif
        </div>
    </div>

    <div class="admin-nav-department__panel">
        @foreach($group['sections'] as $section)
            <details
                class="admin-nav-section"
                data-admin-navigation-section="{{ $group['id'] }}.{{ $section['id'] }}"
                @if($section['active']) open @endif
            >
                <summary
                    class="admin-nav-section__summary tooltip tooltip-right"
                    aria-controls="admin-nav-section-{{ $group['id'] }}-{{ $section['id'] }}"
                    aria-expanded="{{ $section['active'] ? 'true' : 'false' }}"
                    aria-label="{{ $section['label'] }}{{ $section['badge'] ? ', '.$section['badge'].' pending' : '' }}"
                    :data-tip="collapsed ? @js($section['label']) : ''"
                    @click="
                        if (collapsed) {
                            $event.preventDefault();
                            toggle();
                            $nextTick(() => {
                                $el.closest('details').open = true;
                                $el.focus();
                            });
                        }
                    "
                >
                    <x-icon :name="$section['icon']" class="admin-nav-section__icon size-5" />
                    <span class="admin-nav-section__label mary-hideable">{{ $section['label'] }}</span>

                    @if($section['badge'])
                        <span class="admin-nav-section__badge-slot mary-hideable">
                            <span class="admin-nav-section__badge" aria-label="{{ $section['badge'] }} pending">
                                <span class="admin-nav-badge__value">{{ $section['badge'] }}</span>
                            </span>
                        </span>
                        <span class="admin-nav-link__attention-dot display-when-collapsed hidden" aria-hidden="true"></span>
                    @endif

                    <x-icon name="o-chevron-right" class="admin-nav-section__chevron mary-hideable size-4" />
                </summary>

                <div
                    id="admin-nav-section-{{ $group['id'] }}-{{ $section['id'] }}"
                    class="admin-nav-section__panel mary-hideable"
                >
                    <ul class="admin-nav-group__items">
                        @foreach($section['items'] as $item)
                            <li @class(['admin-nav-item', 'is-active' => $item['active']])>
                                <a
                                    href="{{ $item['route'] }}"
                                    @class(['admin-nav-link', 'is-active' => $item['active']])
                                    data-admin-navigation-id="{{ $item['id'] }}"
                                    @if($item['active']) aria-current="page" @endif
                                    aria-label="{{ $item['label'] }}{{ $item['badge'] ? ', '.$item['badge'].' pending' : '' }}"
                                    title="{{ $item['label'] }}"
                                >
                                    <x-icon :name="$item['icon']" class="admin-nav-link__icon size-5" />
                                    <span class="admin-nav-link__label">{{ $item['label'] }}</span>

                                    @if($item['badge'])
                                        <span class="admin-nav-link__badge-slot">
                                            <span class="admin-nav-link__badge" aria-label="{{ $item['badge'] }} pending">
                                                {{ $item['badge'] }}
                                            </span>
                                        </span>
                                    @endif
                                </a>

                                <x-admin.navigation-pin :navigation-id="$item['id']" :label="$item['label']" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            </details>
        @endforeach
    </div>
</section>
