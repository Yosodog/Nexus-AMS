@props([
    'resources' => [],
])

@php
    if (! is_array($resources) && ! $resources instanceof \Illuminate\Contracts\Support\Arrayable) {
        throw new \InvalidArgumentException('Resource vectors require an array of resource values.');
    }

    $resourceValues = $resources instanceof \Illuminate\Contracts\Support\Arrayable
        ? $resources->toArray()
        : $resources;
    $canonicalVector = collect(\App\Services\PWHelperService::resources())
        ->map(fn (string $resource): string => $resource.'='.number_format(
            (float) ($resourceValues[$resource] ?? 0),
            2,
            '.',
            '',
        ))
        ->implode(';');
@endphp

<x-copy-action
    :value="$canonicalVector"
    label="resource vector"
    {{ $attributes }}
/>
