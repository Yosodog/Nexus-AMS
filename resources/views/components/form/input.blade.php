@props([
    'errorKey' => null,
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'optional' => false,
    'type' => 'text',
    'value' => null,
])

@php
    $fieldId = $id ?? Str::slug($name);
    $validationKey = $errorKey ?? $name;
    $error = isset($errors) ? $errors->first($validationKey) : null;
    $describedBy = collect([
        $hint ? $fieldId.'-help' : null,
        $error ? $fieldId.'-error' : null,
        $attributes->get('aria-describedby'),
    ])->filter()->implode(' ');
@endphp

<div class="grid gap-2">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <label for="{{ $fieldId }}" class="label text-base-content">{{ $label }}</label>
        @if ($optional)
            <span class="nexus-text-muted text-xs">Optional</span>
        @endif
    </div>

    @if ($hint)
        <p id="{{ $fieldId }}-help" class="nexus-text-muted text-sm">{{ $hint }}</p>
    @endif

    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'file') value="{{ old($name, $value) }}" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($error || $attributes->get('aria-invalid') === 'true') aria-invalid="true" @endif
        {{ $attributes->except(['aria-describedby', 'aria-invalid'])->class(['input w-full']) }}
    >

    @if ($error)
        <p id="{{ $fieldId }}-error" class="text-sm font-medium text-error" role="alert">{{ $error }}</p>
    @endif
</div>
