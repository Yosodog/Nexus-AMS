@props([
    'nation' => [],
    'variant' => 'compact',
    'dynamic' => false,
    'label' => 'Military',
])

@php
    $isTiles = $variant === 'tiles';
    $military = [
        'soldiers' => 'Soldiers',
        'tanks' => 'Tanks',
        'aircraft' => 'Aircraft',
        'ships' => 'Ships',
    ];
@endphp

<dl
    aria-label="{{ $label }}"
    {{ $attributes->class($isTiles
        ? 'grid grid-cols-2 gap-px overflow-hidden rounded-md border border-base-300 bg-base-300 sm:grid-cols-4'
        : 'flex flex-wrap gap-x-4 gap-y-1 text-xs text-base-content/60') }}
>
    @foreach ($military as $key => $statLabel)
        <div class="{{ $isTiles ? 'bg-base-100 p-3' : 'flex items-baseline gap-1' }}">
            <dt class="{{ $isTiles ? 'nexus-stat-label' : '' }}">{{ $statLabel }}</dt>
            <dd
                @if ($dynamic) data-milcom-field="{{ $key }}" @endif
                data-milcom-military="{{ $key }}"
                class="font-semibold tabular-nums text-base-content {{ $isTiles ? 'mt-1' : '' }}"
            >
                {{ is_numeric(data_get($nation, $key)) ? number_format((int) data_get($nation, $key)) : 'Not available' }}
            </dd>
        </div>
    @endforeach
</dl>
