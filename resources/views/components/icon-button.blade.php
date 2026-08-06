@props([
    'disabled' => false,
    'href' => null,
    'label' => null,
    'loading' => false,
    'tooltip' => null,
    'type' => 'button',
    'variant' => 'default',
])

@php
    if (blank($label)) {
        throw new \InvalidArgumentException('The icon button label is required.');
    }

    if (! in_array($variant, ['default', 'compact'], true)) {
        throw new \InvalidArgumentException("Unsupported icon button variant [{$variant}].");
    }

    $isLink = filled($href);
    $isLoading = (bool) $loading;
    $isDisabled = (bool) $disabled || $isLoading;
    $tooltipText = filled($tooltip) ? (string) $tooltip : (string) $label;
    $classes = [
        'btn nexus-icon-button',
        'btn-sm nexus-icon-button--compact' => $variant === 'compact',
        'nexus-icon-button--default' => $variant === 'default',
        'tooltip',
        'btn-disabled' => $isDisabled,
    ];
    $forwardedAttributes = $attributes->except([
        'aria-busy',
        'aria-disabled',
        'aria-label',
        'data-tip',
        'disabled',
        'href',
        'type',
    ]);
@endphp

@if ($isLink)
    <a
        @unless ($isDisabled) href="{{ $href }}" @endunless
        aria-label="{{ $label }}"
        @if ($isDisabled) aria-disabled="true" tabindex="-1" @endif
        @if ($isLoading) aria-busy="true" @endif
        data-tip="{{ $tooltipText }}"
        {{ $forwardedAttributes->except($isDisabled ? ['tabindex'] : [])->class($classes) }}
    >
        <span class="nexus-icon-button__icon inline-flex size-5 shrink-0 items-center justify-center" aria-hidden="true">
            {{ $slot }}
        </span>
        @if ($isLoading)
            <span class="nexus-icon-button__loading loading loading-spinner loading-xs" aria-hidden="true"></span>
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        aria-label="{{ $label }}"
        @disabled($isDisabled)
        @if ($isDisabled) aria-disabled="true" @endif
        @if ($isLoading) aria-busy="true" @endif
        data-tip="{{ $tooltipText }}"
        {{ $forwardedAttributes->class($classes) }}
    >
        <span class="nexus-icon-button__icon inline-flex size-5 shrink-0 items-center justify-center" aria-hidden="true">
            {{ $slot }}
        </span>
        @if ($isLoading)
            <span class="nexus-icon-button__loading loading loading-spinner loading-xs" aria-hidden="true"></span>
        @endif
    </button>
@endif
