@extends('layouts.admin')

@section('content')
    @php $formatCurrency = static fn (float $value): string => '$' . number_format($value, 2); @endphp

    <x-header title="Alliance Finance Ledger" separator>
        <x-slot:subtitle>Track income and expenses across taxes, grants, MMR, and war aid.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ $exportUrl }}">
                <x-button label="Export CSV" icon="o-arrow-down-tray" class="btn-outline btn-sm" />
            </a>
            <a href="{{ route('admin.finance.index') }}">
                <x-button label="Reset Filters" icon="o-arrow-path" class="btn-ghost btn-sm" />
            </a>
        </x-slot:actions>
    </x-header>

    {{-- Filters --}}
    <x-card class="mb-6">
        <form method="GET">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                <x-input label="From" type="date" id="from" name="from" :value="$from->toDateString()" />
                <x-input label="To" type="date" id="to" name="to" :value="$to->toDateString()" />
                <div>
                    <label class="label font-semibold text-sm">Direction</label>
                    <select id="direction" name="direction" class="select select-bordered w-full">
                        <option value="both" @selected($selectedDirection === 'both')>Income + Expense</option>
                        <option value="income" @selected($selectedDirection === 'income')>Income</option>
                        <option value="expense" @selected($selectedDirection === 'expense')>Expense</option>
                    </select>
                </div>
                <div>
                    <label class="label font-semibold text-sm">
                        Categories
                        <span class="text-xs text-base-content/50">Hold Ctrl/Cmd to multi-select</span>
                    </label>
                    <select id="categories" name="categories[]" class="select select-bordered w-full" multiple size="4">
                        @foreach ($categories as $key => $category)
                            <option value="{{ $key }}" @selected(in_array($key, $selectedCategories, true))>
                                {{ $category['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex justify-end">
                <x-button label="Apply Filters" type="submit" icon="o-funnel" class="btn-primary" />
            </div>
        </form>
    </x-card>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        @php $formatCurrencyStat = static fn (float $value): string => '$' . number_format($value, 2); @endphp
        @foreach ($infoCards as $card)
            @php
                $colorMap = ['primary' => 'text-primary', 'secondary' => 'text-neutral', 'success' => 'text-success', 'danger' => 'text-error', 'warning' => 'text-warning', 'info' => 'text-info', 'dark' => 'text-base-content'];
                $iconMapFinance = ['o-building-library' => 'o-building-library', 'o-banknotes' => 'o-banknotes', 'o-arrow-trending-up' => 'o-arrow-trending-up', 'o-arrow-down-circle' => 'o-arrow-down-circle', 'o-arrow-up-circle' => 'o-arrow-up-circle', 'o-calculator' => 'o-calculator', 'o-wallet' => 'o-wallet', 'o-currency-dollar' => 'o-currency-dollar'];
            @endphp
            <x-stat :title="$card['title']"
                    :value="$formatCurrencyStat($card['value'])"
                    icon="{{ $iconMapFinance[$card['icon']] ?? 'o-chart-bar' }}"
                    color="{{ $colorMap[$card['variant']] ?? 'text-primary' }}"
                    :description="$card['helper'] ?? null" />
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <x-card>
            <x-slot:title>
                Daily Net Flow
                <div class="text-sm font-normal text-base-content/50">
                    {{ $from->toFormattedDateString() }} – {{ $to->toFormattedDateString() }}
                </div>
            </x-slot:title>
            <div id="financeNetChart" class="w-full min-h-64"></div>
        </x-card>
        <x-card>
            <x-slot:title>
                Category Breakdown
                <div class="text-sm font-normal text-base-content/50">Stacked by day</div>
            </x-slot:title>
            <div id="financeCategoryChart" class="w-full min-h-64"></div>
        </x-card>
    </div>

    {{-- Ledger Accordion --}}
    <x-card>
        <x-slot:title>Ledger</x-slot:title>
        <x-slot:menu>
            <span class="text-sm text-base-content/50">
                {{ number_format((int) $dailyTotals->sum('entry_count')) }} entries across {{ $ledgerDates->count() }} days
            </span>
        </x-slot:menu>

        <div class="space-y-2" id="ledgerAccordion">
            @forelse ($ledgerDates as $date)
                @php
                    $summary = $dailyTotals[$date] ?? ['entry_count' => 0, 'income' => 0, 'expense' => 0, 'net' => 0];
                    $dayUrl = route('admin.finance.day', ['date' => $date] + request()->query());
                @endphp
                <div class="border border-base-300 rounded-box"
                     x-data="{ open: false, loaded: false, loading: false, content: '' }"
                     @open.once="if (!loaded && !loading) {
                         loading = true;
                         fetch('{{ $dayUrl }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                             .then(r => r.text())
                             .then(html => { content = html; loaded = true; loading = false; })
                             .catch(e => { content = '<p class=\'text-error py-2\'>' + e.message + '</p>'; loading = false; });
                     }">
                    <button type="button"
                            class="w-full flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 p-4 text-left hover:bg-base-200 transition-colors rounded-box"
                            @click="open = !open; if (open) $dispatch('open')">
                        <span class="font-semibold">{{ \Carbon\Carbon::parse($date)->toFormattedDateString() }}</span>
                        <div class="flex flex-wrap gap-2">
                            <x-badge  value="{{ number_format((int) $summary['entry_count']) }} entries" class="badge-ghost badge-sm" />
                            <x-badge  value="Income: {{ $formatCurrency($summary['income']) }}" class="badge-success badge-sm" />
                            <x-badge  value="Expense: {{ $formatCurrency($summary['expense']) }}" class="badge-error badge-sm" />
                            <x-badge :value="'Net: ' . $formatCurrency($summary['net'])"
                                     :class="$summary['net'] >= 0 ? 'badge-primary badge-sm' : 'badge-warning badge-sm'" />
                        </div>
                    </button>
                    <div x-show="open" x-cloak class="border-t border-base-300 p-4">
                        <div x-show="loading" class="flex items-center gap-2 text-base-content/50">
                            <span class="loading loading-spinner loading-sm"></span>
                            <span>Loading entries...</span>
                        </div>
                        <div x-show="loaded" x-html="content"></div>
                    </div>
                </div>
            @empty
                <p class="text-base-content/50">No ledger data for this range.</p>
            @endforelse
        </div>
    </x-card>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const netData = @json($netChart);
            const categoryDatasets = @json($categoryDatasets);
            const colorMap = {
                primary: '#570df8',
                secondary: '#6c757d',
                success: '#36d399',
                danger: '#f87272',
                warning: '#fbbd23',
                info: '#3abff8',
                light: '#f8f9fa',
                dark: '#212529',
            };

            const createSvgNode = (name, attributes = {}) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', name);
                Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
                return node;
            };

            const formatMoney = (value) => '$' + Number(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });

            const renderNetChart = () => {
                const container = document.getElementById('financeNetChart');
                if (!container || !netData.labels.length) return;
                const width = 720, height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 56 };
                const values = [...netData.income, ...netData.expense, ...netData.net];
                const min = Math.min(0, ...values), max = Math.max(0, ...values);
                const range = Math.max(max - min, 1);
                const innerWidth = width - padding.left - padding.right;
                const innerHeight = height - padding.top - padding.bottom;
                const stepX = netData.labels.length > 1 ? innerWidth / (netData.labels.length - 1) : innerWidth / 2;
                const yForValue = (value) => padding.top + ((max - value) / range) * innerHeight;
                const xForIndex = (index) => padding.left + (netData.labels.length > 1 ? stepX * index : innerWidth / 2);
                const svg = createSvgNode('svg', { viewBox: `0 0 ${width} ${height}`, class: 'w-full', role: 'img', 'aria-label': 'Daily net flow chart' });
                [0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
                    const value = max - range * ratio, y = padding.top + innerHeight * ratio;
                    svg.appendChild(createSvgNode('line', { x1: padding.left, y1: y, x2: width - padding.right, y2: y, stroke: '#dee2e6', 'stroke-width': '1' }));
                    const label = createSvgNode('text', { x: padding.left - 8, y: y + 4, 'text-anchor': 'end', 'font-size': '11', fill: '#6c757d' });
                    label.textContent = formatMoney(value);
                    svg.appendChild(label);
                });
                const zeroY = yForValue(0);
                svg.appendChild(createSvgNode('line', { x1: padding.left, y1: zeroY, x2: width - padding.right, y2: zeroY, stroke: '#adb5bd', 'stroke-width': '1.5' }));
                [{ label: 'Income', color: colorMap.success, data: netData.income }, { label: 'Expense', color: colorMap.danger, data: netData.expense }, { label: 'Net', color: colorMap.primary, data: netData.net }].forEach((series) => {
                    const points = series.data.map((value, index) => `${xForIndex(index)},${yForValue(value)}`).join(' ');
                    svg.appendChild(createSvgNode('polyline', { points, fill: 'none', stroke: series.color, 'stroke-width': '3', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
                });
                const firstLabel = createSvgNode('text', { x: padding.left, y: height - 10, 'font-size': '11', fill: '#6c757d' });
                firstLabel.textContent = netData.labels[0];
                svg.appendChild(firstLabel);
                const lastLabel = createSvgNode('text', { x: width - padding.right, y: height - 10, 'font-size': '11', 'text-anchor': 'end', fill: '#6c757d' });
                lastLabel.textContent = netData.labels[netData.labels.length - 1];
                svg.appendChild(lastLabel);
                const legend = document.createElement('div');
                legend.className = 'flex flex-wrap gap-3 text-sm text-base-content/50 mt-3';
                legend.innerHTML = `<span><span class="inline-block rounded-full mr-1" style="width:10px;height:10px;background:${colorMap.success};"></span>Income</span><span><span class="inline-block rounded-full mr-1" style="width:10px;height:10px;background:${colorMap.danger};"></span>Expense</span><span><span class="inline-block rounded-full mr-1" style="width:10px;height:10px;background:${colorMap.primary};"></span>Net</span>`;
                container.innerHTML = '';
                container.appendChild(svg);
                container.appendChild(legend);
            };

            const renderCategoryChart = () => {
                const container = document.getElementById('financeCategoryChart');
                if (!container || !netData.labels.length || !categoryDatasets.length) return;
                const width = 720, height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 56 };
                const totalsByDay = netData.labels.map((_, index) => categoryDatasets.reduce((sum, dataset) => sum + (Number(dataset.data[index]) || 0), 0));
                const max = Math.max(...totalsByDay, 1);
                const innerWidth = width - padding.left - padding.right;
                const innerHeight = height - padding.top - padding.bottom;
                const barWidth = Math.max(innerWidth / netData.labels.length - 4, 6);
                const stepX = innerWidth / netData.labels.length;
                const svg = createSvgNode('svg', { viewBox: `0 0 ${width} ${height}`, class: 'w-full', role: 'img', 'aria-label': 'Category breakdown chart' });
                [0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
                    const value = max - max * ratio, y = padding.top + innerHeight * ratio;
                    svg.appendChild(createSvgNode('line', { x1: padding.left, y1: y, x2: width - padding.right, y2: y, stroke: '#dee2e6', 'stroke-width': '1' }));
                    const label = createSvgNode('text', { x: padding.left - 8, y: y + 4, 'text-anchor': 'end', 'font-size': '11', fill: '#6c757d' });
                    label.textContent = formatMoney(value);
                    svg.appendChild(label);
                });
                netData.labels.forEach((label, index) => {
                    let cumulative = 0;
                    const x = padding.left + index * stepX + 2;
                    categoryDatasets.forEach((dataset) => {
                        const value = Number(dataset.data[index]) || 0;
                        if (value <= 0) return;
                        const segmentHeight = (value / max) * innerHeight;
                        const y = height - padding.bottom - ((cumulative + value) / max) * innerHeight;
                        svg.appendChild(createSvgNode('rect', { x, y, width: barWidth, height: segmentHeight, rx: '1', fill: colorMap[dataset.color] ?? colorMap.primary }));
                        cumulative += value;
                    });
                });
                const firstLabel = createSvgNode('text', { x: padding.left, y: height - 10, 'font-size': '11', fill: '#6c757d' });
                firstLabel.textContent = netData.labels[0];
                svg.appendChild(firstLabel);
                const lastLabel = createSvgNode('text', { x: width - padding.right, y: height - 10, 'font-size': '11', 'text-anchor': 'end', fill: '#6c757d' });
                lastLabel.textContent = netData.labels[netData.labels.length - 1];
                svg.appendChild(lastLabel);
                const legend = document.createElement('div');
                legend.className = 'flex flex-wrap gap-3 text-sm text-base-content/50 mt-3';
                legend.innerHTML = categoryDatasets.map((dataset) => `<span><span class="inline-block rounded-full mr-1" style="width:10px;height:10px;background:${colorMap[dataset.color] ?? colorMap.primary};"></span>${dataset.label}</span>`).join('');
                container.innerHTML = '';
                container.appendChild(svg);
                container.appendChild(legend);
            };

            renderNetChart();
            renderCategoryChart();
        });
    </script>
@endpush
