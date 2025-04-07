@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Nation Overview: <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank">{{ $nation->leader_name }}</a> (ID: {{ $nation->id }})</h3>
            <p class="text-muted">Last updated: {{ $lastUpdatedAt ? $lastUpdatedAt->diffForHumans() : 'Unknown' }}</p>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-bar-chart-line" bgColor="text-bg-primary" title="Score"
                              :value="number_format($lastScore, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-building" bgColor="text-bg-success" title="Cities"
                              :value="$lastCities"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-info" title="Total Taxes (30d)"
                              :value="number_format($taxHistory->take(30)->sum('money'))"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-clock-history" bgColor="text-bg-warning" title="Updates"
                              :value="$scoreHistory->count() . ' records'"/>
        </div>
    </div>

    {{-- Charts --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Money Tax History</div>
                <div class="card-body">
                    <canvas id="moneyTaxChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Food Tax History</div>
                <div class="card-body">
                    <canvas id="foodTaxChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Resource Tax History</div>
                <div class="card-body">
                    <canvas id="resourceTaxChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Score History (1 Year)</div>
                <div class="card-body">
                    <canvas id="scoreChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Money History (30 Days)</div>
                <div class="card-body">
                    <canvas id="moneySignInChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Resource History (30 Days)</div>
                <div class="card-body">
                    <canvas id="resourceSignInChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Tables --}}
    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Recent Loan Requests</div>
                <div class="card-body">
                    @include('admin.members.partials.loans', ['loans' => $recentLoans])
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Recent City Grant Requests</div>
                <div class="card-body">
                    @include('admin.members.partials.city_grants', ['requests' => $recentCityGrants])
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Recent Grant Requests</div>
                <div class="card-body">
                    @include('admin.members.partials.grants', ['requests' => $recentCustomGrants])
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Recent Taxes Paid</div>
                <div class="card-body">
                    @include('admin.members.partials.taxes', ['taxes' => $recentTaxes])
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const taxLabels = {!! json_encode($taxHistory->pluck('date')) !!};

        // Chart 1: Money Only
        const moneyTaxCtx = document.getElementById('moneyTaxChart');
        new Chart(moneyTaxCtx, {
            type: 'line',
            data: {
                labels: taxLabels,
                datasets: [
                    {
                        label: 'Money',
                        data: {!! json_encode($taxHistory->map(fn($row) => $row['money'])) !!},
                        fill: false,
                        tension: 0.3,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Chart 2: Resources
        const resourceTaxCtx = document.getElementById('resourceTaxChart');
        new Chart(resourceTaxCtx, {
            type: 'line',
            data: {
                labels: taxLabels,
                datasets: [
                        @foreach(['steel', 'gasoline', 'aluminum', 'munitions', 'uranium'] as $res)
                    {
                        label: '{{ ucfirst($res) }}',
                        data: {!! json_encode($taxHistory->map(fn($row) => $row[$res])) !!},
                        fill: false,
                        tension: 0.3
                    },
                    @endforeach
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const foodTaxCtx = document.getElementById('foodTaxChart');
        new Chart(foodTaxCtx, {
            type: 'line',
            data: {
                labels: taxLabels,
                datasets: [
                    {
                        label: 'Food',
                        data: {!! json_encode($taxHistory->map(fn($row) => $row['food'])) !!},
                        fill: false,
                        tension: 0.3
                    },
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Score Chart
        const scoreCtx = document.getElementById('scoreChart');
        new Chart(scoreCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode($scoreHistory->pluck('created_at')->map(fn($d) => $d->format('Y-m-d'))) !!},
                datasets: [{
                    label: 'Score',
                    data: {!! json_encode($scoreHistory->pluck('score')) !!},
                    fill: false
                }]
            }
        });

        const signInLabels = {!! json_encode($resourceSignInHistory->pluck('date')) !!};

        // Money History (30 Days)
        const moneySignInCtx = document.getElementById('moneySignInChart');
        new Chart(moneySignInCtx, {
            type: 'line',
            data: {
                labels: signInLabels,
                datasets: [{
                    label: 'Money',
                    data: {!! json_encode($resourceSignInHistory->map(fn($row) => $row['money'])) !!},
                    fill: false,
                    tension: 0.3,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // Resource History (30 Days)
        const resourceSignInCtx = document.getElementById('resourceSignInChart');
        new Chart(resourceSignInCtx, {
            type: 'line',
            data: {
                labels: signInLabels,
                datasets: [
                        @foreach(['steel', 'aluminum', 'gasoline', 'munitions'] as $res)
                    {
                        label: '{{ ucfirst($res) }}',
                        data: {!! json_encode($resourceSignInHistory->map(fn($row) => $row[$res])) !!},
                        fill: false,
                        tension: 0.3
                    },
                    @endforeach
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    </script>
@endsection