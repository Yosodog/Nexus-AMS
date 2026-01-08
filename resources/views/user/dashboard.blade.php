@extends('layouts.main')

@section('content')
    <div class="max-w-7xl xl:max-w-6xl 2xl:max-w-[1400px] mx-auto space-y-8">

        {{-- Overview --}}
        <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow-md space-y-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-start gap-4">
                    <div class="avatar">
                        <div class="w-20 rounded-lg border border-base-300">
                            <img src="{{ $nation->flag ?? 'https://politicsandwar.com/img/flags/default.png' }}" alt="Nation flag" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-base-content/60">Live overview</p>
                        <h1 class="text-3xl font-bold leading-tight">
                            {{ $nation->leader_name }}
                            <span class="text-base-content/60 text-lg font-semibold">({{ $nation->nation_name }})</span>
                        </h1>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <span class="badge badge-outline">{{ $nation->alliance->name ?? 'Unaffiliated' }}</span>
                            <span class="badge badge-outline">Score {{ number_format($nation->score, 2) }}</span>
                            <span class="badge badge-outline">{{ $nation->num_cities }} Cities</span>
                            @if($latestSignIn && $latestSignIn->created_at)
                                <span class="badge badge-ghost">Last sync {{ $latestSignIn->created_at->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('accounts') }}" class="btn btn-outline btn-sm">Accounts</a>
                    <a href="{{ route('grants.city') }}" class="btn btn-outline btn-sm">Grants</a>
                    <a href="{{ route('loans.index') }}" class="btn btn-outline btn-sm">Loans</a>
                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" class="btn btn-primary btn-sm">
                        View on P&amp;W
                    </a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl bg-primary/10 p-4 border border-primary/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-primary">MMR health</p>
                            <p class="text-xs text-primary/70">Tier {{ $mmrTier->city_count ?? 0 }} requirements</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge badge-sm {{ $mmrResourcesMet ? 'badge-success' : 'badge-warning' }}">{{ $mmrResourcesMet ? 'Resources covered' : 'Resources low' }}</span>
                            <span class="badge badge-sm {{ $mmrUnitsMet ? 'badge-success' : 'badge-warning' }}">{{ $mmrUnitsMet ? 'Units ready' : 'Units low' }}</span>
                            <span class="badge badge-sm {{ ($mmrScore ?? 0) < 50 ? 'badge-error' : 'badge-success' }}">{{ $mmrScore ?? 0 }}%</span>
                        </div>
                    </div>
                    <progress class="progress progress-primary w-full mt-2" value="{{ $mmrScore ?? 0 }}" max="100"></progress>
                    <p class="text-xs mt-2 text-base-content/70">
                        @if($mmrResourcesMet && $mmrUnitsMet)
                            Good job! You are meeting current MMR expectations.
                        @else
                            Top off the gaps below to hit full compliance for your tier.
                        @endif
                    </p>
                </div>
                <div class="rounded-xl bg-base-100 p-4 border border-base-300 shadow-sm">
                    <p class="text-sm font-semibold">Direct deposit received</p>
                    <p class="text-2xl font-bold">${{ number_format($afterTaxIncomeTotal ?? 0) }}</p>
                    <p class="text-xs text-base-content/70">Net cash deposited in the last 30 days after tax and MMR withholding.</p>
                </div>
                <div class="rounded-xl bg-base-100 p-4 border border-base-300 shadow-sm">
                    <p class="text-sm font-semibold">Support snapshot</p>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="p-2 rounded-lg bg-primary/5 border border-primary/20">
                            <p class="text-xs uppercase text-base-content/70">Grants</p>
                            <p class="text-lg font-bold">${{ number_format($grantTotal ?? 0) }}</p>
                        </div>
                        <div class="p-2 rounded-lg bg-warning/5 border border-warning/20">
                            <p class="text-xs uppercase text-base-content/70">Loans</p>
                            <p class="text-lg font-bold">${{ number_format($loanTotal ?? 0) }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @if (! $mmrResourcesMet)
                <div class="alert alert-warning shadow-sm">
                    <div>
                        <h3 class="font-semibold">Resource gap detected</h3>
                        <p class="text-sm">Keep at least the required resources for Tier {{ $mmrTier->city_count ?? 0 }} to reach 100%.</p>
                    </div>
                </div>
            @endif

            <div class="rounded-2xl border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-semibold">Payroll</p>
                            <span class="badge badge-sm {{ $payrollIsActive ? 'badge-success' : 'badge-ghost' }}">
                                {{ $payrollIsActive ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        @if($payrollMember && $payrollGrade)
                            <p class="text-lg font-bold mt-1">{{ $payrollGrade->name }}</p>
                            <p class="text-sm text-base-content/70">
                                Weekly ${{ number_format((float) $payrollGrade->weekly_amount, 2) }} ·
                                Daily ${{ number_format((float) $payrollDailyAmount, 2) }}
                            </p>
                        @else
                            <p class="text-sm text-base-content/70 mt-1">You are not enrolled in payroll.</p>
                        @endif
                    </div>
                    <div class="rounded-xl border border-base-200 bg-base-50/60 p-3 text-center md:min-w-[220px]">
                        <p class="text-xs uppercase text-base-content/60">Last 30 days</p>
                        <p class="text-2xl font-bold">${{ number_format($payrollMonthlyTotal ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Stat Highlights --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-utils.stat title="MMR Score" value="{{ $mmrScore ?? 0 }}%" desc="Nation health" icon="chart" color="primary" />
            <x-utils.stat title="Total Taxed" value="${{ number_format($taxTotal ?? 0) }}" desc="After deposits" icon="bank" color="accent" />
            <x-utils.stat title="Total Grants" value="${{ number_format($grantTotal ?? 0) }}" desc="Approved to date" icon="gift" color="success" />
            <x-utils.stat title="Total Loans" value="${{ number_format($loanTotal ?? 0) }}" desc="Outstanding" icon="credit-card" color="warning" />
            <x-utils.stat title="City Count" value="{{ $nation->num_cities ?? 0 }}" desc="Built and online" icon="city" color="info" />
            <x-utils.stat
                title="Last Update"
                :value="$latestSignIn && $latestSignIn->created_at ? $latestSignIn->created_at->diffForHumans() : 'N/A'"
                desc="PW sync"
                icon="clock"
                color="neutral"
            />
        </div>

        {{-- Comparison Tables --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <x-user.resource-comparison :resources="$mmrResourceBreakdown" :weights="$mmrWeights" />
            <x-user.military-comparison
                :nation="$nation"
                :latestSignIn="$latestSignIn"
                :requirements="$mmrUnitRequirements"
                :meets="$mmrUnitsMet"
            />
        </div>

        {{-- Nation Charts --}}
        <div class="card bg-base-100 shadow border border-base-300">
            <div class="card-body space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="card-title">Nation charts</h3>
                    <p class="text-sm text-base-content/70">Live snapshots from your synced data.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="rounded-xl border border-base-200 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Score history</h4>
                            <span class="badge badge-ghost">Trend</span>
                        </div>
                        <canvas id="scoreChart" class="w-full h-64"></canvas>
                    </div>
                    <div class="rounded-xl border border-base-200 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Tax revenue</h4>
                            <span class="badge badge-ghost">Money</span>
                        </div>
                        <canvas id="moneyTaxChart" class="w-full h-64"></canvas>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-base-200 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Resource tax revenue</h4>
                            <span class="badge badge-ghost">Stacked</span>
                        </div>
                        <canvas id="resourceTaxChart" class="w-full h-64"></canvas>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-base-200 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Military units over time</h4>
                            <span class="badge badge-ghost">Inventory</span>
                        </div>
                        <canvas id="militaryChart" class="w-full h-64"></canvas>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-base-200 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Resource holdings over time</h4>
                            <span class="badge badge-ghost">Stores</span>
                        </div>
                        <canvas id="resourceHoldingsChart" class="w-full h-64"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Transactions --}}
        <div class="card bg-base-100 shadow border border-base-300">
            <div class="card-body">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="card-title">Recent transactions</h3>
                    <a href="{{ route('accounts') }}" class="btn btn-sm btn-ghost">Open accounts</a>
                </div>
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
                                $isSent = $direction === 'Sent';
                            @endphp
                            <tr>
                                <td>{{ $tx->created_at->toDateTimeString() }}</td>
                                <td>
                                    <span class="badge {{ $isSent ? 'badge-ghost' : 'badge-success badge-outline' }}">
                                        {{ $direction }}
                                    </span>
                                </td>
                                <td class="font-semibold">{{ $isSent ? '-' : '+' }}${{ number_format($tx->money, 2) }}</td>
                                <td class="text-sm text-base-content/80">{{ $tx->note ?? 'No note provided' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-base-content/70">No transactions yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    @push('scripts')
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
    @endpush
@endsection
