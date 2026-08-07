@props([
    'src' => null,
    'alt',
    'width',
    'height',
    'fallback' => '?',
    'fit' => 'cover',
    'loading' => 'lazy',
])

@php
    $resolvedWidth = filter_var($width, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $resolvedHeight = filter_var($height, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($resolvedWidth === false || $resolvedHeight === false) {
        throw new \InvalidArgumentException('Lazy image width and height must be positive integers.');
    }

    if (! in_array($fit, ['contain', 'cover'], true)) {
        throw new \InvalidArgumentException("Unsupported lazy image fit [{$fit}].");
    }

    if (! in_array($loading, ['lazy', 'eager'], true)) {
        throw new \InvalidArgumentException("Unsupported image loading mode [{$loading}].");
    }

    $resolvedAlt = (string) $alt;
    $resolvedFallback = filled($fallback) ? (string) $fallback : '?';
    $imageFitClass = $fit === 'contain' ? 'object-contain' : 'object-cover';
    $forwardedAttributes = $attributes->except(['aria-hidden', 'aria-label', 'role', 'style']);
@endphp

<span
    data-lazy-image
    @if (filled($resolvedAlt)) role="img" aria-label="{{ $resolvedAlt }}" @else aria-hidden="true" @endif
    style="aspect-ratio: {{ $resolvedWidth }} / {{ $resolvedHeight }};"
    {{ $forwardedAttributes->class('relative inline-grid shrink-0 place-items-center overflow-hidden bg-base-200 text-base-content/60') }}
>
    <span
        data-lazy-image-fallback
        aria-hidden="true"
        class="col-start-1 row-start-1 max-w-full truncate px-1 text-center text-[0.625rem] font-semibold uppercase tracking-wide"
    >{{ $resolvedFallback }}</span>

    @if (filled($src))
        <img
            data-lazy-image-source
            src="{{ $src }}"
            alt=""
            width="{{ $resolvedWidth }}"
            height="{{ $resolvedHeight }}"
            loading="{{ $loading }}"
            decoding="async"
            class="col-start-1 row-start-1 h-full w-full {{ $imageFitClass }}"
            x-data
            x-on:error="$el.hidden = true"
        >
    @endif
</span>
