@props(['title', 'value', 'desc' => null, 'icon' => null, 'color' => 'primary'])

<div class="stats shadow">
    <div class="stat min-h-[120px]">
        @if ($icon)
            <div class="stat-figure text-{{ $color }}">
                <x-dynamic-component :component="'icons.' . $icon" class="w-8 h-8" />
            </div>
        @endif

        <div class="stat-title truncate">{{ $title }}</div>
        <div class="stat-value text-{{ $color }} truncate max-w-full overflow-hidden text-ellipsis">
            {{ $value }}
        </div>
        @if ($desc)
            <div class="stat-desc">{{ $desc }}</div>
        @endif
    </div>
</div>