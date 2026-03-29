@extends('layouts.admin')

@section('content')
    {{-- Page Header --}}
    <div class="app-content-header">
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
                              :value="number_format($totalMembers)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-bar-chart-line" bgColor="text-bg-success" title="Total Cities"
                              :value="number_format($totalCities)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-award" bgColor="text-bg-info" title="Average Score"
                              :value="number_format($avgScore, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-graph-up" bgColor="text-bg-warning" title="30-Day Growth"
                              :value="number_format(array_sum($cityGrowthHistory))"/>
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

    @php
        $profitabilityRows = $profitabilityLeaderboard['rows'] ?? [];
    @endphp

    {{-- Member Table --}}
    <div class="card mt-4">
        <div class="card-header">Alliance Members</div>
        <div class="card-body table-responsive">
            <table id="membersTable" class="table table-hover table-striped align-middle w-100">
                <thead class="table-light">
                <tr>
                    <th>Leader</th>
                    <th>Status</th>
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
                @foreach($members as $nation)
                    <tr>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $nation['id'] }}" target="_blank">
                                {{ $nation['leader_name'] }}
                            </a>
                        </td>
                        <td>
                            @if($nation['is_inactive'])
                                <span class="badge text-bg-danger">Inactive</span>
                            @else
                                <span class="badge text-bg-success">Active</span>
                            @endif
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

    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="fw-semibold">Build Profitability</span>
                <div class="text-muted small">Current daily economic output converted with 24-hour average trade prices.</div>
            </div>
            <div class="text-muted small">
                Radiation snapshot:
                {{ optional($profitabilityLeaderboard['radiation_snapshot_at'] ?? null)?->toDateTimeString() ?? 'Unavailable' }}
            </div>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Rank</th>
                    <th>Leader</th>
                    <th>Nation</th>
                    <th>Cities</th>
                    <th class="text-end">Net / Day</th>
                    <th class="text-end">Money</th>
                    <th class="text-end">City Income</th>
                    <th class="text-end">Power Cost</th>
                    <th class="text-end">Food Cost</th>
                    <th class="text-end">Military Upkeep</th>
                </tr>
                </thead>
                <tbody>
                @forelse($profitabilityRows as $row)
                    <tr>
                        <td><span class="badge text-bg-secondary">#{{ $row['rank'] }}</span></td>
                        <td>{{ $row['leader_name'] }}</td>
                        <td>{{ $row['nation_name'] }}</td>
                        <td>{{ number_format($row['cities']) }}</td>
                        <td class="text-end fw-semibold {{ $row['converted_profit_per_day'] >= 0 ? 'text-success' : 'text-danger' }}">
                            ${{ number_format($row['converted_profit_per_day'], 2) }}
                        </td>
                        <td class="text-end">${{ number_format($row['money_profit_per_day'], 2) }}</td>
                        <td class="text-end text-success">${{ number_format($row['city_income_per_day'], 2) }}</td>
                        <td class="text-end text-danger">${{ number_format(abs($row['power_cost_per_day']), 2) }}</td>
                        <td class="text-end text-danger">${{ number_format(abs($row['food_cost_per_day']), 2) }}</td>
                        <td class="text-end text-danger">${{ number_format(abs($row['military_upkeep_per_day']), 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No profitability data available yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Inactivity Settings --}}
    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div>
                <span class="fw-semibold">Inactivity Mode</span>
                <div class="text-muted small">Automated inactivity detection and notifications.</div>
            </div>
            <form action="{{ route('admin.members.inactivity-check') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    Run inactivity check now
                </button>
            </form>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.members.inactivity-settings') }}" method="POST">
                @csrf
                <input type="hidden" name="inactivity_enabled" value="0">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               id="inactivity_enabled"
                                               name="inactivity_enabled"
                                               value="1"
                                               {{ $inactivitySettings['enabled'] ? 'checked' : '' }}>
                                        <label class="form-check-label fw-semibold" for="inactivity_enabled">
                                            Enabled
                                        </label>
                                    </div>
                                    <span class="text-muted small">
                                        When disabled, no new inactivity episodes are created.
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="inactivity_threshold_hours" class="form-label">Threshold (hours)</label>
                                <input type="number"
                                       class="form-control"
                                       id="inactivity_threshold_hours"
                                       name="inactivity_threshold_hours"
                                       min="1"
                                       max="8760"
                                       value="{{ old('inactivity_threshold_hours', $inactivitySettings['threshold_hours']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="inactivity_cooldown_hours" class="form-label">Cooldown (hours)</label>
                                <input type="number"
                                       class="form-control"
                                       id="inactivity_cooldown_hours"
                                       name="inactivity_cooldown_hours"
                                       min="1"
                                       max="8760"
                                       value="{{ old('inactivity_cooldown_hours', $inactivitySettings['cooldown_hours']) }}">
                                <div class="form-text">
                                    Minimum hours between repeat notifications during the same inactivity episode.
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="inactivity_discord_channel_id" class="form-label">Discord Channel ID</label>
                                <input type="text"
                                       class="form-control"
                                       id="inactivity_discord_channel_id"
                                       name="inactivity_discord_channel_id"
                                       value="{{ old('inactivity_discord_channel_id', $inactivitySettings['discord_channel_id']) }}">
                                <div class="form-text">Channel to post inactivity alerts.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="border rounded bg-light p-3 h-100">
                            <div class="fw-semibold mb-2">Actions on Inactivity</div>
                            <div class="d-flex flex-column gap-2">
                                @foreach($inactivityActionOptions as $action)
                                    <div class="form-check">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               id="inactivity_action_{{ $loop->index }}"
                                               name="inactivity_actions[]"
                                               value="{{ $action['value'] }}"
                                               {{ in_array($action['value'], $inactivitySettings['actions'], true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="inactivity_action_{{ $loop->index }}">
                                            {{ $action['label'] }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" class="btn btn-primary">Save Inactivity Settings</button>
                </div>
            </form>

            <hr class="my-4">

            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <h6 class="mb-0">Inactive Nations</h6>
                    <div class="text-muted small">Nations currently in an open inactivity episode.</div>
                </div>
                <span class="text-muted small">
                    {{ $members->where('is_inactive', true)->count() }} inactive
                </span>
            </div>

            <div class="table-responsive mt-3">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Leader</th>
                        <th>Nation</th>
                        <th>Inactive Since</th>
                        <th>Last Active</th>
                        <th>Last Notified</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($members->where('is_inactive', true) as $nation)
                        <tr>
                            <td>{{ $nation['leader_name'] }}</td>
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $nation['id'] }}" target="_blank">
                                    {{ $nation['nation_name'] ?? 'Nation '.$nation['id'] }}
                                </a>
                            </td>
                            <td>{{ optional($nation['inactive_since_at'])->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{{ optional($nation['last_pw_last_active_at'])->format('Y-m-d H:i') ?? '—' }}</td>
                            @php
                                $event = $nation['current_inactivity_event'];
                            @endphp
                            <td>{{ optional($event['last_notified_at'] ?? null)->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">No inactive nations.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
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
                    label: 'Total City',
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

        initAdminDataTable('#membersTable', {
            pageLength: 50,
            order: [[2, 'desc']],
            scrollX: true,
            autoWidth: false,
            language: {
                searchPlaceholder: 'Search members...',
            }
        });
    </script>
@endsection
