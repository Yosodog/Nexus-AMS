@extends('layouts.admin')

@section('content')
    {{-- Page Header --}}
    <div class="app-content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Alliance Members</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-people" bgColor="text-bg-primary" title="Total Members"
                              :value="$totalMembers"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-bar-chart-line" bgColor="text-bg-success" title="Total Cities"
                              :value="$totalCities"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-award" bgColor="text-bg-info" title="Average Score"
                              :value="$avgScore"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-graph-up" bgColor="text-bg-warning" title="30-Day Growth"
                              :value="array_sum($cityGrowthHistory)"/>
        </div>
    </div>

    {{-- Charts --}}
    <div class="row mt-4">
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">City Tier Breakdown</div>
                <div class="card-body">
                    <canvas id="cityTierChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">Total Cities (Last 30 Days)</div>
                <div class="card-body">
                    <canvas id="cityGrowthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Member Table --}}
    <div class="card mt-4">
        <div class="card-header">Alliance Members</div>
        <div class="card-body table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead>
                <tr>
                    <th>Leader</th>
                    <th>Score</th>
                    <th>Cities</th>
                    <th>Spies</th>
                    <th>Money</th>
                    <th>Steel</th>
                    <th>Gasoline</th>
                    <th>Aluminum</th>
                    <th>Munitions</th>
                    <th>Uranium</th>
                    <th>Food</th>
                    <th>Military %</th>
                    <th>Timezone</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($members->sortByDesc('score') as $nation)
                    <tr>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $nation['id'] }}" target="_blank">
                                {{ $nation['leader_name'] }}
                            </a>
                        </td>
                        <td>{{ number_format($nation['score'], 2) }}</td>
                        <td>{{ $nation['cities'] }}</td>
                        <td>{{ $nation['spies'] }}</td>

                        {{-- Individual Resource Columns --}}
                        @foreach (['money', 'steel', 'gasoline', 'aluminum', 'munitions', 'uranium', 'food'] as $res)
                            <td>
        <span data-bs-toggle="tooltip"
              data-bs-placement="top"
              title="In Nation: {{ number_format($nation['resources'][$res]['in_game']) }}">
            {{ number_format($nation['resources'][$res]['total']) }}
        </span>
                            </td>
                        @endforeach

                        {{-- Military Percentage with Tooltip --}}
                        <td>
            <span data-bs-toggle="tooltip"
                  title="Soldiers: {{ number_format($nation['military_current']['soldiers']) }}
Tanks: {{ number_format($nation['military_current']['tanks']) }}
Aircraft: {{ number_format($nation['military_current']['aircraft']) }}
Ships: {{ number_format($nation['military_current']['ships']) }}">
                {{ implode('/', [
                    $nation['military_percent']['soldiers'] . '%',
                    $nation['military_percent']['tanks'] . '%',
                    $nation['military_percent']['aircraft'] . '%',
                    $nation['military_percent']['ships'] . '%',
                ]) }}
            </span>
                        </td>

                        <td>UTC {{ $nation['timezone'] }}</td>
                        <td>
                            <a href="{{ route('admin.members.show', $nation['id']) }}"
                               class="btn btn-sm btn-outline-primary">
                                View
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const cityTierCtx = document.getElementById('cityTierChart').getContext('2d');
        const cityGrowthCtx = document.getElementById('cityGrowthChart').getContext('2d');

        new Chart(cityTierCtx, {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($cityTiers)) !!},
                datasets: [{
                    label: 'Members',
                    data: {!! json_encode(array_values($cityTiers)) !!},
                    borderWidth: 1
                }]
            }
        });

        new Chart(cityGrowthCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode(array_keys($cityGrowthHistory)) !!},
                datasets: [{
                    label: 'Total Cities',
                    data: {!! json_encode(array_values($cityGrowthHistory)) !!},
                    fill: false,
                    tension: 0.3
                }]
            }
        });

        // Enable tooltips for military % and resources
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el)
        });
    </script>
@endsection