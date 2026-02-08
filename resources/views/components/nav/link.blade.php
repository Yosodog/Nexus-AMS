@props([
    'href',
    'icon' => null,
    'permission' => null,
    'active' => request()->url() === $href,
    'badge' => null,
    'badgeClass' => 'badge bg-primary ms-auto',
])

@php
    $canView = is_null($permission) || auth()->user()?->can($permission);
    $classes = 'nav-link';
    $classes .= $active ? ' active' : '';
    $classes .= !$canView ? ' disabled' : '';
@endphp

@if($canView)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if($icon)
            <i class="{{ $icon }}"></i>
        @endif
        <p class="d-flex align-items-center gap-2 w-100">
            <span class="flex-grow-1">{{ $slot }}</span>
            @if($badge)
                <span class="{{ $badgeClass }}">{{ $badge }}</span>
            @endif
        </p>
    </a>
@endif
