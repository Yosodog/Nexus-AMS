@props([
    'error' => null,
    'helpClass' => 'nexus-text-muted text-sm',
    'hint' => null,
    'id',
    'label',
    'labelClass' => 'label font-semibold text-base-content',
    'layout' => 'stacked',
    'optional' => false,
    'required' => false,
])

@php
    $hasHelp = (isset($help) && $help->hasActualContent()) || filled($hint);
    $hasStatus = isset($status) && $status->hasActualContent();
    $indicator = $required ? 'Required' : ($optional ? 'Optional' : null);
@endphp

<div {{ $attributes->class(['grid gap-2']) }}>
    @if ($layout === 'toggle')
        <label for="{{ $id }}" class="flex min-h-11 cursor-pointer items-center justify-between gap-4">
            <span class="flex flex-wrap items-baseline gap-2">
                <span class="{{ $labelClass }}">{{ $label }}</span>
                @if ($indicator)
                    <span class="nexus-text-muted text-xs">{{ $indicator }}</span>
                @endif
            </span>
            {{ $slot }}
        </label>
    @else
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <label for="{{ $id }}" class="{{ $labelClass }}">{{ $label }}</label>
            @if ($indicator)
                <span class="nexus-text-muted text-xs">{{ $indicator }}</span>
            @endif
        </div>

        @if ($hasHelp)
            <div id="{{ $id }}-help" class="{{ $helpClass }}">
                @if (isset($help) && $help->hasActualContent())
                    {{ $help }}
                @else
                    {{ $hint }}
                @endif
            </div>
        @endif

        {{ $slot }}
    @endif

    @if ($layout === 'toggle' && $hasHelp)
        <div id="{{ $id }}-help" class="{{ $helpClass }}">
            @if (isset($help) && $help->hasActualContent())
                {{ $help }}
            @else
                {{ $hint }}
            @endif
        </div>
    @endif

    @if ($hasStatus)
        <div
            id="{{ $id }}-status"
            class="text-sm text-base-content/70"
            role="status"
            aria-live="polite"
            aria-atomic="true"
        >{{ $status }}</div>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-sm font-medium text-error" role="alert">
            <x-icon name="o-exclamation-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
