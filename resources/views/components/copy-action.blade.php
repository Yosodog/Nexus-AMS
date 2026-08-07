@props([
    'value' => null,
    'label' => 'value',
])

@php
    $canonicalValue = is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    $resolvedLabel = trim((string) $label);

    if ($canonicalValue === null || $canonicalValue === '') {
        throw new \InvalidArgumentException('Copy actions require an explicit, non-empty canonical value.');
    }

    if ($resolvedLabel === '') {
        throw new \InvalidArgumentException('Copy actions require a descriptive label.');
    }

    $displayLabel = \Illuminate\Support\Str::ucfirst($resolvedLabel);
    $statusId = 'copy-action-status-'.\Illuminate\Support\Str::uuid();
@endphp

<span
    data-copy-action
    data-copy-value="{{ $canonicalValue }}"
    data-copy-success-message="Copied {{ $resolvedLabel }}."
    data-copy-selected-message="Copy unavailable. {{ $displayLabel }} selected; use your device's copy command."
    data-copy-manual-message="Copy unavailable. Select the {{ $resolvedLabel }} and use your device's copy command."
    {{ $attributes
        ->except([
            'data-copy-action',
            'data-copy-value',
            'data-copy-success-message',
            'data-copy-selected-message',
            'data-copy-manual-message',
        ])
        ->class('inline-flex max-w-full flex-wrap items-center gap-1.5 align-middle') }}
>
    <span class="text-xs font-medium nexus-text-muted">{{ $displayLabel }}:</span>
    <code data-copy-readable class="max-w-full break-all rounded bg-base-300 px-2 py-1 text-xs font-semibold text-base-content">{{ $canonicalValue }}</code>
    <button
        type="button"
        class="btn btn-ghost btn-sm min-h-11 px-3"
        data-copy-trigger
        hidden
        aria-label="Copy {{ $resolvedLabel }}: {{ $canonicalValue }}"
        aria-describedby="{{ $statusId }}"
    >
        <x-icon name="o-clipboard-document" class="size-4" aria-hidden="true" />
        <span>Copy</span>
    </button>
    <span
        id="{{ $statusId }}"
        data-copy-status
        class="text-xs font-medium nexus-text-muted empty:hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    ></span>
</span>
