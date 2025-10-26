@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Ongoing Wars</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-fire" bgColor="text-bg-danger" title="Ongoing Wars"
                              :value="$stats['total_ongoing']"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-calendar-week" bgColor="text-bg-info" title="Wars Last 7 Days"
                              :value="$stats['wars_last_7_days']"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-clock-history" bgColor="text-bg-warning" title="Avg Duration (days)"
                              :value="$stats['avg_duration']"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash-stack" bgColor="text-bg-success" title="Looted (7 Days)"
                              :value="'$' . number_format($stats['total_looted'], 2)"/>
        </div>
    </div>

    {{-- Charts --}}
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Wars Started (Last 30 Days)</div>
                <div class="card-body">
                    <canvas id="warsLineChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">War Type Distribution</div>
                <div class="card-body">
                    <canvas id="warTypePieChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">Top Nations w/ Active Wars</div>
                <div class="card-body">
                    <canvas id="topNationsBarChart"></canvas>
                </div>
            </div>
        </div>

    </div>
    <div class="row mt-4">
        {{-- Resource Usage Breakdown --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Resource Usage Breakdown</div>
                <div class="card-body">
                    <canvas id="resourceUsageChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Aggressor vs Defender --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Aggressor vs Defender</div>
                <div class="card-body">
                    <canvas id="aggroDefenderChart" style="max-height: 335px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Damage Dealt vs Taken --}}
    <div class="row mt-4">
        @foreach($damageBreakdown as $type => $data)
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header">{{ ucfirst(str_replace('_', ' ', $type)) }}: Dealt vs Taken</div>
                    <div class="card-body">
                        <canvas id="damageChart_{{ $type }}"></canvas>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Active War by Member --}}
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">Active Wars by Member</div>
                <div class="card-body">
                    <canvas id="warsByNationChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- War Table --}}
    <div class="card mt-4">
        <div class="card-header">Active Wars</div>
        <div class="card-body table-responsive">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Attacker</th>
                    <th>Defender</th>
                    <th>Att. Points</th>
                    <th>Def. Points</th>
                    <th>Att. Resistance</th>
                    <th>Def. Resistance</th>
                    <th>Turns Left</th>
                </tr>
                </thead>
                <tbody>
                @php $membershipService = app(\App\Services\AllianceMembershipService::class) @endphp
                @foreach ($wars as $war)
                    @php
                        $isUsAttacker = $membershipService->contains($war->att_alliance_id);
                        $isUsDefender = $membershipService->contains($war->def_alliance_id);
                        $ourResistance = $isUsAttacker ? $war->att_resistance : ($isUsDefender ? $war->def_resistance : null);
                    @endphp
                    <tr @if($ourResistance !== null && $ourResistance < 20) class="table-danger" @endif>
                        <td>
                            <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank">
                                {{ $war->id }}
                            </a>
                        </td>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank">
                                {{ $war->attacker->leader_name ?? 'Unknown' }}
                            </a>
                            @if($war->attacker && $war->attacker->alliance)
                                (<a href="https://politicsandwar.com/alliance/id={{ $war->attacker->alliance->id }}"
                                    target="_blank">
                                    {{ $war->attacker->alliance->name }}
                                </a>)
                            @endif
                        </td>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank">
                                {{ $war->defender->leader_name ?? 'Unknown' }}
                            </a>
                            @if($war->defender && $war->defender->alliance)
                                (<a href="https://politicsandwar.com/alliance/id={{ $war->defender->alliance->id }}"
                                    target="_blank">
                                    {{ $war->defender->alliance->name }}
                                </a>)
                            @endif
                        </td>
                        <td>{{ $war->att_points }}</td>
                        <td>{{ $war->def_points }}</td>
                        <td>{{ $war->att_resistance }}</td>
                        <td>{{ $war->def_resistance }}</td>
                        <td>{{ $war->turns_left }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section("scripts")
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const warsLineChartCtx = document.getElementById('warsLineChart').getContext('2d');
        new Chart(warsLineChartCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode(array_keys($warStartHistory)) !!},
                datasets: [{
                    label: 'War Started',
                    data: {!! json_encode(array_values($warStartHistory)) !!},
                    borderWidth: 2,
                    fill: false,
                    tension: 0.3
                }]
            }
        });

        const warTypePieCtx = document.getElementById('warTypePieChart').getContext('2d');
        new Chart(warTypePieCtx, {
            type: 'pie',
            data: {
                labels: {!! json_encode(array_keys($warTypeDistribution)) !!},
                datasets: [{
                    data: {!! json_encode(array_values($warTypeDistribution)) !!},
                }]
            }
        });

        const topNationsCtx = document.getElementById('topNationsBarChart').getContext('2d');
        new Chart(topNationsCtx, {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($topNations)) !!},
                datasets: [{
                    label: 'Active War',
                    data: {!! json_encode(array_values($topNations)) !!},
                    borderWidth: 1
                }]
            }
        });

        // Resource Usage Breakdown
        new Chart(document.getElementById('resourceUsageChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($resourceUsage)) !!},
                datasets: [{
                    label: 'Used',
                    data: {!! json_encode(array_map(fn($r) => $r['used'], $resourceUsage)) !!},
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {beginAtZero: true}
                }
            }
        });

        // Aggressor vs Defender
        new Chart(document.getElementById('aggroDefenderChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode(array_keys($aggroDefenderSplit)) !!},
                datasets: [{
                    data: {!! json_encode(array_values($aggroDefenderSplit)) !!},
                }]
            }
        });

        // Damage Dealt vs Taken
        @foreach($damageBreakdown as $type => $data)
        new Chart(document.getElementById('damageChart_{{ $type }}').getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Dealt', 'Taken'],
                datasets: [{
                    label: '{{ ucfirst(str_replace('_', ' ', $type)) }}',
                    data: [{{ $data['dealt'] }}, {{ $data['taken'] }}],
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {beginAtZero: true}
                }
            }
        });
        @endforeach

        // Active War by Member
        new Chart(document.getElementById('warsByNationChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($warsByNation)) !!},
                datasets: [{
                    label: 'Active War',
                    data: {!! json_encode(array_values($warsByNation)) !!},
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: {beginAtZero: true}
                }
            }
        });
    </script>
@endsection
