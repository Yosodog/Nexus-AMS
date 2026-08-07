@props([
    'loading' => 'Working…',
    'target' => null,
])

<div
    class="nexus-async-inline"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span wire:loading.delay @if($target) wire:target="{{ $target }}" @endif>
        <span class="loading loading-spinner loading-sm" aria-hidden="true"></span>
        {{ $loading }}
    </span>
    <span wire:offline>
        You are offline. This action will not be sent until your connection returns.
    </span>
</div>
