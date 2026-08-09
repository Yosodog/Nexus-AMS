@props([
    'value' => null,
    'serverNow' => null,
    'label' => null,
    'fallback' => 'Not recorded',
    'showExact' => false,
    'staleAfter' => null,
    'clockSkewThreshold' => 60,
])

@php
    $toTimeDisplayCarbon = static function (mixed $candidate): ?\Carbon\CarbonImmutable {
        if ($candidate === null || $candidate === '') {
            return null;
        }

        try {
            return $candidate instanceof \DateTimeInterface
                ? \Carbon\CarbonImmutable::instance($candidate)
                : \Carbon\CarbonImmutable::parse((string) $candidate, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    };

    $displayTime = $toTimeDisplayCarbon($value);
    $serverReference = $toTimeDisplayCarbon($serverNow) ?? \Carbon\CarbonImmutable::now();
    $staleAfterSeconds = is_numeric($staleAfter) ? max(0, (int) $staleAfter) : null;
    $skewThresholdSeconds = max(1, (int) $clockSkewThreshold);
@endphp

@if($displayTime === null)
    <span {{ $attributes }}>{{ $fallback }}</span>
@else
    @php
        $relativeTime = $displayTime->diffForHumans(
            $serverReference,
            \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
            true,
            1,
        );
        $exactTime = $displayTime->translatedFormat('M j, Y, g:i:s A T');
        $accessibleLabel = trim(($label ? $label.'. ' : '').$relativeTime.'. Exact time '.$exactTime.'.');
    @endphp

    <span
        {{ $attributes->class([
            'relative inline-flex',
            'flex-col items-start' => $showExact,
            'items-baseline gap-x-2' => ! $showExact,
        ]) }}
        data-nexus-time-display
        data-time-state="server"
        data-time-value="{{ $displayTime->toIso8601String() }}"
        data-server-reference="{{ $serverReference->toIso8601String() }}"
        data-time-label="{{ $label }}"
        data-clock-skew-threshold="{{ $skewThresholdSeconds }}"
        @if($staleAfterSeconds !== null) data-time-stale-after="{{ $staleAfterSeconds }}" @endif
    >
        <span
            class="tooltip tooltip-bottom"
            data-time-tooltip
            data-tip="{{ $exactTime }}"
        >
            <time
                datetime="{{ $displayTime->toIso8601String() }}"
                class="cursor-help rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                data-time-relative
                title="{{ $exactTime }}"
                tabindex="0"
                aria-label="{{ $accessibleLabel }}"
            >{{ $relativeTime }}</time>
        </span>
        <span
            data-time-exact
            aria-hidden="true"
            @class([
                'text-xs nexus-text-muted',
                'sr-only' => ! $showExact,
            ])
        >{{ $exactTime }}</span>
        <span data-time-stale-indicator class="text-xs font-medium text-warning" hidden>Stale</span>
        <span data-time-status class="sr-only" aria-live="polite" aria-atomic="true"></span>
    </span>

    @pushOnce('scripts', 'nexus-time-display-module')
        @vite('resources/js/time-display.js')
    @endPushOnce
@endif
