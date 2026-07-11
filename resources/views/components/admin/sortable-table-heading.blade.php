@props([
    'column',
    'currentSort',
    'currentDirection',
    'label',
])

@php
    $isActive = $currentSort === $column;
    $nextDirection = $isActive && $currentDirection === 'asc' ? 'desc' : 'asc';
    $ariaSort = $isActive
        ? ($currentDirection === 'asc' ? 'ascending' : 'descending')
        : null;
@endphp

<th scope="col" @if($ariaSort) aria-sort="{{ $ariaSort }}" @endif {{ $attributes }}>
    <a
        href="{{ route('admin.cities.index', ['sort' => $column, 'direction' => $nextDirection]) }}"
        class="inline-flex min-h-10 w-full items-center gap-1.5 text-left"
        title="Sort all cities by {{ $label }}"
    >
        <span>{{ $label }}</span>
        <span
            aria-hidden="true"
            @class([
                'text-xs',
                'text-primary' => $isActive,
                'opacity-30' => ! $isActive,
            ])
        >{{ $isActive ? ($currentDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
    </a>
</th>
