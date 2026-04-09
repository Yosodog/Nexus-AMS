@props([
    'title' => '',
    'body' => '',
    'extraClasses' => '',
])

<x-card :title="$title ?: null" {{ $attributes->class($extraClasses) }}>
    {{ $slot }}

    @if($body)
        <p>{{ $body }}</p>
    @endif
</x-card>
