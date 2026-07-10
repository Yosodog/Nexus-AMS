@extends('layouts.admin')

@section('content')
    <x-header :title="'Nation Overview: ' . $nation->leader_name" separator use-h1>
        <x-slot:subtitle>
            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" class="link link-primary">
                Nation #{{ $nation->id }}
            </a>
            &nbsp;&middot;&nbsp;
            Last updated: {{ $lastUpdatedAt ? $lastUpdatedAt->diffForHumans() : 'Unknown' }}
        </x-slot:subtitle>
    </x-header>

    {{-- Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-stat title="Score" :value="number_format($lastScore, 2)" icon="o-chart-bar" color="text-primary" />
        <x-stat title="Cities" :value="$lastCities" icon="o-building-office-2" color="text-success" />
        <x-stat title="Total Taxes (30d)" :value="'$' . number_format($taxHistory->take(30)->sum('money'))" icon="o-banknotes" color="text-info" />
        <x-stat title="Updates" :value="$scoreHistory->count() . ' records'" icon="o-clock" color="text-warning" />
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <x-card title="Money Tax History">
            <canvas id="moneyTaxChart" class="max-h-56"></canvas>
        </x-card>
        <x-card title="Food Tax History">
            <canvas id="foodTaxChart" class="max-h-56"></canvas>
        </x-card>
        <x-card title="Resource Tax History">
            <canvas id="resourceTaxChart" class="max-h-56"></canvas>
        </x-card>
        <x-card title="Score History (1 Year)">
            <canvas id="scoreChart" class="max-h-56"></canvas>
        </x-card>
        <x-card title="Money History (30 Days)">
            <canvas id="moneySignInChart" class="max-h-56"></canvas>
        </x-card>
        <x-card title="Resource History (30 Days)">
            <canvas id="resourceSignInChart" class="max-h-56"></canvas>
        </x-card>
    </div>

    {{-- Recent Tables --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <x-card title="Recent Loan Requests">
            @include('admin.members.partials.loans', ['loans' => $recentLoans])
        </x-card>
        <x-card title="Recent Grant Requests">
            @include('admin.members.partials.grants', ['requests' => $recentCustomGrants])
        </x-card>
        <x-card title="Recent City Grant Requests">
            @include('admin.members.partials.city_grants', ['requests' => $recentCityGrants])
        </x-card>
        <x-card title="Recent Taxes Paid">
            @include('admin.members.partials.taxes', ['taxes' => $recentTaxes])
        </x-card>
    </div>

    {{-- Account Overview --}}
    <x-card title="Account Overview" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra" data-sortable="true">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Account</th>
                        <th>Status</th>
                        @foreach(\App\Services\PWHelperService::resources() as $resource)
                            <th>{{ ucfirst($resource) }}</th>
                        @endforeach
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($memberAccounts as $account)
                        <tr>
                            <td>
                                <a href="{{ route('admin.accounts.view', $account['id']) }}" class="link link-primary font-semibold">
                                    {{ $account['name'] ?: 'Account #' . $account['id'] }}
                                </a>
                                <div class="text-xs text-base-content/50">#{{ $account['id'] }}</div>
                            </td>
                            <td>
                                <x-badge :value="$account['frozen'] ? 'Frozen' : 'Active'"
                                         :class="$account['frozen'] ? 'badge-error badge-sm' : 'badge-success badge-sm'" />
                            </td>
                            <td>${{ number_format((float) $account['resources']['money'], 2) }}</td>
                            @foreach(\App\Services\PWHelperService::resources(false) as $resource)
                                <td>{{ number_format((float) $account['resources'][$resource], 2) }}</td>
                            @endforeach
                            <td data-order="{{ $account['updated_at']?->timestamp ?? 0 }}">{{ $account['updated_at']?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count(\App\Services\PWHelperService::resources()) + 3 }}"
                                class="text-center text-base-content/50 py-6">
                                No accounts found for this nation.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

@push('scripts')
    <x-chart-js />
    <script>
        const taxLabels = {!! json_encode($taxHistory->pluck('date')) !!};
        const signInLabels = {!! json_encode($resourceSignInHistory->pluck('date')) !!};
        const seriesColors = ['primary', 'secondary', 'success', 'info', 'warning', 'error'];
        const chartDefaults = { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } };

        new Chart(document.getElementById('moneyTaxChart'), {
            type: 'line',
            data: { labels: taxLabels, datasets: [{ label: 'Money', nexusColor: 'primary', data: {!! json_encode($taxHistory->map(fn($row) => $row['money'])) !!}, fill: false, tension: 0.3, borderWidth: 2 }] },
            options: chartDefaults
        });

        new Chart(document.getElementById('foodTaxChart'), {
            type: 'line',
            data: { labels: taxLabels, datasets: [{ label: 'Food', nexusColor: 'success', data: {!! json_encode($taxHistory->map(fn($row) => $row['food'])) !!}, fill: false, tension: 0.3, borderWidth: 2 }] },
            options: chartDefaults
        });

        new Chart(document.getElementById('resourceTaxChart'), {
            type: 'line',
            data: {
                labels: taxLabels,
                datasets: [
                    @foreach(['steel', 'gasoline', 'aluminum', 'munitions', 'uranium'] as $res)
                    { label: '{{ ucfirst($res) }}', nexusColor: seriesColors[{{ $loop->index }} % seriesColors.length], data: {!! json_encode($taxHistory->map(fn($row) => $row[$res])) !!}, fill: false, tension: 0.3, borderWidth: 2 },
                    @endforeach
                ]
            },
            options: chartDefaults
        });

        new Chart(document.getElementById('scoreChart'), {
            type: 'line',
            data: {
                labels: {!! json_encode($scoreHistory->pluck('created_at')->map(fn($d) => $d->format('Y-m-d'))) !!},
                datasets: [{ label: 'Score', nexusColor: 'info', data: {!! json_encode($scoreHistory->pluck('score')) !!}, fill: false, tension: 0.3, borderWidth: 2 }]
            },
            options: { responsive: true }
        });

        new Chart(document.getElementById('moneySignInChart'), {
            type: 'line',
            data: { labels: signInLabels, datasets: [{ label: 'Money', nexusColor: 'primary', data: {!! json_encode($resourceSignInHistory->map(fn($row) => $row['money'])) !!}, fill: false, tension: 0.3, borderWidth: 2 }] },
            options: chartDefaults
        });

        new Chart(document.getElementById('resourceSignInChart'), {
            type: 'line',
            data: {
                labels: signInLabels,
                datasets: [
                    @foreach(['steel', 'aluminum', 'gasoline', 'munitions'] as $res)
                    { label: '{{ ucfirst($res) }}', nexusColor: seriesColors[{{ $loop->index }} % seriesColors.length], data: {!! json_encode($resourceSignInHistory->map(fn($row) => $row[$res])) !!}, fill: false, tension: 0.3, borderWidth: 2 },
                    @endforeach
                ]
            },
            options: chartDefaults
        });
    </script>
@endpush
