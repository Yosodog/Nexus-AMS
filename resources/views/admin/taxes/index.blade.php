@php use Carbon\Carbon; @endphp
@extends('layouts.admin')

@section('content')
    <x-header title="Taxes" separator />

    {{-- Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-stat title="Total Money Collected" :value="'$' . number_format($stats['total_money'], 2)" icon="o-banknotes" color="text-success" />
        <x-stat title="Top Resource" :value="ucfirst($stats['top_resource'])" icon="o-cube" color="text-primary" />
        <x-stat title="Transactions (30d)" :value="number_format($stats['transaction_count'])" icon="o-receipt-percent" color="text-warning" />
        <x-stat title="Avg Daily Money" :value="'$' . number_format($stats['average_daily_money'], 2)" icon="o-arrow-trending-up" color="text-info" />
    </div>

    {{-- Resource Trend Charts --}}
    <div class="mb-2">
        <h2 class="text-lg font-semibold mb-4">Resource Trends (30 Days)</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            @foreach ($charts as $resource => $data)
                <x-card :title="ucfirst($resource) . ' Collected'">
                    <div class="h-72">
                        <canvas id="chart-{{ $resource }}" class="h-full w-full"></canvas>
                    </div>
                </x-card>
            @endforeach
        </div>
    </div>

    {{-- Daily Totals --}}
    <h2 class="text-lg font-semibold mb-4">Daily Totals</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($totals as $resource => $daily)
            <x-card :title="ucfirst($resource) . ' – Daily Totals'">
                <div class="overflow-x-auto max-h-64 overflow-y-auto">
                    <table class="table table-sm table-zebra">
                        <thead>
                            <tr class="text-base-content/60">
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
            </x-card>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        @foreach ($charts as $resource => $data)
        new Chart(document.getElementById('chart-{{ $resource }}').getContext('2d'), {
            type: 'line',
            data: {
                labels: {!! json_encode($data['labels']) !!},
                datasets: [{
                    label: '{{ ucfirst($resource) }}',
                    data: {!! json_encode($data['data']) !!},
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });
        @endforeach
    </script>
@endpush
