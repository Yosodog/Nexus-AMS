@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h3 class="mb-1">Alliance Finance Ledger</h3>
                <p class="text-secondary mb-0">Track income and expenses across taxes, grants, MMR, and war aid.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ $exportUrl }}" class="btn btn-outline-primary">
                    <i class="bi bi-download me-1"></i> Export CSV
                </a>
                <a href="{{ route('admin.finance.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-clockwise me-1"></i> Reset Filters
                </a>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="from" class="form-label">From</label>
                    <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label for="to" class="form-label">To</label>
                    <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label for="direction" class="form-label">Direction</label>
                    <select id="direction" name="direction" class="form-select">
                        <option value="both" @selected($selectedDirection === 'both')>Income + Expense</option>
                        <option value="income" @selected($selectedDirection === 'income')>Income</option>
                        <option value="expense" @selected($selectedDirection === 'expense')>Expense</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="categories" class="form-label">Categories</label>
                    <select id="categories" name="categories[]" class="form-select" multiple>
                        @foreach ($categories as $key => $category)
                            <option value="{{ $key }}" @selected(in_array($key, $selectedCategories, true))>
                                {{ $category['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-secondary">Hold Ctrl/Cmd to multi-select.</small>
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-filter-circle me-1"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-5 g-3 mb-4">
        @php
            $formatCurrency = static fn (float $value): string => '$' . number_format($value, 2);
        @endphp
        @foreach ($infoCards as $card)
            <div class="col">
                <div class="info-box shadow-sm h-100">
                    <span class="info-box-icon text-bg-{{ $card['variant'] }}">
                        <i class="{{ $card['icon'] }}"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text text-secondary text-uppercase fw-semibold">{{ $card['title'] }}</span>
                        <span class="info-box-number fs-4 fw-semibold">
                            {{ $formatCurrency($card['value']) }}
                        </span>
                        @if (! empty($card['helper']))
                            <span class="text-secondary small">{{ $card['helper'] }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Daily Net Flow</span>
                    <span class="badge bg-primary-subtle text-primary-emphasis">{{ $from->toFormattedDateString() }} - {{ $to->toFormattedDateString() }}</span>
                </div>
                <div class="card-body">
                    <div id="financeNetChart" class="w-100" style="min-height: 260px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Category Breakdown</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Stacked by day</span>
                </div>
                <div class="card-body">
                    <div id="financeCategoryChart" class="w-100" style="min-height: 260px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Ledger</span>
            <span class="text-secondary small">
                {{ number_format((int) $dailyTotals->sum('entry_count')) }} entries across {{ $ledgerDates->count() }} days
            </span>
        </div>
        <div class="card-body">
            <div class="accordion" id="ledgerAccordion">
                @forelse ($ledgerDates as $date)
                    @php
                        $accordionId = 'ledger-' . md5($date);
                        $summary = $dailyTotals[$date] ?? ['entry_count' => 0, 'income' => 0, 'expense' => 0, 'net' => 0];
                    @endphp
                    <div class="accordion-item mb-2 border">
                        <h2 class="accordion-header" id="heading-{{ $accordionId }}">
                            <button class="accordion-button collapsed bg-body" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapse-{{ $accordionId }}" aria-expanded="false"
                                    aria-controls="collapse-{{ $accordionId }}">
                                <div class="d-flex flex-column flex-md-row w-100 justify-content-between">
                                    <span class="fw-semibold">{{ \Carbon\Carbon::parse($date)->toFormattedDateString() }}</span>
                                    <div class="d-flex flex-wrap gap-3">
                                        <span class="badge text-bg-secondary">{{ number_format((int) $summary['entry_count']) }} entries</span>
                                        <span class="badge text-bg-success">Income: {{ $formatCurrency($summary['income']) }}</span>
                                        <span class="badge text-bg-danger">Expense: {{ $formatCurrency($summary['expense']) }}</span>
                                        <span class="badge text-bg-{{ $summary['net'] >= 0 ? 'primary' : 'warning' }}">Net: {{ $formatCurrency($summary['net']) }}</span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse-{{ $accordionId }}" class="accordion-collapse collapse"
                             aria-labelledby="heading-{{ $accordionId }}" data-bs-parent="#ledgerAccordion">
                            <div class="accordion-body"
                                 data-ledger-day
                                 data-loaded="false"
                                 data-url="{{ route('admin.finance.day', ['date' => $date] + request()->query()) }}">
                                <div class="d-flex align-items-center gap-2 text-secondary">
                                    <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                                    <span>Loading entries...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-secondary mb-0">No ledger data for this range.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const netData = @json($netChart);
            const categoryDatasets = @json($categoryDatasets);
            const colorMap = {
                primary: '#0d6efd',
                secondary: '#6c757d',
                success: '#198754',
                danger: '#dc3545',
                warning: '#ffc107',
                info: '#0dcaf0',
                light: '#f8f9fa',
                dark: '#212529',
            };

            const createSvgNode = (name, attributes = {}) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', name);

                Object.entries(attributes).forEach(([key, value]) => {
                    node.setAttribute(key, value);
                });

                return node;
            };

            const formatMoney = (value) => '$' + Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0,
            });

            const renderNetChart = () => {
                const container = document.getElementById('financeNetChart');
                if (!container || !netData.labels.length) {
                    return;
                }

                const width = 720;
                const height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 56 };
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
                    class: 'w-100',
                    role: 'img',
                    'aria-label': 'Daily net flow chart',
                });

                [0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
                    const value = max - range * ratio;
                    const y = padding.top + innerHeight * ratio;
                    svg.appendChild(createSvgNode('line', {
                        x1: padding.left,
                        y1: y,
                        x2: width - padding.right,
                        y2: y,
                        stroke: '#dee2e6',
                        'stroke-width': '1',
                    }));

                    const label = createSvgNode('text', {
                        x: padding.left - 8,
                        y: y + 4,
                        'text-anchor': 'end',
                        'font-size': '11',
                        fill: '#6c757d',
                    });
                    label.textContent = formatMoney(value);
                    svg.appendChild(label);
                });

                const zeroY = yForValue(0);
                svg.appendChild(createSvgNode('line', {
                    x1: padding.left,
                    y1: zeroY,
                    x2: width - padding.right,
                    y2: zeroY,
                    stroke: '#adb5bd',
                    'stroke-width': '1.5',
                }));

                [
                    { label: 'Income', color: colorMap.success, data: netData.income },
                    { label: 'Expense', color: colorMap.danger, data: netData.expense },
                    { label: 'Net', color: colorMap.primary, data: netData.net },
                ].forEach((series) => {
                    const points = series.data.map((value, index) => `${xForIndex(index)},${yForValue(value)}`).join(' ');
                    svg.appendChild(createSvgNode('polyline', {
                        points,
                        fill: 'none',
                        stroke: series.color,
                        'stroke-width': '3',
                        'stroke-linecap': 'round',
                        'stroke-linejoin': 'round',
                    }));
                });

                const firstLabel = createSvgNode('text', {
                    x: padding.left,
                    y: height - 10,
                    'font-size': '11',
                    fill: '#6c757d',
                });
                firstLabel.textContent = netData.labels[0];
                svg.appendChild(firstLabel);

                const lastLabel = createSvgNode('text', {
                    x: width - padding.right,
                    y: height - 10,
                    'font-size': '11',
                    'text-anchor': 'end',
                    fill: '#6c757d',
                });
                lastLabel.textContent = netData.labels[netData.labels.length - 1];
                svg.appendChild(lastLabel);

                const legend = document.createElement('div');
                legend.className = 'd-flex flex-wrap gap-3 small text-secondary mt-3';
                legend.innerHTML = `
                    <span><span class="d-inline-block rounded-circle me-1 align-middle" style="width:10px;height:10px;background:${colorMap.success};"></span>Income</span>
                    <span><span class="d-inline-block rounded-circle me-1 align-middle" style="width:10px;height:10px;background:${colorMap.danger};"></span>Expense</span>
                    <span><span class="d-inline-block rounded-circle me-1 align-middle" style="width:10px;height:10px;background:${colorMap.primary};"></span>Net</span>
                `;

                container.innerHTML = '';
                container.appendChild(svg);
                container.appendChild(legend);
            };

            const renderCategoryChart = () => {
                const container = document.getElementById('financeCategoryChart');
                if (!container || !netData.labels.length || !categoryDatasets.length) {
                    return;
                }

                const width = 720;
                const height = 260;
                const padding = { top: 20, right: 20, bottom: 36, left: 56 };
                const totalsByDay = netData.labels.map((_, index) => categoryDatasets.reduce((sum, dataset) => sum + (Number(dataset.data[index]) || 0), 0));
                const max = Math.max(...totalsByDay, 1);
                const innerWidth = width - padding.left - padding.right;
                const innerHeight = height - padding.top - padding.bottom;
                const barWidth = Math.max(innerWidth / netData.labels.length - 4, 6);
                const stepX = innerWidth / netData.labels.length;
                const svg = createSvgNode('svg', {
                    viewBox: `0 0 ${width} ${height}`,
                    class: 'w-100',
                    role: 'img',
                    'aria-label': 'Category breakdown chart',
                });

                [0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
                    const value = max - max * ratio;
                    const y = padding.top + innerHeight * ratio;
                    svg.appendChild(createSvgNode('line', {
                        x1: padding.left,
                        y1: y,
                        x2: width - padding.right,
                        y2: y,
                        stroke: '#dee2e6',
                        'stroke-width': '1',
                    }));

                    const label = createSvgNode('text', {
                        x: padding.left - 8,
                        y: y + 4,
                        'text-anchor': 'end',
                        'font-size': '11',
                        fill: '#6c757d',
                    });
                    label.textContent = formatMoney(value);
                    svg.appendChild(label);
                });

                netData.labels.forEach((label, index) => {
                    let cumulative = 0;
                    const x = padding.left + index * stepX + 2;

                    categoryDatasets.forEach((dataset) => {
                        const value = Number(dataset.data[index]) || 0;
                        if (value <= 0) {
                            return;
                        }

                        const segmentHeight = (value / max) * innerHeight;
                        const y = height - padding.bottom - ((cumulative + value) / max) * innerHeight;

                        svg.appendChild(createSvgNode('rect', {
                            x,
                            y,
                            width: barWidth,
                            height: segmentHeight,
                            rx: '1',
                            fill: colorMap[dataset.color] ?? colorMap.primary,
                        }));

                        cumulative += value;
                    });
                });

                const firstLabel = createSvgNode('text', {
                    x: padding.left,
                    y: height - 10,
                    'font-size': '11',
                    fill: '#6c757d',
                });
                firstLabel.textContent = netData.labels[0];
                svg.appendChild(firstLabel);

                const lastLabel = createSvgNode('text', {
                    x: width - padding.right,
                    y: height - 10,
                    'font-size': '11',
                    'text-anchor': 'end',
                    fill: '#6c757d',
                });
                lastLabel.textContent = netData.labels[netData.labels.length - 1];
                svg.appendChild(lastLabel);

                const legend = document.createElement('div');
                legend.className = 'd-flex flex-wrap gap-3 small text-secondary mt-3';
                legend.innerHTML = categoryDatasets.map((dataset) => `
                    <span><span class="d-inline-block rounded-circle me-1 align-middle" style="width:10px;height:10px;background:${colorMap[dataset.color] ?? colorMap.primary};"></span>${dataset.label}</span>
                `).join('');

                container.innerHTML = '';
                container.appendChild(svg);
                container.appendChild(legend);
            };

            const loadLedgerDay = async (body) => {
                if (!body || body.dataset.loaded === 'true' || body.dataset.loading === 'true') {
                    return;
                }

                body.dataset.loading = 'true';

                try {
                    const response = await fetch(body.dataset.url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(`Failed to load day entries (${response.status})`);
                    }

                    body.innerHTML = await response.text();
                    body.dataset.loaded = 'true';
                } catch (error) {
                    body.innerHTML = `<p class="text-danger mb-0">${error.message}</p>`;
                } finally {
                    delete body.dataset.loading;
                }
            };

            document.querySelectorAll('#ledgerAccordion .accordion-collapse').forEach((collapse) => {
                collapse.addEventListener('show.bs.collapse', () => {
                    loadLedgerDay(collapse.querySelector('[data-ledger-day]'));
                });
            });

            renderNetChart();
            renderCategoryChart();
        });
    </script>
@endsection
