@props(['title', 'value', 'desc' => null, 'icon' => null, 'color' => 'text-primary'])

@php
    $iconAliases = [
        'chart' => 'o-chart-bar',
        'bank' => 'o-building-library',
        'city' => 'o-building-office-2',
        'gift' => 'o-gift',
        'clock' => 'o-clock',
        'credit-card' => 'o-credit-card',
    ];

    $resolvedIcon = null;

    if ($icon) {
        $resolvedIcon = str_starts_with($icon, 'o-')
            ? $icon
            : ($iconAliases[$icon] ?? 'o-' . $icon);
    }
@endphp

<x-stat
    :title="$title"
    :value="$value"
    :description="$desc"
    :icon="$resolvedIcon"
    :color="$color"
    {{ $attributes }}
/>
