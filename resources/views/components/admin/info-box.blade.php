@props([
    'icon' => 'o-information-circle',
    'color' => 'text-primary',
    'title' => 'Info Box',
    'value' => 'N/A',
])

<x-stat
    :title="$title"
    :value="(string) $value"
    :icon="$icon"
    :color="$color"
    {{ $attributes }}
/>
