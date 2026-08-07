@props([
    'errorBag' => 'default',
    'errorKey' => null,
    'errorKeys' => null,
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'optional' => false,
    'required' => false,
    'type' => 'text',
    'value' => null,
])

@php
    $baseFieldId = Illuminate\Support\Str::slug($name) ?: 'field';
    $fieldId = $id ?? $baseFieldId.'-'.substr(hash('sha256', $name.'|'.$label), 0, 8);
    $validationKeys = collect($errorKeys ?? [$errorKey ?? $name])->filter()->values();
    $messageBag = isset($errors) && method_exists($errors, 'getBag')
        ? $errors->getBag($errorBag)
        : ($errors ?? null);
    $error = $messageBag
        ? $validationKeys->map(static fn ($key) => $messageBag->first((string) $key))->first(
            static fn ($message) => filled($message)
        )
        : null;
    $hasHelp = (isset($help) && $help->hasActualContent()) || filled($hint);
    $hasStatus = isset($status) && $status->hasActualContent();
    $describedBy = collect([
        $hasHelp ? $fieldId.'-help' : null,
        $hasStatus ? $fieldId.'-status' : null,
        $error ? $fieldId.'-error' : null,
        $attributes->get('aria-describedby'),
    ])->filter()->unique()->implode(' ');
    $isInvalid = $error || $attributes->get('aria-invalid') === 'true';
    $errorMessageId = $error ? $fieldId.'-error' : $attributes->get('aria-errormessage');
@endphp

<x-form.field
    :id="$fieldId"
    :label="$label"
    :hint="$hint"
    :error="$error"
    :optional="$optional"
    :required="$required"
>
    @isset($help)
        <x-slot:help>{{ $help }}</x-slot:help>
    @endisset
    @isset($status)
        <x-slot:status>{{ $status }}</x-slot:status>
    @endisset
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if (! in_array($type, ['file', 'password'], true)) value="{{ old($name, $value) }}" @endif
        @if ($required) required @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($errorMessageId) aria-errormessage="{{ $errorMessageId }}" @endif
        @if ($isInvalid) aria-invalid="true" @endif
        {{ $attributes->except(['aria-describedby', 'aria-errormessage', 'aria-invalid'])->class(['input w-full', 'input-error' => $isInvalid]) }}
    >
</x-form.field>
