@props([
    'type' => 'text',        // Default type is 'text'
    'name',                  // Name and id of the input
    'label' => '',           // Optional label
    'placeholder' => '',     // Optional placeholder text
    'value' => '',           // Optional default value
    'required' => false,     // Required attribute
])

<div class="mb-4 w-full space-y-2">
    <!-- Label (optional) -->
    @if($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-base-content">
            <span>{{ $label }}</span>
        </label>
    @endif

    <!-- Input field -->
    <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $name }}"
            value="{{ old($name, $value) }}"
            placeholder="{{ $placeholder }}"
            {{ $required ? 'required' : '' }}
            {{ $attributes->merge(['class' => 'input input-bordered w-full']) }}
    >

    <!-- Error handling -->
    @error($name)
    <span class="text-sm text-error">{{ $message }}</span>
    @enderror
</div>
