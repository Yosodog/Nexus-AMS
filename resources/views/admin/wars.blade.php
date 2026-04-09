@extends('layouts.admin')

@section("content")
    <x-header title="Ongoing Wars" separator>
        <x-slot:subtitle>Track active conflicts, recent launch tempo, and damage patterns across the alliance battlefield.</x-slot:subtitle>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Ongoing Wars" :value="number_format($stats['total_ongoing'])" icon="o-fire" color="text-error" description="Current active war records" />
        <x-stat title="Wars Last 7 Days" :value="number_format($stats['wars_last_7_days'])" icon="o-calendar-days" color="text-info" description="Recent launches across all tracked alliances" />
        <x-stat title="Avg Duration" :value="number_format((float) $stats['avg_duration'], 1) . ' days'" icon="o-clock" color="text-warning" description="Average age of ongoing wars" />
        <x-stat title="Looted (7 Days)" :value="'$' . number_format((float) $stats['total_looted'], 2)" icon="o-banknotes" color="text-success" description="Recent looted money in tracked wars" />
    </div>

    {{-- Charts --}}
    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)_minmax(280px,1fr)]">
        <x-card title="Wars Started (Last 30 Days)">
            <canvas id="warsLineChart" height="260"></canvas>
        </x-card>
        <x-card title="War Type Distribution">
            <canvas id="warTypePieChart" height="260"></canvas>
        </x-card>
        <x-card title="Top Nations w/ Active Wars">
            <canvas id="topNationsBarChart" height="260"></canvas>
        </x-card>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <x-card title="Resource Usage Breakdown">
            <canvas id="resourceUsageChart" height="260"></canvas>
        </x-card>

        <x-card title="Aggressor vs Defender">
            <canvas id="aggroDefenderChart" height="260"></canvas>
        </x-card>
    </div>

    {{-- Damage Dealt vs Taken --}}
    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($damageBreakdown as $type => $data)
            <x-card :title="ucfirst(str_replace('_', ' ', $type)) . ': Dealt vs Taken'">
                <canvas id="damageChart_{{ $type }}" height="220"></canvas>
            </x-card>
        @endforeach
    </div>

    {{-- Active War by Member --}}
    <div class="mt-4">
        <x-card title="Active Wars by Member">
            <canvas id="warsByNationChart" height="320"></canvas>
        </x-card>
    </div>

    {{-- War Table --}}
    <x-card class="mt-4">
        <x-slot:title>Active Wars</x-slot:title>
        <div class="overflow-x-auto rounded-box border border-base-300">
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
                    <tr @class(['bg-error/10' => $ourResistance !== null && $ourResistance < 20])>
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
    </x-card>
@endsection

@push("scripts")
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
@endpush
