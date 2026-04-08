@extends('layouts.admin')

@section('content')
    <div class="mb-6 mb-3">
        <div class="w-full">
            <div class="row align-items-center">
                <div class="col-12 col-lg-6">
                    <h3 class="mb-1">Alliance Market</h3>
                    <p class="text-base-content/50 mb-0">Manage buyable resources, caps, and pricing adjustments.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="30d Volume"
                :value="number_format($overview['stats']['volume'], $overview['stats']['volume'] >= 1000 ? 0 : 2)"
                icon="o-archive-box"
                color="text-primary"
                description="Units sold across all listed resources" />
        <x-stat title="30d Paid"
                :value="'$' . number_format($overview['stats']['total_paid'], 2)"
                icon="o-banknotes"
                color="text-success"
                description="Alliance cash paid to sellers" />
        <x-stat title="Top Resource"
                :value="$overview['stats']['top_resource'] ? str_replace('_', ' ', $overview['stats']['top_resource']) : '—'"
                icon="o-trophy"
                color="text-info"
                :description="'$' . number_format($overview['stats']['top_resource_paid'], 2) . ' paid in 30d'" />
        <x-stat title="Remaining Cap"
                :value="number_format($overview['stats']['total_remaining_cap'], $overview['stats']['total_remaining_cap'] >= 1000 ? 0 : 2)"
                icon="o-clipboard-document-check"
                color="text-warning"
                description="Combined remaining buy cap across resources" />
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header font-semibold">30-Day Cash Paid</div>
                <div class="card-body">
                    <canvas id="marketPaidChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header font-semibold">30-Day Volume by Resource</div>
                <div class="card-body">
                    <canvas id="marketVolumeChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4" id="market-resources">
        <div class="card-header flex justify-content-between align-items-center">
            <span class="font-semibold">Market Resources</span>
            <span class="text-base-content/50 small">Base prices from 24h averages.</span>
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
        <div class="card-header font-semibold">Latest Transactions (Last 50)</div>
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
                        <th class="text-right">Amount</th>
                        <th class="text-right">Final Price</th>
                        <th class="text-right">Money Paid</th>
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
                            <td class="text-right">{{ number_format($transaction->amount, 2) }}</td>
                            <td class="text-right">${{ number_format($transaction->final_price, 4) }}</td>
                            <td class="text-right">${{ number_format($transaction->money_paid, 2) }}</td>
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
