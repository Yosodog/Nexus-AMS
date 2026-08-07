@props([
    'message' => null,
    'retry' => false,
    'retryLabel' => 'Try again',
    'state' => 'loading',
    'title' => null,
])

@php
    $states = [
        'loading' => ['title' => 'Loading', 'icon' => null, 'intent' => 'neutral'],
        'saving' => ['title' => 'Saving', 'icon' => null, 'intent' => 'neutral'],
        'pending_confirmation' => ['title' => 'Waiting for confirmation', 'icon' => 'o-clock', 'intent' => 'neutral'],
        'success' => ['title' => 'Up to date', 'icon' => 'o-check-circle', 'intent' => 'success'],
        'empty' => ['title' => 'Nothing to show', 'icon' => 'o-inbox', 'intent' => 'neutral'],
        'filtered_empty' => ['title' => 'No matches', 'icon' => 'o-funnel', 'intent' => 'neutral'],
        'stale' => ['title' => 'Showing older data', 'icon' => 'o-clock', 'intent' => 'warning'],
        'rate_limited' => ['title' => 'Refresh paused', 'icon' => 'o-clock', 'intent' => 'warning'],
        'offline' => ['title' => 'You are offline', 'icon' => 'o-wifi', 'intent' => 'warning'],
        'session_expired' => ['title' => 'Session expired', 'icon' => 'o-lock-closed', 'intent' => 'warning'],
        'temporary_failure' => ['title' => 'Temporarily unavailable', 'icon' => 'o-exclamation-triangle', 'intent' => 'failure'],
        'error' => ['title' => 'Could not complete the request', 'icon' => 'o-exclamation-circle', 'intent' => 'failure'],
    ];

    if (! array_key_exists($state, $states)) {
        throw new InvalidArgumentException("Unsupported async state [{$state}].");
    }

    $definition = $states[$state];
@endphp

<div
    data-async-state="{{ $state }}"
    {{ $attributes->class(['nexus-async-state', 'nexus-async-state--'.$definition['intent']]) }}
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="nexus-async-state__icon" aria-hidden="true">
        @if (in_array($state, ['loading', 'saving'], true))
            <span class="loading loading-spinner loading-md"></span>
        @else
            <x-icon :name="$definition['icon']" class="size-6" />
        @endif
    </span>
    <div class="min-w-0">
        <p class="font-semibold" data-async-state-title>{{ $title ?? $definition['title'] }}</p>
        @if ($message)
            <p class="nexus-body-muted mt-1" data-async-state-message>{{ $message }}</p>
        @else
            <p class="nexus-body-muted mt-1" data-async-state-message hidden></p>
        @endif
        @if (trim((string) $slot) !== '')
            <div class="nexus-body-muted mt-2">{{ $slot }}</div>
        @endif
        @if ($retry)
            <button type="button" class="btn btn-sm btn-outline mt-3" data-async-retry>{{ $retryLabel }}</button>
        @endif
    </div>
</div>
