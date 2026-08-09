<details
    @class(['admin-nav-department', 'admin-nav-department--system' => $system])
    data-admin-department="{{ $group['id'] }}"
    data-admin-department-count="{{ $group['badge'] ?? 0 }}"
    @if($group['active']) open @endif
>
    <summary
        class="admin-nav-department__summary tooltip tooltip-right"
        aria-controls="admin-nav-department-{{ $group['id'] }}"
        aria-expanded="{{ $group['active'] ? 'true' : 'false' }}"
        aria-label="{{ $group['label'] }}{{ $group['badge'] ? ', '.$group['badge'].' pending' : '' }}"
        :data-tip="collapsed ? @js($group['label']) : ''"
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
        <x-icon :name="$group['icon']" class="admin-nav-department__icon size-5" />
        <span class="admin-nav-department__label mary-hideable">{{ $group['label'] }}</span>

        @if($group['badge'])
            <span class="admin-nav-department__badge mary-hideable" aria-label="{{ $group['badge'] }} pending">
                {{ $group['badge'] }}
            </span>
            <span class="admin-nav-link__attention-dot display-when-collapsed hidden" aria-hidden="true"></span>
        @endif

        <x-icon name="o-chevron-right" class="admin-nav-department__chevron mary-hideable size-4" />
    </summary>

    <div id="admin-nav-department-{{ $group['id'] }}" class="admin-nav-department__panel mary-hideable">
        @foreach(collect($group['items'])->groupBy('section') as $section => $items)
            <section class="admin-nav-subgroup" aria-labelledby="admin-nav-{{ $group['id'] }}-{{ str($section)->slug() }}">
                @if($section !== '')
                    <h3 id="admin-nav-{{ $group['id'] }}-{{ str($section)->slug() }}" class="admin-nav-subgroup__label">
                        {{ $section }}
                    </h3>
                @endif

                <ul class="admin-nav-group__items">
                    @foreach($items as $item)
                        <li>
                            <a
                                href="{{ $item['route'] }}"
                                @class(['admin-nav-link', 'is-active' => $item['active']])
                                data-admin-navigation-id="{{ $item['id'] }}"
                                @if($item['active']) aria-current="page" @endif
                                aria-label="{{ $item['label'] }}{{ $item['badge'] ? ', '.$item['badge'].' pending' : '' }}"
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
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</details>
