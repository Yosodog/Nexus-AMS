@extends('layouts.main')

@section('content')
    <div class="max-w-7xl mx-auto p-4 space-y-6">

        {{-- Alert for Low MMR Score --}}
        @if ($mmrScore < 50)
            <div class="alert alert-error">
                <span>Your MMR score is dangerously low. Improve your nation!</span>
            </div>
        @endif

        {{-- Nation Summary Card --}}
        <div class="card bg-base-100 shadow-md">
            <div class="card-body flex flex-col md:flex-row items-center gap-6">
                <div class="avatar">
                    <div class="w-24 rounded">
                        <img src="{{ $nation->flag ?? 'https://politicsandwar.com/img/flags/default.png' }}" alt="Nation flag" />
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-bold">{{ $nation->leader_name }} <span class="text-base-content/50">({{ $nation->nation_name }})</span></h2>
                    <p class="text-sm">Alliance: {{ $nation->alliance->name ?? 'None' }}</p>
                    <p class="text-sm">Score: {{ number_format($nation->score, 2) }} | Cities: {{ $nation->num_cities }}</p>
                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" class="btn btn-sm btn-outline mt-2">View on P&W</a>
                </div>
            </div>
        </div>

        {{-- Stat Boxes --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-utils.stat title="MMR Score" value="{{ $mmrScore ?? 0 }}%" desc="Nation health indicator" icon="chart" color="primary" />
            <x-utils.stat title="Total Taxed" value="${{ number_format($taxTotal ?? 0) }}" icon="bank" color="accent" />
            <x-utils.stat title="Total Grants" value="${{ number_format($grantTotal ?? 0) }}" icon="gift" color="success" />
            <x-utils.stat title="Total Loans" value="${{ number_format($loanTotal ?? 0) }}" icon="credit-card" color="warning" />
            <x-utils.stat title="City Count" value="{{ $nation->num_cities ?? 0 }}" icon="city" color="info" />
            <x-utils.stat
                    title="Last Update"
                    :value="$latestSignIn && $latestSignIn->created_at ? $latestSignIn->created_at->diffForHumans() : 'N/A'"
                    icon="clock"
                    color="neutral"
            />
        </div>

        {{-- Comparison Tables --}}
        <x-user.resource-comparison :nation="$nation" />
        <x-user.military-comparison :nation="$nation" />

        {{-- Nation Charts --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Nation Charts</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    {{-- Score History --}}
                    <div>
                        <h4 class="font-semibold mb-2">Score History</h4>
                        <canvas id="scoreChart" class="w-full h-64"></canvas>
                    </div>

                    {{-- Money Tax History --}}
                    <div>
                        <h4 class="font-semibold mb-2">Tax Revenue (Money)</h4>
                        <canvas id="moneyTaxChart" class="w-full h-64"></canvas>
                    </div>

                    {{-- Resource Tax Revenue --}}
                    <div class="md:col-span-2">
                        <h4 class="font-semibold mb-2">Resource Tax Revenue</h4>
                        <canvas id="resourceTaxChart" class="w-full h-64"></canvas>
                    </div>

                    {{-- Military Units Over Time --}}
                    <div class="md:col-span-2">
                        <h4 class="font-semibold mb-2">Military Units Over Time</h4>
                        <canvas id="militaryChart" class="w-full h-64"></canvas>
                    </div>

                    {{-- Resource Holdings Over Time --}}
                    <div class="md:col-span-2">
                        <h4 class="font-semibold mb-2">Resource Holdings Over Time</h4>
                        <canvas id="resourceHoldingsChart" class="w-full h-64"></canvas>
                    </div>

                </div>
            </div>
        </div>

        {{-- Recent Transactions --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Recent Transactions</h3>
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Direction</th>
                            <th>Amount</th>
                            <th>Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($recentTransactions as $tx)
                            @php
                                $direction = in_array($tx->from_account_id, $nation->accounts->pluck('id')->toArray()) ? 'Sent' : 'Received';
                            @endphp
                            <tr>
                                <td>{{ $tx->created_at->toDateTimeString() }}</td>
                                <td>{{ $direction }}</td>
                                <td>${{ number_format($tx->money, 2) }}</td>
                                <td>{{ $tx->note ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">No transactions</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const scoreChart = new Chart(document.getElementById('scoreChart'), {
            type: 'line',
            data: {
                labels: @json($scoreChart['labels']),
                datasets: [{
                    label: 'Score',
                    data: @json($scoreChart['data']),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
            }
        });

        const moneyTaxChart = new Chart(document.getElementById('moneyTaxChart'), {
            type: 'bar',
            data: {
                labels: @json($moneyTaxChart['labels']),
                datasets: [{
                    label: 'Money',
                    data: @json($moneyTaxChart['data']),
                    backgroundColor: '#10b981',
                }]
            },
            options: {
                responsive: true,
            }
        });

        const resourceTaxChart = new Chart(document.getElementById('resourceTaxChart'), {
            type: 'bar',
            data: {
                labels: @json($resourceTaxChart['labels']),
                datasets: [
                        @foreach ($resourceTaxChart['resources'] as $res => $rData)
                    {
                        label: '{{ $rData['label'] }}',
                        data: @json($rData['data']),
                        backgroundColor: '{{ "#" . substr(md5($res), 0, 6) }}',
                        borderWidth: 1,
                    },
                    @endforeach
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    },
                    legend: {
                        position: 'bottom',
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                }
            }
        });

        const militaryChart = new Chart(document.getElementById('militaryChart'), {
            type: 'line',
            data: {
                labels: @json($militaryChart['labels']),
                datasets: @json($militaryChart['datasets'])
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    },
                    legend: {
                        position: 'bottom',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const resourceHoldingsChart = new Chart(document.getElementById('resourceHoldingsChart'), {
            type: 'line',
            data: {
                labels: @json($resourceHoldingsChart['labels']),
                datasets: @json($resourceHoldingsChart['datasets'])
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    },
                    legend: {
                        position: 'bottom',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
@endsection