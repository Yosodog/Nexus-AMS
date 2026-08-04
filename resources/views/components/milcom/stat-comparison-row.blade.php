@props([
    'label',
    'friendlyValue',
    'enemyValue',
    'friendlyDetail' => null,
    'enemyDetail' => null,
    'friendlyTitle' => null,
    'enemyTitle' => null,
    'friendlyRaw' => null,
    'enemyRaw' => null,
])

@php
    $showBars = is_numeric($friendlyRaw) && is_numeric($enemyRaw);
    $largestValue = $showBars ? max(abs((float) $friendlyRaw), abs((float) $enemyRaw), 1) : 1;
    $friendlyWidth = $showBars ? min(100, abs((float) $friendlyRaw) / $largestValue * 100) : 0;
    $enemyWidth = $showBars ? min(100, abs((float) $enemyRaw) / $largestValue * 100) : 0;
@endphp

<div class="grid grid-cols-[minmax(0,1fr)_minmax(5.75rem,auto)_minmax(0,1fr)] items-center gap-3 px-4 py-3 sm:gap-5">
    <dd class="min-w-0 text-right">
        <div class="truncate font-semibold tabular-nums text-success" @if ($friendlyTitle) title="{{ $friendlyTitle }}" @endif>{{ $friendlyValue }}</div>
        @if ($friendlyDetail)
            <div class="truncate text-xs tabular-nums text-base-content/55">{{ $friendlyDetail }}</div>
        @endif
    </dd>
    <dt class="text-center text-xs font-semibold text-base-content/65 sm:text-sm">{{ $label }}</dt>
    <dd class="min-w-0">
        <div class="truncate font-semibold tabular-nums text-error" @if ($enemyTitle) title="{{ $enemyTitle }}" @endif>{{ $enemyValue }}</div>
        @if ($enemyDetail)
            <div class="truncate text-xs tabular-nums text-base-content/55">{{ $enemyDetail }}</div>
        @endif
    </dd>
    @if ($showBars)
        <div class="col-span-3 grid grid-cols-[minmax(0,1fr)_minmax(5.75rem,auto)_minmax(0,1fr)] gap-3 sm:gap-5" aria-hidden="true">
            <div class="flex h-1 justify-end overflow-hidden rounded-full bg-success/10">
                <span class="block h-full rounded-full bg-success/60" style="width: {{ $friendlyWidth }}%"></span>
            </div>
            <span></span>
            <div class="h-1 overflow-hidden rounded-full bg-error/10">
                <span class="block h-full rounded-full bg-error/60" style="width: {{ $enemyWidth }}%"></span>
            </div>
        </div>
    @endif
</div>
