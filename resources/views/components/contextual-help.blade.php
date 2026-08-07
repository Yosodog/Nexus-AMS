@props([
    'open' => false,
    'owner',
    'title',
])

@php
    $titleText = trim((string) $title);
    $ownerText = trim((string) $owner);

    if ($titleText === '' || $ownerText === '') {
        throw new \InvalidArgumentException('Contextual help requires a title and content owner.');
    }

    if (! isset($why) || ! $why->hasActualContent() || ! isset($next) || ! $next->hasActualContent()) {
        throw new \InvalidArgumentException('Contextual help requires why and next-action guidance.');
    }
@endphp

<details {{ $attributes->class('group rounded-lg border border-info/30 bg-info/5') }} @if($open) open @endif>
    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold text-base-content focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        <x-icon name="o-question-mark-circle" class="size-5 shrink-0 text-info" aria-hidden="true" />
        <span class="grow">{{ $titleText }}</span>
        <x-icon name="o-chevron-down" class="size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden="true" />
    </summary>

    <div class="grid gap-4 border-t border-info/20 px-4 py-4 text-sm" role="note">
        <section>
            <h3 class="font-semibold">Why this is happening</h3>
            <div class="mt-1 text-base-content/75">{{ $why }}</div>
        </section>
        <section>
            <h3 class="font-semibold">What to do next</h3>
            <div class="mt-1 text-base-content/75">{{ $next }}</div>
        </section>
        @isset($timing)
            @if($timing->hasActualContent())
                <section>
                    <h3 class="font-semibold">When it should change</h3>
                    <div class="mt-1 text-base-content/75">{{ $timing }}</div>
                </section>
            @endif
        @endisset
        @isset($support)
            @if($support->hasActualContent())
                <section>
                    <h3 class="font-semibold">If you are still blocked</h3>
                    <div class="mt-1 text-base-content/75">{{ $support }}</div>
                </section>
            @endif
        @endisset
        <p class="text-xs nexus-text-muted">Content owner: {{ $ownerText }}</p>
    </div>
</details>
