@props([
    'busyLabel' => 'Working…',
    'type' => 'button',
])

<button
    type="{{ $type }}"
    data-async-button
    data-async-busy-label="{{ $busyLabel }}"
    {{ $attributes->class(['btn']) }}
>
    <span data-async-button-spinner class="loading loading-spinner loading-sm" aria-hidden="true" hidden></span>
    <span data-async-button-label>{{ $slot }}</span>
</button>
