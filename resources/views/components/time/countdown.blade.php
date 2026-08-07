@props([
    'target' => null,
    'serverNow' => null,
    'mode' => 'local',
    'label' => null,
    'fallback' => 'Target time unavailable',
    'staleAfter' => null,
    'staleText' => 'Refresh for a current countdown',
    'expiredText' => null,
    'clockSkewThreshold' => 60,
])

@php
    $toCountdownCarbon = static function (mixed $candidate): ?\Carbon\CarbonImmutable {
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

    if (! in_array($mode, ['local', 'pw-turn'], true)) {
        throw new \InvalidArgumentException('Time countdown mode must be either local or pw-turn.');
    }

    $targetTime = $toCountdownCarbon($target);
    $serverReference = $toCountdownCarbon($serverNow) ?? \Carbon\CarbonImmutable::now();
    $staleAfterSeconds = is_numeric($staleAfter) ? max(0, (int) $staleAfter) : null;
    $skewThresholdSeconds = max(1, (int) $clockSkewThreshold);
    $displayLabel = $label ?: ($mode === 'pw-turn' ? 'Next P&W turn' : 'Time remaining');
    $displayExpiredText = $expiredText ?: ($mode === 'pw-turn'
        ? 'Turn started — refresh for the next turn'
        : 'Target reached');
@endphp

@if($targetTime === null)
    <span {{ $attributes }}>{{ $fallback }}</span>
@else
    @php
        $remainingSeconds = max(0, $targetTime->getTimestamp() - $serverReference->getTimestamp());
        $days = intdiv($remainingSeconds, 86400);
        $hours = intdiv($remainingSeconds % 86400, 3600);
        $minutes = intdiv($remainingSeconds % 3600, 60);
        $seconds = $remainingSeconds % 60;
        $remainingParts = [];

        if ($days > 0) {
            $remainingParts[] = $days.'d';
        }

        if ($days > 0 || $hours > 0) {
            $remainingParts[] = $hours.'h';
        }

        if ($days > 0 || $hours > 0 || $minutes > 0) {
            $remainingParts[] = str_pad((string) $minutes, 2, '0', STR_PAD_LEFT).'m';
        }

        $remainingParts[] = str_pad((string) $seconds, 2, '0', STR_PAD_LEFT).'s';
        $remainingTime = implode(' ', $remainingParts);
        $targetExactTime = $targetTime->translatedFormat('M j, Y, g:i:s A T');
        $hasExpired = $remainingSeconds === 0;
        $timerLabel = $hasExpired
            ? $displayExpiredText.'. Target '.$targetExactTime.'.'
            : $displayLabel.'. '.$remainingTime.' remaining. Target '.$targetExactTime.'.';
    @endphp

    <span
        {{ $attributes->class(['inline-flex flex-col gap-1']) }}
        data-nexus-time-countdown
        data-time-state="{{ $hasExpired ? 'expired' : 'server' }}"
        data-time-countdown-mode="{{ $mode }}"
        data-time-target="{{ $targetTime->toIso8601String() }}"
        data-server-reference="{{ $serverReference->toIso8601String() }}"
        data-time-label="{{ $displayLabel }}"
        data-time-stale-text="{{ $staleText }}"
        data-time-expired-text="{{ $displayExpiredText }}"
        data-clock-skew-threshold="{{ $skewThresholdSeconds }}"
        data-clock-skewed="false"
        data-time-remaining-seconds="{{ $remainingSeconds }}"
        @if($staleAfterSeconds !== null) data-time-stale-after="{{ $staleAfterSeconds }}" @endif
    >
        <span class="inline-flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span class="font-medium">{{ $displayLabel }}</span>
            <span
                class="font-mono font-semibold tabular-nums"
                data-time-countdown-value
                role="timer"
                aria-live="off"
                aria-label="{{ $timerLabel }}"
            >{{ $hasExpired ? $displayExpiredText : $remainingTime }}</span>
        </span>
        <span class="text-xs nexus-text-muted">
            Target:
            <time datetime="{{ $targetTime->toIso8601String() }}" data-time-countdown-target>{{ $targetExactTime }}</time>
        </span>
        <span data-time-clock-warning class="text-xs font-medium text-warning" hidden>
            Device clock differs from the server; server time is being used.
        </span>
        <span data-time-status class="sr-only" aria-live="polite" aria-atomic="true"></span>
    </span>

    @pushOnce('scripts', 'nexus-time-display-module')
        @vite('resources/js/time-display.js')
    @endPushOnce
@endif
