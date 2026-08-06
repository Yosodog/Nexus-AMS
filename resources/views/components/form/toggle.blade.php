@props([
    'errorKey' => null,
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'value' => '1',
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
    <label for="{{ $fieldId }}" class="flex min-h-11 cursor-pointer items-center justify-between gap-4">
        <span class="label text-base-content">{{ $label }}</span>
        <input
            id="{{ $fieldId }}"
            name="{{ $name }}"
            type="checkbox"
            value="{{ $value }}"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($error || $attributes->get('aria-invalid') === 'true') aria-invalid="true" @endif
            {{ $attributes->except(['aria-describedby', 'aria-invalid'])->class(['toggle']) }}
        >
    </label>

    @if ($hint)
        <p id="{{ $fieldId }}-help" class="nexus-text-muted text-sm">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $fieldId }}-error" class="text-sm font-medium text-error" role="alert">{{ $error }}</p>
    @endif
</div>
