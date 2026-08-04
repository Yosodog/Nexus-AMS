@php
    $milcomCurrent = $milcomCurrent ?? 'dashboard';
    $milcomNavigation = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => url('/admin/milcom'), 'icon' => 'o-command-line'],
        ['key' => 'plans', 'label' => 'Mass plans', 'href' => url('/admin/milcom/plans'), 'icon' => 'o-map'],
        ['key' => 'counters', 'label' => 'Fast counters', 'href' => url('/admin/milcom/counters'), 'icon' => 'o-bolt'],
        ['key' => 'archive', 'label' => 'Archive', 'href' => url('/admin/milcom/archive'), 'icon' => 'o-archive-box'],
        ['key' => 'settings', 'label' => 'Settings', 'href' => url('/admin/milcom/settings'), 'icon' => 'o-cog-6-tooth'],
    ];
@endphp

<nav class="-mt-2 overflow-x-auto border-b border-base-300" aria-label="Milcom sections">
    <div class="flex min-w-max gap-1 pb-2">
        @foreach ($milcomNavigation as $item)
            @php
                $isCurrent = $milcomCurrent === $item['key'];
            @endphp
            <a
                href="{{ $item['href'] }}"
                class="btn btn-sm border-0 {{ $isCurrent ? 'bg-primary/12 text-primary shadow-none' : 'btn-ghost text-base-content/70' }}"
                @if ($isCurrent) aria-current="page" @endif
            >
                <x-icon :name="$item['icon']" class="size-4" aria-hidden="true" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
