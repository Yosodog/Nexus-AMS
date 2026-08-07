@props([
    'label' => 'Loading content',
    'rows' => 4,
])

<div class="nexus-skeleton" role="status" aria-label="{{ $label }}" {{ $attributes }}>
    <span class="sr-only">{{ $label }}</span>
    @for ($row = 0; $row < $rows; $row++)
        <span class="nexus-skeleton__row" aria-hidden="true"></span>
    @endfor
</div>
