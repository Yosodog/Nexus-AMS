@props([
    'href',
    'icon' => null,
    'permission' => null,
    'active' => request()->url() === $href,
])

@php
    $canView = is_null($permission) || auth()->user()?->can($permission);
    $classes = 'nav-link';
    $classes .= $active ? ' active' : '';
    $classes .= !$canView ? ' disabled' : '';
@endphp

<a href="{{ $canView ? $href : '#' }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if($icon)
        <i class="{{ $icon }}"></i>
    @endif
    <p>{{ $slot }}</p>
</a>