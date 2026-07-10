@props([
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'optional' => false,
])

@php
    $fieldId = $id ?? $name;
    $error = $errors->first($name);
@endphp

<div class="grid gap-2">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <label for="{{ $fieldId }}" class="text-sm font-semibold text-base-content">{{ $label }}</label>
        @if($optional)
            <span class="text-xs text-base-content/70">Optional</span>
        @endif
    </div>

    @if($hint)
        <p id="{{ $fieldId }}-help" class="text-xs leading-5 text-base-content/70">{{ $hint }}</p>
    @endif

    {{ $slot }}

    @if($error)
        <p id="{{ $fieldId }}-error" class="flex items-start gap-1.5 text-sm text-error">
            <x-icon name="o-exclamation-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
