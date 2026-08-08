@props(['result'])

@php
    $formatMoney = static fn (float|int $value): string => ($value < 0 ? '-$' : '$').number_format(abs((float) $value), 2);
    $scalarMetrics = collect($result['metrics'] ?? [])->filter(
        static fn ($value) => $value === null || is_scalar($value)
    );
@endphp

<section {{ $attributes->class(['mt-6 rounded-lg border border-primary/25 bg-primary/5 p-4 sm:p-5']) }} aria-live="polite" aria-atomic="true">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="nexus-kicker">Calculated result</p>
            <h3 class="nexus-section-title">Cost and value breakdown</h3>
        </div>
        <span class="nexus-status nexus-status--success">Ready</span>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        @foreach($result['breakdowns'] as $key => $breakdown)
            <article class="rounded-lg border border-base-300 bg-base-100 p-4">
                <h4 class="font-semibold text-base-content">{{ str($key)->headline() }}</h4>
                <dl class="mt-3 grid gap-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="nexus-text-muted">Money</dt>
                        <dd class="font-semibold tabular-nums">{{ $formatMoney($breakdown['money']) }}</dd>
                    </div>
                    @foreach($breakdown['resources'] as $resource => $amount)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="nexus-text-muted">{{ str($resource)->headline() }}</dt>
                            <dd class="font-semibold tabular-nums">{{ number_format($amount, 2) }}</dd>
                        </div>
                    @endforeach
                    <div class="mt-1 flex items-center justify-between gap-4 border-t border-base-300 pt-2">
                        <dt>
                            <span class="font-semibold">Market value</span>
                            <span class="block text-xs nexus-text-muted">{{ str($breakdown['valuation_basis'])->headline() }}</span>
                        </dt>
                        <dd class="font-bold tabular-nums">
                            {{ $breakdown['market_value'] === null ? 'Unavailable' : $formatMoney($breakdown['market_value']) }}
                        </dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </div>

    @if($scalarMetrics->isNotEmpty())
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($scalarMetrics as $key => $value)
                <div class="rounded-lg border border-base-300 bg-base-100 p-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">{{ str($key)->headline() }}</dt>
                    <dd class="mt-1 font-semibold tabular-nums">
                        @if(is_bool($value))
                            {{ $value ? 'Yes' : 'No' }}
                        @elseif($value === null)
                            Unavailable
                        @elseif(is_float($value))
                            {{ number_format($value, 2) }}
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <div>
            <h4 class="font-semibold">Modifiers</h4>
            <ul class="mt-2 grid gap-2 text-sm">
                @foreach($result['modifiers'] as $modifier)
                    <li class="flex items-start justify-between gap-4 rounded-md border border-base-300 bg-base-100 p-3">
                        <span>
                            <span class="font-medium">{{ $modifier['label'] }}</span>
                            @if(filled($modifier['note'] ?? null))
                                <span class="mt-0.5 block text-xs nexus-text-muted">{{ $modifier['note'] }}</span>
                            @endif
                        </span>
                        <span class="text-right">
                            <span class="nexus-status {{ $modifier['applied'] ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                {{ $modifier['applied'] ? 'Applied' : 'Not applied' }}
                            </span>
                            @if(($modifier['rate'] ?? null) !== null)
                                <span class="mt-1 block text-xs tabular-nums nexus-text-muted">{{ number_format($modifier['rate'] * 100, 2) }}%</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <h4 class="font-semibold">Assumptions</h4>
            <ul class="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-base-content/75">
                @foreach($result['assumptions'] as $assumption)
                    <li>{{ $assumption }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs nexus-text-muted">{{ $result['rounding'] }}</p>
        </div>
    </div>
</section>
