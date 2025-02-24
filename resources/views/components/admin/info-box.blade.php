@props([
    'icon' => 'bi bi-info-circle', // Default icon if not provided
    'bgColor' => 'text-bg-primary', // Default background color
    'title' => 'Info Box', // Default title
    'value' => 'N/A' // Default value
])

<div class="info-box">
    <span class="info-box-icon {{ $bgColor }} shadow-sm">
        <i class="{{ $icon }}"></i>
    </span>
    <div class="info-box-content">
        <span class="info-box-text">{{ $title }}</span>
        <span class="info-box-number">{{ $value }}</span>
    </div>
</div>