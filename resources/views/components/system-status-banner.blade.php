@props([
    'down' => false,
    'checkedAt' => null,
])

@if($down)
    <div class="system-banner" role="status">
        <x-icon name="o-exclamation-triangle" class="size-4 shrink-0" aria-hidden="true" />
        <span>
            Politics & War is currently unavailable. Actions that contact the game may be delayed or disabled.
            @if($checkedAt)
                Last checked {{ \Carbon\Carbon::parse($checkedAt)->diffForHumans() }}.
            @endif
        </span>
    </div>
@endif
