@props([
    'navigationId',
    'label',
])

<span class="admin-nav-pin-slot mary-hideable">
    <button
        type="button"
        {{ $attributes->class(['admin-nav-pin']) }}
        data-admin-pin-navigation="{{ $navigationId }}"
        data-admin-pin-label="{{ $label }}"
        aria-label="Pin {{ $label }}"
        aria-pressed="false"
        title="Pin {{ $label }}"
    >
        <x-icon name="o-star" class="size-4" />
    </button>
</span>
