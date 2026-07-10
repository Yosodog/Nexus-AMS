@props([
    'badge' => null,
    'description' => null,
    'headingId' => 'auth-page-title',
    'title',
])

<section {{ $attributes->class(['mx-auto w-full max-w-5xl']) }} aria-labelledby="{{ $headingId }}">
    <div class="overflow-hidden rounded-xl border border-base-300 bg-base-100">
        <header class="border-b border-base-300 px-6 py-7 sm:p-8">
            @if($badge)
                <p class="mb-3 inline-flex rounded-full border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                    {{ $badge }}
                </p>
            @endif

            <h1 id="{{ $headingId }}" class="font-display text-3xl font-bold tracking-[-0.025em] text-base-content sm:text-4xl">
                {{ $title }}
            </h1>

            @if($description)
                <p class="mt-3 max-w-2xl text-pretty text-sm leading-6 text-base-content/70 sm:text-base">
                    {{ $description }}
                </p>
            @endif
        </header>

        <div @class([
            'grid',
            'lg:grid-cols-[minmax(16rem,0.78fr)_minmax(0,1.22fr)]' => isset($context),
        ])>
            @isset($context)
                <aside class="border-b border-neutral-content/15 bg-neutral px-6 py-7 text-neutral-content sm:p-8 lg:border-b-0 lg:border-r">
                    {{ $context }}
                </aside>
            @endisset

            <div class="min-w-0">
                <div class="px-6 py-7 sm:p-8">
                    {{ $slot }}
                </div>

                @isset($footer)
                    <footer class="border-t border-base-300 bg-base-200/45 px-6 py-4 text-sm text-base-content/70 sm:px-8">
                        {{ $footer }}
                    </footer>
                @endisset
            </div>
        </div>
    </div>
</section>
