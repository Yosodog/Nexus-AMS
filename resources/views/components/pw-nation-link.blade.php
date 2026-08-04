@props([
    'nationId',
    'label' => null,
])

@php
    $resolvedNationId = filter_var($nationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $resolvedLabel = filled($label) ? (string) $label : trim((string) $slot);
@endphp

@if ($resolvedNationId)
    <a
        href="https://politicsandwar.com/nation/id={{ $resolvedNationId }}"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="{{ $resolvedLabel }}, open nation on Politics &amp; War in a new tab"
        {{ $attributes->except(['href', 'target', 'rel', 'aria-label'])->class('link transition-colors') }}
    >{{ $resolvedLabel }}<span class="sr-only"> (opens in a new tab)</span></a>
@else
    <span {{ $attributes->except(['href', 'target', 'rel', 'aria-label']) }}>{{ $resolvedLabel }}</span>
@endif
