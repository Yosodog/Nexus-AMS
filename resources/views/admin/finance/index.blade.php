@extends('layouts.admin')

@section('title', 'Finance Ledger')

@section('content')
    @php
        $formatCurrency = static fn (mixed $value): string => '$'.number_format(is_numeric($value) ? (float) $value : 0, 2);
        $entryCount = (int) $dailyTotals->sum('entry_count');
    @endphp

    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <p class="nexus-kicker">Economics · Reporting</p>
            <h1 class="nexus-page-title">Alliance finance ledger</h1>
            <p class="nexus-page-summary">
                Reconcile income and expenditure across taxes, grants, MMR, war aid, and other recorded alliance activity.
            </p>
        </div>

        <div class="nexus-page-header__actions">
            <a href="{{ route('admin.finance.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
                Reset filters
            </a>
            <a href="{{ $exportUrl }}" class="btn btn-outline btn-sm">
                <x-icon name="o-arrow-down-tray" class="size-4" aria-hidden="true" />
                Export CSV
            </a>
        </div>
    </header>

    <section class="nexus-panel" aria-labelledby="ledger-filter-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="ledger-filter-title" class="nexus-section-title">Report scope</h2>
                <p class="nexus-body-muted mt-1">Dates are inclusive. Select one or more categories to narrow the ledger.</p>
            </div>
            <span class="nexus-status nexus-status--neutral">
                {{ $from->toFormattedDateString() }} – {{ $to->toFormattedDateString() }}
            </span>
        </div>

        <form method="GET" class="nexus-panel__body grid gap-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-input label="From" type="date" id="from" name="from" :value="$from->toDateString()" />
                <x-input label="To" type="date" id="to" name="to" :value="$to->toDateString()" />

                <label class="grid content-start gap-2">
                    <span class="text-sm font-semibold text-base-content">Direction</span>
                    <select id="direction" name="direction" class="select w-full">
                        <option value="both" @selected($selectedDirection === 'both')>Income and expense</option>
                        <option value="income" @selected($selectedDirection === 'income')>Income only</option>
                        <option value="expense" @selected($selectedDirection === 'expense')>Expense only</option>
                    </select>
                </label>

                <fieldset class="grid content-start gap-2 md:col-span-2 xl:col-span-1">
                    <legend class="text-sm font-semibold text-base-content">Categories</legend>
                    <div class="grid max-h-40 gap-2 overflow-y-auto border border-base-300 bg-base-100 p-3 sm:grid-cols-2 xl:grid-cols-1">
                        @foreach ($categories as $key => $category)
                            <label class="flex min-h-8 cursor-pointer items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="categories[]"
                                    value="{{ $key }}"
                                    class="checkbox checkbox-primary checkbox-sm"
                                    @checked(in_array($key, $selectedCategories, true))
                                >
                                <span>{{ $category['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            <div class="flex justify-end border-t border-base-300 pt-4">
                <button type="submit" class="btn btn-primary">
                    <x-icon name="o-funnel" class="size-4" aria-hidden="true" />
                    Apply filters
                </button>
            </div>
        </form>
    </section>

    <dl class="nexus-metrics" aria-label="Finance summary">
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Total income</dt>
            <dd class="nexus-stat-value text-success">{{ $formatCurrency($totals['income']) }}</dd>
            <p class="nexus-stat-helper">Within the selected scope</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Total expenses</dt>
            <dd class="nexus-stat-value text-error">{{ $formatCurrency($totals['expense']) }}</dd>
            <p class="nexus-stat-helper">Within the selected scope</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Net position</dt>
            <dd class="nexus-stat-value {{ $totals['net'] >= 0 ? 'text-success' : 'text-error' }}">{{ $formatCurrency($totals['net']) }}</dd>
            <p class="nexus-stat-helper">Income less expenses</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Strongest day</dt>
            <dd class="nexus-stat-value">{{ $formatCurrency($bestDay['net'] ?? 0) }}</dd>
            <p class="nexus-stat-helper">{{ $bestDay['date'] ?? 'No activity in range' }}</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Weakest day</dt>
            <dd class="nexus-stat-value">{{ $formatCurrency($worstDay['net'] ?? 0) }}</dd>
            <p class="nexus-stat-helper">{{ $worstDay['date'] ?? 'No activity in range' }}</p>
        </div>
    </dl>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="nexus-panel" aria-labelledby="net-flow-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="net-flow-title" class="nexus-section-title">Daily net flow</h2>
                    <p class="nexus-body-muted mt-1">Income, expenditure, and resulting net movement by day.</p>
                </div>
            </div>
            <div id="financeNetChart" class="nexus-panel__body min-h-72" aria-live="polite"></div>
        </section>

        <section class="nexus-panel" aria-labelledby="category-flow-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="category-flow-title" class="nexus-section-title">Category mix</h2>
                    <p class="nexus-body-muted mt-1">Recorded value grouped by operational category and day.</p>
                </div>
            </div>
            <div id="financeCategoryChart" class="nexus-panel__body min-h-72" aria-live="polite"></div>
        </section>
    </div>

    <section class="nexus-panel" aria-labelledby="ledger-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="ledger-title" class="nexus-section-title">Ledger by day</h2>
                <p class="nexus-body-muted mt-1">Open a day to load its itemized entries.</p>
            </div>
            <span class="nexus-status nexus-status--neutral">
                {{ number_format($entryCount) }} {{ Illuminate\Support\Str::plural('entry', $entryCount) }} · {{ $ledgerDates->count() }} {{ Illuminate\Support\Str::plural('day', $ledgerDates->count()) }}
            </span>
        </div>

        <div class="divide-y divide-base-300" id="ledgerAccordion">
            @forelse ($ledgerDates as $date)
                @php
                    $summary = $dailyTotals[$date] ?? ['entry_count' => 0, 'income' => 0, 'expense' => 0, 'net' => 0];
                    $dayUrl = route('admin.finance.day', ['date' => $date] + request()->query());
                    $panelId = 'ledger-day-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $date);
                @endphp
                <article
                    x-data="{ open: false, loaded: false, loading: false, content: '', error: '' }"
                    @open="if (!loaded && !loading) {
                        loading = true;
                        error = '';
                        fetch('{{ $dayUrl }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(response => {
                                if (!response.ok) throw new Error('Unable to load this day.');
                                return response.text();
                            })
                            .then(html => { content = html; loaded = true; loading = false; })
                            .catch(() => { error = 'Unable to load entries for this day. Close and reopen the day to retry.'; loading = false; });
                    }"
                >
                    <button
                        type="button"
                        class="grid w-full gap-3 px-5 py-4 text-left transition-colors hover:bg-base-200/60 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                        @click="open = !open; if (open) $dispatch('open')"
                        :aria-expanded="open.toString()"
                        aria-controls="{{ $panelId }}"
                    >
                        <span>
                            <span class="block font-semibold text-base-content">{{ \Carbon\Carbon::parse($date)->toFormattedDateString() }}</span>
                            <span class="mt-1 block text-xs text-base-content/55">{{ number_format((int) $summary['entry_count']) }} recorded {{ \Illuminate\Support\Str::plural('entry', (int) $summary['entry_count']) }}</span>
                        </span>
                        <span class="grid grid-cols-2 gap-x-5 gap-y-1 text-sm sm:grid-cols-4 sm:text-right">
                            <span><span class="block text-xs text-base-content/50">Income</span><span class="font-semibold text-success">{{ $formatCurrency($summary['income']) }}</span></span>
                            <span><span class="block text-xs text-base-content/50">Expense</span><span class="font-semibold text-error">{{ $formatCurrency($summary['expense']) }}</span></span>
                            <span><span class="block text-xs text-base-content/50">Net</span><span class="font-semibold {{ $summary['net'] >= 0 ? 'text-success' : 'text-error' }}">{{ $formatCurrency($summary['net']) }}</span></span>
                            <span class="self-end text-primary" aria-hidden="true"><x-icon name="o-chevron-down" class="size-4 transition-transform" x-bind:class="open && 'rotate-180'" /></span>
                        </span>
                    </button>

                    <div id="{{ $panelId }}" x-show="open" x-cloak x-transition.opacity.duration.150ms class="border-t border-base-300 px-5 py-4">
                        <div x-show="loading" class="flex items-center gap-2 text-sm text-base-content/60" role="status">
                            <span class="loading loading-spinner loading-sm" aria-hidden="true"></span>
                            <span>Loading entries…</span>
                        </div>
                        <p x-show="error" x-text="error" class="py-2 text-sm text-error" role="alert"></p>
                        <div x-show="loaded" x-html="content"></div>
                    </div>
                </article>
            @empty
                <div class="nexus-empty-state">
                    <x-icon name="o-document-magnifying-glass" class="size-8 text-base-content/35" aria-hidden="true" />
                    <div>
                        <h2 class="text-lg font-semibold">No ledger activity in this scope</h2>
                        <p class="mt-1 text-sm text-base-content/60">Change the dates, direction, or category filters to review another period.</p>
                    </div>
                </div>
            @endforelse
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('codex:page-ready', () => {
            const netData = @json($netChart);
            const categoryDatasets = @json($categoryDatasets);
            const colorMap = {
                primary: 'var(--color-primary)',
                secondary: 'var(--color-secondary)',
                success: 'var(--color-success)',
                danger: 'var(--color-error)',
                error: 'var(--color-error)',
                warning: 'var(--color-warning)',
                info: 'var(--color-info)',
                neutral: 'var(--color-neutral)',
            };

            const createSvgNode = (name, attributes = {}) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', name);
                Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));

                return node;
            };

            const formatMoney = (value) => '$' + Number(value).toLocaleString(undefined, {
                maximumFractionDigits: 0,
            });

            const renderEmpty = (container, message) => {
                const empty = document.createElement('p');
                empty.className = 'grid min-h-56 place-items-center text-center text-sm text-base-content/60';
                empty.textContent = message;
                container.replaceChildren(empty);
            };

            const appendLegend = (container, series) => {
                const legend = document.createElement('div');
                legend.className = 'mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm text-base-content/60';

                series.forEach((item) => {
                    const entry = document.createElement('span');
                    entry.className = 'inline-flex items-center gap-1.5';
                    const marker = document.createElement('span');
                    marker.className = 'size-2.5 rounded-sm';
                    marker.style.background = item.color;
                    marker.setAttribute('aria-hidden', 'true');
                    entry.append(marker, document.createTextNode(item.label));
                    legend.appendChild(entry);
                });

                container.appendChild(legend);
            };

            const appendScale = (svg, { width, height, padding, max, range }) => {
                const innerHeight = height - padding.top - padding.bottom;

                [0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
                    const value = max - range * ratio;
                    const y = padding.top + innerHeight * ratio;
                    svg.appendChild(createSvgNode('line', {
                        x1: padding.left,
                        y1: y,
                        x2: width - padding.right,
                        y2: y,
                        stroke: 'currentColor',
                        'stroke-opacity': '0.13',
                        'stroke-width': '1',
                    }));
                    const label = createSvgNode('text', {
                        x: padding.left - 8,
                        y: y + 4,
                        'text-anchor': 'end',
                        'font-size': '11',
                        fill: 'currentColor',
                        'fill-opacity': '0.58',
                    });
                    label.textContent = formatMoney(value);
                    svg.appendChild(label);
                });
            };

            const appendDateLabels = (svg, labels, width, height, padding) => {
                const first = createSvgNode('text', {
                    x: padding.left,
                    y: height - 10,
                    'font-size': '11',
                    fill: 'currentColor',
                    'fill-opacity': '0.58',
                });
                first.textContent = labels[0];
                svg.appendChild(first);

                const last = createSvgNode('text', {
                    x: width - padding.right,
                    y: height - 10,
                    'font-size': '11',
                    'text-anchor': 'end',
                    fill: 'currentColor',
                    'fill-opacity': '0.58',
                });
                last.textContent = labels[labels.length - 1];
                svg.appendChild(last);
            };

            const renderNetChart = () => {
                const container = document.getElementById('financeNetChart');

                if (!container) return;
                if (!netData.labels.length) {
                    renderEmpty(container, 'No daily flow is available for the selected scope.');
                    return;
                }

                const width = 720;
                const height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 64 };
                const values = [...netData.income, ...netData.expense, ...netData.net];
                const min = Math.min(0, ...values);
                const max = Math.max(0, ...values);
                const range = Math.max(max - min, 1);
                const innerWidth = width - padding.left - padding.right;
                const innerHeight = height - padding.top - padding.bottom;
                const stepX = netData.labels.length > 1 ? innerWidth / (netData.labels.length - 1) : innerWidth / 2;
                const yForValue = (value) => padding.top + ((max - value) / range) * innerHeight;
                const xForIndex = (index) => padding.left + (netData.labels.length > 1 ? stepX * index : innerWidth / 2);
                const svg = createSvgNode('svg', {
                    viewBox: `0 0 ${width} ${height}`,
                    class: 'w-full text-base-content',
                    role: 'img',
                    'aria-label': 'Daily income, expense, and net flow',
                });
                const series = [
                    { label: 'Income', color: colorMap.success, data: netData.income },
                    { label: 'Expense', color: colorMap.error, data: netData.expense },
                    { label: 'Net', color: colorMap.primary, data: netData.net },
                ];

                appendScale(svg, { width, height, padding, max, range });
                svg.appendChild(createSvgNode('line', {
                    x1: padding.left,
                    y1: yForValue(0),
                    x2: width - padding.right,
                    y2: yForValue(0),
                    stroke: 'currentColor',
                    'stroke-opacity': '0.32',
                    'stroke-width': '1.5',
                }));

                series.forEach((item) => {
                    const points = item.data.map((value, index) => `${xForIndex(index)},${yForValue(value)}`).join(' ');
                    svg.appendChild(createSvgNode('polyline', {
                        points,
                        fill: 'none',
                        stroke: item.color,
                        'stroke-width': '3',
                        'stroke-linecap': 'round',
                        'stroke-linejoin': 'round',
                    }));
                });

                appendDateLabels(svg, netData.labels, width, height, padding);
                container.replaceChildren(svg);
                appendLegend(container, series);
            };

            const renderCategoryChart = () => {
                const container = document.getElementById('financeCategoryChart');

                if (!container) return;
                if (!netData.labels.length || !categoryDatasets.length) {
                    renderEmpty(container, 'No category activity is available for the selected scope.');
                    return;
                }

                const width = 720;
                const height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 64 };
                const totalsByDay = netData.labels.map((_, index) => categoryDatasets.reduce(
                    (sum, dataset) => sum + (Number(dataset.data[index]) || 0),
                    0,
                ));
                const max = Math.max(...totalsByDay, 1);
                const innerWidth = width - padding.left - padding.right;
                const innerHeight = height - padding.top - padding.bottom;
                const barWidth = Math.max(innerWidth / netData.labels.length - 4, 6);
                const stepX = innerWidth / netData.labels.length;
                const svg = createSvgNode('svg', {
                    viewBox: `0 0 ${width} ${height}`,
                    class: 'w-full text-base-content',
                    role: 'img',
                    'aria-label': 'Daily finance value grouped by category',
                });
                const series = categoryDatasets.map((dataset) => ({
                    ...dataset,
                    color: colorMap[dataset.color] ?? colorMap.primary,
                }));

                appendScale(svg, { width, height, padding, max, range: max });

                netData.labels.forEach((_, index) => {
                    let cumulative = 0;
                    const x = padding.left + index * stepX + 2;

                    series.forEach((dataset) => {
                        const value = Number(dataset.data[index]) || 0;
                        if (value <= 0) return;
                        const segmentHeight = (value / max) * innerHeight;
                        const y = height - padding.bottom - ((cumulative + value) / max) * innerHeight;
                        svg.appendChild(createSvgNode('rect', {
                            x,
                            y,
                            width: barWidth,
                            height: segmentHeight,
                            rx: '1',
                            fill: dataset.color,
                        }));
                        cumulative += value;
                    });
                });

                appendDateLabels(svg, netData.labels, width, height, padding);
                container.replaceChildren(svg);
                appendLegend(container, series);
            };

            renderNetChart();
            renderCategoryChart();
        });
    </script>
@endpush
