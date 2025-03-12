@props([
    'type' => 'text',        // Default type is 'text'
    'name',                  // Name and id of the input
    'label' => '',           // Optional label
    'placeholder' => '',     // Optional placeholder text
    'value' => '',           // Optional default value
    'required' => false,     // Required attribute
])

<div class="form-control w-full mb-4">
    <!-- Label (optional) -->
    @if($label)
        <label for="{{ $name }}" class="label">
            <span class="label-text">{{ $label }}</span>
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
    <span class="text-sm text-red-600">{{ $message }}</span>
    @enderror
</div>
