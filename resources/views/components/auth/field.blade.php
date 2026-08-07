@props([
    'errorBag' => 'default',
    'errorKey' => null,
    'errorKeys' => null,
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'optional' => false,
    'required' => null,
])

@php
    $fieldId = $id ?? (Illuminate\Support\Str::slug($name) ?: 'auth-field').'-'.substr(hash('sha256', $name.'|'.$label), 0, 8);
    $validationKeys = collect($errorKeys ?? [$errorKey ?? $name])->filter()->values();
    $messageBag = isset($errors) && method_exists($errors, 'getBag')
        ? $errors->getBag($errorBag)
        : ($errors ?? null);
    $error = $messageBag
        ? $validationKeys->map(static fn ($key) => $messageBag->first((string) $key))->first(
            static fn ($message) => filled($message)
        )
        : null;
    $isRequired = $required ?? ! $optional;
@endphp

<x-form.field
    :id="$fieldId"
    :label="$label"
    :hint="$hint"
    :error="$error"
    :optional="$optional"
    :required="$isRequired"
    label-class="text-sm font-semibold text-base-content"
    help-class="text-xs leading-5 text-base-content/70"
>
    @isset($help)
        <x-slot:help>{{ $help }}</x-slot:help>
    @endisset
    @isset($status)
        <x-slot:status>{{ $status }}</x-slot:status>
    @endisset
    {{ $slot }}
</x-form.field>
