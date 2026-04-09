@props([
    'type' => 'info',
    'message' => '',
])

@php
    $cssClass = match($type) {
        'success' => 'alert-success',
        'error'   => 'alert-error',
        'warning' => 'alert-warning',
        default   => 'alert-info',
    };

    $icon = match($type) {
        'success' => 'o-check-circle',
        'error'   => 'o-x-circle',
        'warning' => 'o-exclamation-triangle',
        default   => 'o-information-circle',
    };
@endphp

@if($message || $errors->any())
    <x-alert :icon="$icon" class="{{ $cssClass }} my-1">
        @if($message)
            {{ $message }}
        @elseif($errors->any())
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </x-alert>
@endif
