@props([
    'description',
    'number',
    'state' => 'upcoming',
    'title',
])

<li class="flex items-start gap-3" @if($state === 'current') aria-current="step" @endif>
    <span @class([
        'inline-grid size-7 shrink-0 place-items-center rounded-full border text-xs font-bold',
        'border-primary bg-primary text-primary-content' => $state === 'current',
        'border-success bg-success text-success-content' => $state === 'complete',
        'border-neutral-content/30 text-neutral-content/75' => ! in_array($state, ['current', 'complete'], true),
    ]) aria-hidden="true">
        @if($state === 'complete')
            <x-icon name="o-check" class="size-4" />
        @else
            {{ $number }}
        @endif
    </span>

    <span class="min-w-0">
        <span class="block text-sm font-semibold text-neutral-content">
            @if($state === 'complete')
                <span class="sr-only">Complete: </span>
            @endif
            {{ $title }}
        </span>
        <span class="sr-only text-xs leading-5 text-neutral-content/75 sm:not-sr-only sm:mt-1 sm:block">{{ $description }}</span>
    </span>
</li>
