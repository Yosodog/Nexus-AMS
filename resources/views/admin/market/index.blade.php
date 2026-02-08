@extends('layouts.admin')

@section('content')
    <div class="app-content-header mb-3">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-12 col-lg-6">
                    <h3 class="mb-1">Alliance Market</h3>
                    <p class="text-secondary mb-0">Manage buyable resources, caps, and pricing adjustments.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="info-box shadow-sm h-100">
                <span class="info-box-icon bg-primary text-white shadow">
                    <i class="bi bi-box-seam"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text text-uppercase text-secondary fw-semibold">30d Volume</span>
                    <span class="info-box-number fs-4 fw-semibold">
                        {{ number_format($overview['stats']['volume'], $overview['stats']['volume'] >= 1000 ? 0 : 2) }}
                    </span>
                    <span class="text-secondary small">Units sold across all resources.</span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="info-box shadow-sm h-100">
                <span class="info-box-icon bg-success text-white shadow">
                    <i class="bi bi-cash-stack"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text text-uppercase text-secondary fw-semibold">30d Paid</span>
                    <span class="info-box-number fs-4 fw-semibold">
                        ${{ number_format($overview['stats']['total_paid'], 2) }}
                    </span>
                    <span class="text-secondary small">Total cash paid out.</span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="info-box shadow-sm h-100">
                <span class="info-box-icon bg-info text-white shadow">
                    <i class="bi bi-trophy"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text text-uppercase text-secondary fw-semibold">Top Resource</span>
                    <span class="info-box-number fs-4 fw-semibold text-capitalize">
                        {{ $overview['stats']['top_resource'] ? str_replace('_', ' ', $overview['stats']['top_resource']) : '—' }}
                    </span>
                    <span class="text-secondary small">
                        ${{ number_format($overview['stats']['top_resource_paid'], 2) }} paid in 30d.
                    </span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="info-box shadow-sm h-100">
                <span class="info-box-icon bg-warning text-white shadow">
                    <i class="bi bi-clipboard-check"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text text-uppercase text-secondary fw-semibold">Remaining Cap</span>
                    <span class="info-box-number fs-4 fw-semibold">
                        {{ number_format($overview['stats']['total_remaining_cap'], $overview['stats']['total_remaining_cap'] >= 1000 ? 0 : 2) }}
                    </span>
                    <span class="text-secondary small">Total remaining across resources.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">30-Day Cash Paid</div>
                <div class="card-body">
                    <canvas id="marketPaidChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">30-Day Volume by Resource</div>
                <div class="card-body">
                    <canvas id="marketVolumeChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4" id="market-resources">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Market Resources</span>
            <span class="text-secondary small">Base prices from 24h averages.</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle" id="marketResourcesTable">
                    <thead>
                    <tr>
                        <th>Resource</th>
                        <th>Status</th>
                        <th>Adjustment %</th>
                        <th>Buy Cap Remaining</th>
                        <th>Base Price</th>
                        <th>Final Price</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($marketResources as $resource)
                        <tr data-market-resource>
                            <td class="text-capitalize">{{ str_replace('_', ' ', $resource['resource']) }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.market.resource.toggle', $resource['id']) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm {{ $resource['is_enabled'] ? 'btn-success' : 'btn-outline-secondary' }}">
                                        {{ $resource['is_enabled'] ? 'Enabled' : 'Disabled' }}
                                    </button>
                                </form>
                            </td>
                            <td>
                                <input type="number" step="0.01" name="adjustment_percent" class="form-control form-control-sm"
                                       value="{{ number_format($resource['adjustment_percent'], 2, '.', '') }}"
                                       data-adjustment-input
                                       data-base-price="{{ $resource['base_price'] }}"
                                       data-final-target="market-final-{{ $resource['id'] }}"
                                       form="update-market-{{ $resource['id'] }}">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="buy_cap_remaining" class="form-control form-control-sm"
                                       value="{{ number_format($resource['buy_cap_remaining'], 2, '.', '') }}"
                                       form="update-market-{{ $resource['id'] }}">
                            </td>
                            <td>${{ number_format($resource['base_price'], 4) }}</td>
                            <td id="market-final-{{ $resource['id'] }}">${{ number_format($resource['final_price'], 4) }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.market.resource.update', $resource['id']) }}" id="update-market-{{ $resource['id'] }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header fw-semibold">Latest Transactions (Last 50)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="marketTransactionsTable">
                    <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Nation</th>
                        <th>Account</th>
                        <th>Resource</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Final Price</th>
                        <th class="text-end">Money Paid</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($recentTransactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at->format('M d, Y H:i') }}</td>
                            <td>{{ $transaction->user?->name ?? 'Unknown' }}</td>
                            <td>{{ $transaction->nation?->leader_name ?? 'Unknown' }}</td>
                            <td>{{ $transaction->account?->name ?? 'Unknown' }}</td>
                            <td class="text-capitalize">{{ str_replace('_', ' ', $transaction->resource) }}</td>
                            <td class="text-end">{{ number_format($transaction->amount, 2) }}</td>
                            <td class="text-end">${{ number_format($transaction->final_price, 4) }}</td>
                            <td class="text-end">${{ number_format($transaction->money_paid, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const formatPrice = (value) => {
                return new Intl.NumberFormat(undefined, {
                    minimumFractionDigits: 4,
                    maximumFractionDigits: 4
                }).format(value);
            };

            document.querySelectorAll('[data-adjustment-input]').forEach((input) => {
                const basePrice = parseFloat(input.dataset.basePrice || '0');
                const targetId = input.dataset.finalTarget;
                const target = targetId ? document.getElementById(targetId) : null;

                if (!target) {
                    return;
                }

                const updateFinal = () => {
                    const adjustment = parseFloat(input.value || '0');
                    const finalPrice = basePrice * (1 + (isNaN(adjustment) ? 0 : adjustment) / 100);
                    target.textContent = `$${formatPrice(finalPrice)}`;
                };

                input.addEventListener('input', updateFinal);
                updateFinal();
            });

            new DataTable('#marketResourcesTable', {
                pageLength: 50,
                responsive: true
            });

            new DataTable('#marketTransactionsTable', {
                pageLength: 25,
                responsive: true
            });

            const paidCtx = document.getElementById('marketPaidChart').getContext('2d');
            new Chart(paidCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($overview['money_paid_chart']['labels']) !!},
                    datasets: [{
                        label: 'Money Paid',
                        data: {!! json_encode($overview['money_paid_chart']['data']) !!},
                        borderWidth: 2,
                        tension: 0.3,
                        fill: false
                    }]
                }
            });

            const volumeCtx = document.getElementById('marketVolumeChart').getContext('2d');
            new Chart(volumeCtx, {
                type: 'bar',
                data: {
                    labels: {!! json_encode($overview['volume_chart']['labels']) !!},
                    datasets: {!! json_encode($overview['volume_chart']['datasets']) !!}
                },
                options: {
                    responsive: true,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true }
                    }
                }
            });
        });
    </script>
@endpush
