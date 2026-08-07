@props([
    'explanation' => null,
    'icon' => null,
    'intent' => null,
    'label' => null,
])

@php
    $intents = ['neutral', 'pending', 'active', 'success', 'warning', 'failure'];
    $icons = [
        'archive-box' => 'o-archive-box',
        'arrow-path' => 'o-arrow-path',
        'bolt' => 'o-bolt',
        'check-circle' => 'o-check-circle',
        'clock' => 'o-clock',
        'eye' => 'o-eye',
        'exclamation-triangle' => 'o-exclamation-triangle',
        'lock-closed' => 'o-lock-closed',
        'minus-circle' => 'o-minus-circle',
        'paper-airplane' => 'o-paper-airplane',
        'pencil-square' => 'o-pencil-square',
        'x-circle' => 'o-x-circle',
    ];

    if (! is_string($label) && ! $label instanceof \Stringable) {
        throw new \InvalidArgumentException('The status label must be a string.');
    }

    $labelText = trim((string) $label);

    if ($labelText === '') {
        throw new \InvalidArgumentException('The status label is required.');
    }

    if (! is_string($intent) || ! in_array($intent, $intents, true)) {
        $receivedIntent = is_scalar($intent) ? (string) $intent : get_debug_type($intent);

        throw new \InvalidArgumentException("Unsupported status intent [{$receivedIntent}].");
    }

    if (! is_string($icon) || ! array_key_exists($icon, $icons)) {
        $receivedIcon = is_scalar($icon) ? (string) $icon : get_debug_type($icon);

        throw new \InvalidArgumentException("Unsupported status icon [{$receivedIcon}].");
    }

    if ($explanation !== null && ! is_string($explanation) && ! $explanation instanceof \Stringable) {
        throw new \InvalidArgumentException('The status explanation must be a string or null.');
    }

    $explanationText = trim((string) ($explanation ?? ''));
    $forwardedAttributes = $attributes->except(['aria-hidden', 'data-status-icon', 'data-status-intent']);
@endphp

<span
    data-status-icon="{{ $icon }}"
    data-status-intent="{{ $intent }}"
    {{ $forwardedAttributes->class([
        'nexus-status',
        'nexus-status--semantic',
        'nexus-status--'.$intent,
        'nexus-status--explained' => $explanationText !== '',
    ]) }}
>
    <x-icon :name="$icons[$icon]" class="nexus-status__icon" aria-hidden="true" />
    <span class="nexus-status__copy">
        <span class="nexus-status__label">{{ $labelText }}</span>
        @if ($explanationText !== '')
            <span class="nexus-status__explanation"><span aria-hidden="true">—</span> {{ $explanationText }}</span>
        @endif
    </span>
</span>
