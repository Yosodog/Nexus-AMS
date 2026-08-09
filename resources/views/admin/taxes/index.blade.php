@extends('layouts.admin')

@section('content')
    @php
        $period = $dashboard['period'];
        $trend = $dashboard['trend'];
        $freshness = $dashboard['freshness'];

        $freshnessPresentation = match ($freshness['status']) {
            'fresh' => [
                'alert' => 'alert-success',
                'icon' => 'o-check-circle',
                'title' => 'Tax data is current',
                'detail' => 'Every configured tax feed has synced within the expected window.',
            ],
            'stale' => [
                'alert' => 'alert-warning',
                'icon' => 'o-clock',
                'title' => 'Tax data is stale',
                'detail' => 'At least one configured feed has not completed within two hours.',
            ],
            'failed' => [
                'alert' => 'alert-error',
                'icon' => 'o-exclamation-triangle',
                'title' => 'A tax sync needs attention',
                'detail' => 'The latest attempt failed for at least one configured feed.',
            ],
            default => [
                'alert' => 'alert-warning',
                'icon' => 'o-question-mark-circle',
                'title' => 'Tax freshness is unavailable',
                'detail' => 'A configured feed has not completed a successful sync yet.',
            ],
        };

        $trendPresentation = match ($trend['direction']) {
            'up' => [
                'icon' => 'o-arrow-trending-up',
                'label' => 'Increase',
                'class' => 'text-success',
            ],
            'down' => [
                'icon' => 'o-arrow-trending-down',
                'label' => 'Decrease',
                'class' => 'text-error',
            ],
            'new' => [
                'icon' => 'o-sparkles',
                'label' => 'New activity',
                'class' => 'text-info',
            ],
            default => [
                'icon' => 'o-minus',
                'label' => 'No change',
                'class' => 'nexus-text-muted',
            ],
        };
    @endphp

    <x-header title="Taxes" separator use-h1>
        <x-slot:subtitle>
            Decision summary for {{ $period['starts_at']->format('M j') }}–{{ $period['ends_at']->format('M j, Y') }}.
            Detailed records live in the finance ledger.
        </x-slot:subtitle>

        @if ($ledgerUrl)
            <x-slot:actions>
                <a
                    href="{{ $ledgerUrl }}"
                    class="btn btn-primary btn-sm"
                    aria-label="View tax transactions for the current 30-day period in the finance ledger"
                >
                    <x-icon name="o-book-open" class="size-4" />
                    View tax transactions
                </a>
            </x-slot:actions>
        @endif
    </x-header>

    <div class="alert {{ $freshnessPresentation['alert'] }} mb-6 items-start" role="status">
        <x-icon :name="$freshnessPresentation['icon']" class="mt-0.5 size-5 shrink-0" />
        <div>
            <p class="font-semibold">{{ $freshnessPresentation['title'] }}</p>
            <p class="text-sm">{{ $freshnessPresentation['detail'] }}</p>
            <p class="mt-1 text-sm">
                @if ($freshness['oldest_successful_at'])
                    Oldest feed refresh:
                    <time datetime="{{ $freshness['oldest_successful_at']->toAtomString() }}">
                        {{ $freshness['oldest_successful_at']->format('M j, Y g:i A T') }}
                    </time>.
                @else
                    No successful refresh time is available.
                @endif
                Tax imports are expected hourly.
            </p>
        </div>
    </div>

    <section aria-labelledby="current-period-heading" class="mb-6">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 id="current-period-heading" class="text-lg font-semibold">Current 30-day period</h2>
                <p class="text-sm nexus-text-muted">Calendar-day totals, including today.</p>
            </div>
            @if ($period['latest_recorded_at'])
                <p class="text-sm nexus-text-muted">
                    Latest included tax:
                    <time datetime="{{ $period['latest_recorded_at']->toAtomString() }}">
                        {{ $period['latest_recorded_at']->format('M j, Y g:i A T') }}
                    </time>
                </p>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat
                title="Money collected"
                :value="'$' . number_format($period['total_money'], 2)"
                icon="o-banknotes"
                color="text-success"
                :description="$period['starts_at']->format('M j') . ' through ' . $period['ends_at']->format('M j')"
            />
            <x-stat
                title="Tax records"
                :value="number_format($period['record_count'])"
                icon="o-receipt-percent"
                color="text-primary"
                description="Individual records included"
            />
            <x-stat
                title="Daily average"
                :value="'$' . number_format($period['average_daily_money'], 2)"
                icon="o-calendar-days"
                color="text-info"
                description="Average across 30 calendar days"
            />
        </div>

        @if ($period['record_count'] === 0)
            <div class="mt-4 rounded-lg border border-base-300 bg-base-200/50 px-4 py-3 text-sm" role="status">
                <p class="font-medium">No tax records were recorded in this period.</p>
                <p class="mt-1 nexus-text-muted">
                    Check the freshness state above to distinguish a current zero-activity period from missing sync data.
                </p>
            </div>
        @endif
    </section>

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <section aria-labelledby="trend-heading" class="rounded-lg border border-base-300 bg-base-100 p-5">
            <h2 id="trend-heading" class="text-lg font-semibold">Period comparison</h2>
            <p class="mt-1 text-sm nexus-text-muted">
                Compared with {{ $trend['previous_starts_at']->format('M j') }}–{{ $trend['previous_ends_at']->format('M j, Y') }}.
            </p>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm nexus-text-muted">Previous money collected</p>
                    <p class="text-2xl font-semibold tabular-nums">${{ number_format($trend['previous_money'], 2) }}</p>
                </div>
                <div class="text-right">
                    <p class="inline-flex items-center justify-end gap-1.5 font-semibold {{ $trendPresentation['class'] }}">
                        <x-icon :name="$trendPresentation['icon']" class="size-5" />
                        {{ $trendPresentation['label'] }}
                    </p>
                    <p class="mt-1 text-sm tabular-nums">
                        @if ($trend['percent_change'] === null)
                            No prior-period baseline
                        @else
                            {{ number_format(abs($trend['percent_change']), 1) }}%
                            ({{ $trend['delta_money'] >= 0 ? '+' : '−' }}${{ number_format(abs($trend['delta_money']), 2) }})
                        @endif
                    </p>
                </div>
            </div>
        </section>

        <section aria-labelledby="exceptions-heading" class="rounded-lg border border-base-300 bg-base-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="exceptions-heading" class="text-lg font-semibold">Collection exceptions</h2>
                <span class="badge {{ $freshness['exceptions'] === [] ? 'badge-success' : 'badge-warning' }} gap-1">
                    <x-icon
                        :name="$freshness['exceptions'] === [] ? 'o-check' : 'o-exclamation-triangle'"
                        class="size-3.5"
                    />
                    {{ count($freshness['exceptions']) }}
                </span>
            </div>

            @if ($freshness['exceptions'] === [])
                <p class="mt-4 text-sm">No collection exceptions are active.</p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($freshness['exceptions'] as $exception)
                        <li class="flex gap-3 text-sm">
                            <x-icon name="o-exclamation-circle" class="mt-0.5 size-4 shrink-0 text-warning" />
                            <div>
                                <p class="font-medium">
                                    {{ $exception['alliance_id'] ? 'Alliance #' . $exception['alliance_id'] : 'Configuration' }}
                                </p>
                                <p class="nexus-text-muted">{{ $exception['message'] }}</p>
                                @if ($exception['occurred_at'])
                                    <time
                                        datetime="{{ $exception['occurred_at']->toAtomString() }}"
                                        class="text-xs nexus-text-muted"
                                    >
                                        {{ $exception['occurred_at']->format('M j, Y g:i A T') }}
                                    </time>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if ($period['record_count'] > 0)
        <section aria-labelledby="resources-heading" class="mb-6">
            <div class="mb-3">
                <h2 id="resources-heading" class="text-lg font-semibold">Current resource intake</h2>
                <p class="text-sm nexus-text-muted">One concise total per resource; daily transaction detail is available in the ledger.</p>
            </div>

            <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-base-300 bg-base-300 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($period['resource_totals'] as $resource => $total)
                    <div class="flex items-baseline justify-between gap-4 bg-base-100 px-4 py-3">
                        <dt class="text-sm font-medium">{{ ucfirst($resource) }}</dt>
                        <dd class="font-mono text-sm tabular-nums">{{ number_format($total, 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($dashboard['daily_resource_totals'] !== [])
        <section aria-labelledby="resource-trends-heading" class="mb-8 grid gap-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="resource-trends-heading" class="text-lg font-semibold">Resource trends</h2>
                    <p class="text-sm nexus-text-muted">Daily tax intake across the same 30-day period as the summary above.</p>
                </div>
                <span class="badge badge-outline">30 calendar days</span>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($dashboard['daily_resource_totals'] as $resource => $dailyTotals)
                    <article class="rounded-lg border border-base-300 bg-base-100 p-4">
                        <div>
                            <h3 class="font-semibold">{{ $resource === 'money' ? 'Money' : ucfirst($resource) }} collected</h3>
                            <p class="text-sm nexus-text-muted">Daily total received through alliance taxes.</p>
                        </div>
                        <div class="mt-4 h-72 min-w-0">
                            <canvas
                                id="tax-chart-{{ $resource }}"
                                class="h-full w-full"
                                aria-label="{{ $resource === 'money' ? 'Money' : ucfirst($resource) }} collected by day"
                            ></canvas>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="daily-tax-values-heading" class="mb-6 grid gap-4">
            <div>
                <h2 id="daily-tax-values-heading" class="text-lg font-semibold">Daily tax values</h2>
                <p class="text-sm nexus-text-muted">Exact daily totals behind each chart, including zero-collection days.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($dashboard['daily_resource_totals'] as $resource => $dailyTotals)
                    <article class="overflow-hidden rounded-lg border border-base-300 bg-base-100">
                        <div class="border-b border-base-300 px-4 py-3">
                            <h3 class="font-semibold">{{ $resource === 'money' ? 'Money' : ucfirst($resource) }}</h3>
                            <p class="text-xs nexus-text-muted">30-day daily values</p>
                        </div>
                        <div class="max-h-72 overflow-auto">
                            <table class="table table-sm table-zebra" data-sortable="false">
                                <caption class="sr-only">
                                    Daily {{ $resource === 'money' ? 'money' : $resource }} tax values
                                </caption>
                                <thead class="sticky top-0 z-10 bg-base-100">
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col" class="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dailyTotals as $dailyTotal)
                                        <tr>
                                            <td>
                                                <time datetime="{{ $dailyTotal['day'] }}">
                                                    {{ Illuminate\Support\Carbon::parse($dailyTotal['day'])->format('M j, Y') }}
                                                </time>
                                            </td>
                                            <td class="text-right font-mono tabular-nums">
                                                {{ $resource === 'money' ? '$' : '' }}{{ number_format($dailyTotal['total'], 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @unless ($ledgerUrl)
        <div class="rounded-lg border border-base-300 bg-base-200/50 px-4 py-3 text-sm" role="note">
            <p class="font-medium">Need transaction-level detail?</p>
            <p class="mt-1 nexus-text-muted">
                Finance ledger access is separate from tax-summary access. Ask a finance staff member for the filtered transaction report.
            </p>
        </div>
    @endunless
@endsection

@push('scripts')
    <x-chart-js />
    <script>
        (() => {
            const resourceSeries = @json($dashboard['daily_resource_totals']);
            const seriesColors = ['primary', 'secondary', 'success', 'info', 'warning', 'error'];

            Object.entries(resourceSeries).forEach(([resource, dailyTotals], index) => {
                const canvas = document.getElementById(`tax-chart-${resource}`);

                if (!canvas) {
                    return;
                }

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: dailyTotals.map((entry) => entry.day),
                        datasets: [{
                            label: resource === 'money' ? 'Money' : resource.charAt(0).toUpperCase() + resource.slice(1),
                            nexusColor: seriesColors[index % seriesColors.length],
                            data: dailyTotals.map((entry) => Number(entry.total)),
                            fill: false,
                            tension: 0.3,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 4,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: (value) => resource === 'money'
                                        ? `$${Number(value).toLocaleString()}`
                                        : Number(value).toLocaleString(),
                                },
                            },
                        },
                    },
                });
            });
        })();
    </script>
@endpush
