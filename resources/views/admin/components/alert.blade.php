@props([
    'type' => 'info', // Default alert type is 'info'
    'message' => '',  // The message to display in the alert
])

@php
    // Define Bootstrap alert classes based on alert type
    $alertClasses = match($type) {
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
        default => 'alert-secondary',
    };

    $icon = match($type) {
        'success' => '<i class="fas fa-check-circle me-2"></i>',
        'error' => '<i class="fas fa-times-circle me-2"></i>',
        'warning' => '<i class="fas fa-exclamation-triangle me-2"></i>',
        'info' => '<i class="fas fa-info-circle me-2"></i>',
        default => '<i class="fas fa-info-circle me-2"></i>',
    };
@endphp

@if($message || $errors->any())
    <div class="alert {{ $alertClasses }} alert-dismissible fade show my-1" role="alert">
        {!! $icon !!}
        @if($message)
            <span>{{ $message }}</span>
        @elseif($errors->any())
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
