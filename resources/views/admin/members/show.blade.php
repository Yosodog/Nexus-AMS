@extends('layouts.admin')

@section('content')
    <x-header :title="'Nation Overview: ' . $nation->leader_name" separator use-h1>
        <x-slot:subtitle>
            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-primary">
                Open nation in Politics &amp; War
            </a>
            &nbsp;&middot;&nbsp;
            <x-copy-action :value="(string) $nation->id" label="nation ID" />
            &nbsp;&middot;&nbsp;
            Last updated: {{ $lastUpdatedAt ? $lastUpdatedAt->diffForHumans() : 'Unknown' }}
        </x-slot:subtitle>
    </x-header>

    {{-- Stats --}}
    <div class="mb-6">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-stat title="Score" :value="number_format($lastScore, 2)" icon="o-chart-bar" color="text-primary" />
            <x-stat title="Cities" :value="$lastCities" icon="o-building-office-2" color="text-success" />
            @if($canViewTaxes)
                <x-stat title="Total Taxes (30d)" :value="'$' . number_format($taxSummary['total_money'], 2)" icon="o-banknotes" color="text-info" />
            @endif
            <x-stat title="Updates" :value="$scoreHistory->count() . ' records'" icon="o-clock" color="text-warning" />
        </div>

        @if($canViewTaxes)
            <p class="mt-2 text-xs nexus-text-muted">
                UTC window: {{ $taxSummary['window_starts_at']->format('M j, Y H:i') }} through {{ $taxSummary['window_ends_at']->format('M j, Y H:i') }}.
                Uses synchronized post-Direct Deposit money-tax amounts, matching the finance ledger.
                Latest included record: {{ $taxSummary['latest_recorded_at'] ? $taxSummary['latest_recorded_at']->format('M j, Y H:i').' UTC' : 'none in this window' }}.
            </p>
        @endif
    </div>

    @if($canManageMemberExceptions)
        @include('admin.members.partials.inactivity-exceptions')
    @endif

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        @if($canViewTaxes)
            <x-card title="Money Tax History">
                <canvas id="moneyTaxChart" class="max-h-56"></canvas>
            </x-card>
            <x-card title="Food Tax History">
                <canvas id="foodTaxChart" class="max-h-56"></canvas>
            </x-card>
            <x-card title="Resource Tax History">
                <canvas id="resourceTaxChart" class="max-h-56"></canvas>
            </x-card>
        @endif
        <x-card title="Score History (1 Year)">
            <canvas id="scoreChart" class="max-h-56"></canvas>
        </x-card>
        @if($canViewMmr)
            <x-card title="Money History (30 Days)">
                <canvas id="moneySignInChart" class="max-h-56"></canvas>
            </x-card>
            <x-card title="Resource History (30 Days)">
                <canvas id="resourceSignInChart" class="max-h-56"></canvas>
            </x-card>
        @endif
    </div>

    {{-- Recent Tables --}}
    @if($canViewLoans || $canViewGrants || $canViewCityGrants || $canViewTaxes)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            @if($canViewLoans)
                <x-card title="Recent Loan Requests">
                    @include('admin.members.partials.loans', ['loans' => $recentLoans])
                </x-card>
            @endif
            @if($canViewGrants)
                <x-card title="Recent Grant Requests">
                    @include('admin.members.partials.grants', ['requests' => $recentCustomGrants])
                </x-card>
            @endif
            @if($canViewCityGrants)
                <x-card title="Recent City Grant Requests">
                    @include('admin.members.partials.city_grants', ['requests' => $recentCityGrants])
                </x-card>
            @endif
            @if($canViewTaxes)
                <x-card title="Recent Taxes Paid">
                    @include('admin.members.partials.taxes', ['taxes' => $recentTaxes])
                </x-card>
            @endif
        </div>
    @endif

    {{-- Account Overview --}}
    @if($canViewAccounts)
        <x-card title="Account Overview" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra" data-sortable="true">
                <thead>
                    <tr class="nexus-text-muted">
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
                                <div class="text-xs nexus-text-muted">#{{ $account['id'] }}</div>
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
                                class="text-center nexus-text-muted py-6">
                                No accounts found for this nation.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </x-card>
    @endif
@endsection

@push('scripts')
    <x-chart-js />
    <script>
        @if($canViewTaxes)
        const taxLabels = {!! json_encode($taxHistory->pluck('date')) !!};
        @endif
        @if($canViewMmr)
        const signInLabels = {!! json_encode($resourceSignInHistory->pluck('date')) !!};
        @endif
        const seriesColors = ['primary', 'secondary', 'success', 'info', 'warning', 'error'];
        const chartDefaults = { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } };

        @if($canViewTaxes)
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
        @endif

        new Chart(document.getElementById('scoreChart'), {
            type: 'line',
            data: {
                labels: {!! json_encode($scoreHistory->pluck('created_at')->map(fn($d) => $d->format('Y-m-d'))) !!},
                datasets: [{ label: 'Score', nexusColor: 'info', data: {!! json_encode($scoreHistory->pluck('score')) !!}, fill: false, tension: 0.3, borderWidth: 2 }]
            },
            options: { responsive: true }
        });

        @if($canViewMmr)
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
        @endif
    </script>
@endpush
