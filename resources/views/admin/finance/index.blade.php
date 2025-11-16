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
                    <span class="badge bg-primary-subtle text-primary-emphasis">{{ $from->toFormattedDateString() }} – {{ $to->toFormattedDateString() }}</span>
                </div>
                <div class="card-body">
                    <canvas id="financeNetChart" height="220"></canvas>
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
                    <canvas id="financeCategoryChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Ledger</span>
            <span class="text-secondary small">{{ $entriesByDate->flatten()->count() }} entries</span>
        </div>
        <div class="card-body">
            <div class="accordion" id="ledgerAccordion">
                @forelse ($entriesByDate as $date => $items)
                    @php
                        $accordionId = 'ledger-' . md5($date);
                        $summary = $dailyTotals[$date] ?? ['income' => 0, 'expense' => 0, 'net' => 0];
                    @endphp
                    <div class="accordion-item mb-2 border">
                        <h2 class="accordion-header" id="heading-{{ $accordionId }}">
                            <button class="accordion-button collapsed bg-body" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapse-{{ $accordionId }}" aria-expanded="false"
                                    aria-controls="collapse-{{ $accordionId }}">
                                <div class="d-flex flex-column flex-md-row w-100 justify-content-between">
                                    <span class="fw-semibold">{{ \Carbon\Carbon::parse($date)->toFormattedDateString() }}</span>
                                    <div class="d-flex flex-wrap gap-3">
                                        <span class="badge text-bg-success">Income: {{ $formatCurrency($summary['income']) }}</span>
                                        <span class="badge text-bg-danger">Expense: {{ $formatCurrency($summary['expense']) }}</span>
                                        <span class="badge text-bg-{{ $summary['net'] >= 0 ? 'primary' : 'warning' }}">Net: {{ $formatCurrency($summary['net']) }}</span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse-{{ $accordionId }}" class="accordion-collapse collapse"
                             aria-labelledby="heading-{{ $accordionId }}" data-bs-parent="#ledgerAccordion">
                            <div class="accordion-body">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Direction</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th class="text-end">Money</th>
                                            @foreach (['coal','oil','uranium','iron','bauxite','lead','gasoline','munitions','steel','aluminum','food'] as $resource)
                                                <th class="text-end text-nowrap text-capitalize">{{ $resource }}</th>
                                            @endforeach
                                            <th>Nation</th>
                                            <th>Account</th>
                                            <th>Source</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($items as $entry)
                                            @php
                                                $category = $categories[$entry->category] ?? null;
                                                $categoryColor = $category['color'] ?? 'secondary';
                                                $source = $entry->source;
                                                $sourceLabel = $entry->source_type ? class_basename($entry->source_type) . ' #' . $entry->source_id : null;
                                                $sourceLink = null;

                                                if ($source instanceof \App\Models\GrantApplication) {
                                                    $sourceLink = route('admin.grants');
                                                } elseif ($source instanceof \App\Models\CityGrantRequest) {
                                                    $sourceLink = route('admin.grants.city');
                                                } elseif ($source instanceof \App\Models\WarAidRequest) {
                                                    $sourceLink = route('admin.war-aid');
                                                } elseif ($source instanceof \App\Models\Taxes) {
                                                    $sourceLink = route('admin.taxes');
                                                } elseif ($source instanceof \App\Models\LoanPayment && $source->loan_id) {
                                                    $sourceLink = route('admin.loans.view', ['Loan' => $source->loan_id]);
                                                }
                                            @endphp
                                            <tr>
                                                <td class="text-nowrap">{{ optional($entry->created_at)->format('H:i') ?? '—' }}</td>
                                                <td>
                                                    <span class="badge text-bg-{{ $entry->isIncome() ? 'success' : 'danger' }}">
                                                        {{ ucfirst($entry->direction) }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge text-bg-{{ $categoryColor }}">
                                                        {{ $category['label'] ?? ucfirst($entry->category) }}
                                                    </span>
                                                </td>
                                                <td class="text-break" style="max-width: 220px;">{{ $entry->description ?? '—' }}</td>
                                                <td class="text-end fw-semibold">{{ $formatCurrency($entry->money) }}</td>
                                                @foreach (['coal','oil','uranium','iron','bauxite','lead','gasoline','munitions','steel','aluminum','food'] as $resource)
                                                    <td class="text-end text-nowrap">{{ number_format($entry->$resource, 2) }}</td>
                                                @endforeach
                                                <td>
                                                    @if ($entry->nation_id)
                                                        <a href="https://politicsandwar.com/nation/id={{ $entry->nation_id }}" target="_blank" rel="noopener"
                                                           class="text-decoration-none">
                                                            {{ $entry->nation?->nation_name ?? 'Nation #'.$entry->nation_id }}
                                                        </a>
                                                    @else
                                                        <span class="text-secondary">—</span>
                                                    @endif
                                                </td>
                                                <td>{{ $entry->account?->name ?? '—' }}</td>
                                                <td>
                                                    @if ($sourceLabel)
                                                        @if ($sourceLink)
                                                            <a href="{{ $sourceLink }}" class="badge bg-dark-subtle text-dark-emphasis text-decoration-none">
                                                                {{ $sourceLabel }}
                                                            </a>
                                                        @else
                                                            <span class="badge bg-dark-subtle text-dark-emphasis">{{ $sourceLabel }}</span>
                                                        @endif
                                                    @else
                                                        <span class="text-secondary">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

            const ctxNet = document.getElementById('financeNetChart');
            if (ctxNet && netData.labels.length) {
                new Chart(ctxNet, {
                    type: 'line',
                    data: {
                        labels: netData.labels,
                        datasets: [
                            {
                                label: 'Income',
                                data: netData.income,
                                borderColor: colorMap.success,
                                backgroundColor: colorMap.success + '33',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Expense',
                                data: netData.expense,
                                borderColor: colorMap.danger,
                                backgroundColor: colorMap.danger + '33',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Net',
                                data: netData.net,
                                borderColor: colorMap.primary,
                                borderWidth: 2,
                                fill: false,
                                tension: 0.3,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        stacked: false,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        const value = context.parsed.y ?? 0;
                                        return `${context.dataset.label}: $${value.toLocaleString()}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback(value) {
                                        return '$' + Number(value).toLocaleString();
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const ctxCategory = document.getElementById('financeCategoryChart');
            if (ctxCategory) {
                const datasets = categoryDatasets.map(dataset => ({
                    label: dataset.label,
                    data: dataset.data,
                    backgroundColor: colorMap[dataset.color] ?? colorMap.primary,
                    stack: 'category',
                }));

                new Chart(ctxCategory, {
                    type: 'bar',
                    data: {
                        labels: netData.labels,
                        datasets,
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        const value = context.parsed.y ?? 0;
                                        return `${context.dataset.label}: $${value.toLocaleString()}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: { stacked: true },
                            y: {
                                stacked: true,
                                ticks: {
                                    callback(value) {
                                        return '$' + Number(value).toLocaleString();
                                    },
                                },
                            },
                        },
                    },
                });
            }
        });
    </script>
@endsection
