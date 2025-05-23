@php use Carbon\Carbon; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Taxes</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash-stack" bgColor="text-bg-success" title="Total Money Collected"
                              :value="number_format($stats['total_money'], 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-box-seam" bgColor="text-bg-primary" title="Top Resource"
                              :value="ucfirst($stats['top_resource'])"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-receipt" bgColor="text-bg-warning" title="Transactions (30d)"
                              :value="number_format($stats['transaction_count'])"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-graph-up" bgColor="text-bg-info" title="Avg Daily Money"
                              :value="number_format($stats['average_daily_money'], 2)"/>
        </div>
    </div>

    {{-- Charts --}}
    <h2 class="mb-3">Resource Trends (30 Days)</h2>
    <div class="row">
        @foreach ($charts as $resource => $data)
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title text-capitalize">{{ $resource }} Collected</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="chart-{{ $resource }}"></canvas>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Daily Totals --}}
    <h2 class="mb-3">Daily Totals</h2>
    <div class="row">
        @foreach ($totals as $resource => $daily)
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title text-capitalize">{{ $resource }} - Daily Totals</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm mb-0">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($daily as $entry)
                                    <tr>
                                        <td>{{ Carbon::parse($entry['day'])->toFormattedDateString() }}</td>
                                        <td>{{ number_format($entry['total'], 2) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@section("scripts")
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        @foreach ($charts as $resource => $data)
        const ctx_{{ $resource }} = document.getElementById('chart-{{ $resource }}').getContext('2d');
        new Chart(ctx_{{ $resource }}, {
            type: 'line',
            data: {
                labels: {!! json_encode($data['labels']) !!},
                datasets: [{
                    label: '{{ ucfirst($resource) }}',
                    data: {!! json_encode($data['data']) !!},
                    fill: true,
                    borderColor: 'rgba(60,141,188,0.8)',
                    backgroundColor: 'rgba(60,141,188,0.1)',
                    tension: 0.3,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        @endforeach
    </script>
@endsection