@extends('layouts.admin')

@section('content')
    <x-header title="Alliance Members" separator>
        <x-slot:actions>
            <x-stat title="Members" :value="number_format($totalMembers)" icon="o-users" color="text-primary" class="border border-base-300 rounded-box px-4 py-2" />
            <x-stat title="Cities" :value="number_format($totalCities)" icon="o-building-office-2" color="text-success" class="border border-base-300 rounded-box px-4 py-2" />
            <x-stat title="Avg Score" :value="number_format($avgScore, 2)" icon="o-star" color="text-info" class="border border-base-300 rounded-box px-4 py-2" />
            <x-stat title="30d Growth" :value="number_format(array_sum($cityGrowthHistory))" icon="o-arrow-trending-up" color="text-warning" class="border border-base-300 rounded-box px-4 py-2" />
        </x-slot:actions>
    </x-header>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <x-card title="City Tier Breakdown">
            <canvas id="cityTierChart" class="max-h-64"></canvas>
        </x-card>
        <x-card title="Total Cities (Last 30 Days)">
            <canvas id="cityGrowthChart" class="max-h-64"></canvas>
        </x-card>
    </div>

    @php
        $profitabilityRows = $profitabilityLeaderboard['rows'] ?? [];
        $profitabilityRadiationSnapshotAt = filled($profitabilityLeaderboard['radiation_snapshot_at'] ?? null)
            ? \Illuminate\Support\Carbon::parse($profitabilityLeaderboard['radiation_snapshot_at'])->toDateTimeString()
            : 'Unavailable';
    @endphp

    {{-- Member Table --}}
    <x-card title="Alliance Members" class="mb-6" x-data="{ search: '' }">
        <x-slot:menu>
            <x-input placeholder="Search members..." x-model="search" icon="o-magnifying-glass" class="input-sm w-64" clearable />
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-base-content/60">
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
                        <tr x-show="!search || '{{ strtolower($nation['leader_name']) }}'.includes(search.toLowerCase())">
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $nation['id'] }}" target="_blank"
                                   class="link link-primary font-medium">
                                    {{ $nation['leader_name'] }}
                                </a>
                            </td>
                            <td>
                                @if($nation['is_inactive'])
                                    <x-badge label="Inactive" class="badge-error badge-sm" />
                                @else
                                    <x-badge label="Active" class="badge-success badge-sm" />
                                @endif
                            </td>
                            <td>{{ number_format($nation['score'], 2) }}</td>
                            <td>{{ $nation['cities'] }}</td>
                            <td>{{ $nation['spies'] }}</td>

                            @foreach (['money', 'steel', 'gasoline', 'aluminum', 'munitions', 'uranium', 'food'] as $res)
                                <td>
                                    <span class="tooltip"
                                          data-tip="In Nation: {{ number_format($nation['resources'][$res]['in_game']) }}">
                                        {{ number_format($nation['resources'][$res]['total']) }}
                                    </span>
                                </td>
                            @endforeach

                            <td>
                                <span class="tooltip" data-tip="Soldiers: {{ number_format($nation['military_current']['soldiers']) }} | Tanks: {{ number_format($nation['military_current']['tanks']) }} | Aircraft: {{ number_format($nation['military_current']['aircraft']) }} | Ships: {{ number_format($nation['military_current']['ships']) }}">
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
                                <a href="{{ route('admin.members.show', $nation['id']) }}">
                                    <x-button label="View" icon="o-eye" class="btn-xs btn-ghost" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Build Profitability --}}
    <x-card class="mb-6">
        <x-slot:title>
            <div>
                Build Profitability
                <div class="text-sm font-normal text-base-content/50">Current daily economic output converted with 24-hour average trade prices.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <span class="text-sm text-base-content/50">Radiation snapshot: {{ $profitabilityRadiationSnapshotAt }}</span>
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Rank</th>
                        <th>Leader</th>
                        <th>Nation</th>
                        <th>Cities</th>
                        <th class="text-right">Net / Day</th>
                        <th class="text-right">Money</th>
                        <th class="text-right">City Income</th>
                        <th class="text-right">Power Cost</th>
                        <th class="text-right">Food Cost</th>
                        <th class="text-right">Military Upkeep</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profitabilityRows as $row)
                        <tr>
                            <td><x-badge label="#{{ $row['rank'] }}" class="badge-ghost badge-sm" /></td>
                            <td>{{ $row['leader_name'] }}</td>
                            <td>{{ $row['nation_name'] }}</td>
                            <td>{{ number_format($row['cities']) }}</td>
                            <td class="text-right font-semibold {{ $row['converted_profit_per_day'] >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($row['converted_profit_per_day'], 2) }}
                            </td>
                            <td class="text-right">${{ number_format($row['money_profit_per_day'], 2) }}</td>
                            <td class="text-right text-success">${{ number_format($row['city_income_per_day'], 2) }}</td>
                            <td class="text-right text-error">${{ number_format(abs($row['power_cost_per_day']), 2) }}</td>
                            <td class="text-right text-error">${{ number_format(abs($row['food_cost_per_day']), 2) }}</td>
                            <td class="text-right text-error">${{ number_format(abs($row['military_upkeep_per_day']), 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-base-content/50 py-6">No profitability data available yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Inactivity Settings --}}
    <x-card class="mb-6">
        <x-slot:title>
            <div>
                Inactivity Mode
                <div class="text-sm font-normal text-base-content/50">Automated inactivity detection and notifications.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <form action="{{ route('admin.members.inactivity-check') }}" method="POST">
                @csrf
                <x-button label="Run Check Now" icon="o-arrow-path" type="submit" class="btn-sm btn-outline btn-primary" />
            </form>
        </x-slot:menu>

        <form action="{{ route('admin.members.inactivity-settings') }}" method="POST">
            @csrf
            <input type="hidden" name="inactivity_enabled" value="0">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <input type="checkbox"
                               id="inactivity_enabled"
                               name="inactivity_enabled"
                               value="1"
                               class="toggle toggle-primary"
                               {{ $inactivitySettings['enabled'] ? 'checked' : '' }}>
                        <label for="inactivity_enabled" class="font-semibold cursor-pointer">Enabled</label>
                        <span class="text-sm text-base-content/50">When disabled, no new inactivity episodes are created.</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <x-input label="Threshold (hours)" type="number" name="inactivity_threshold_hours"
                                     min="1" max="8760"
                                     value="{{ old('inactivity_threshold_hours', $inactivitySettings['threshold_hours']) }}" />
                        </div>
                        <div>
                            <x-input label="Cooldown (hours)" type="number" name="inactivity_cooldown_hours"
                                     min="1" max="8760"
                                     value="{{ old('inactivity_cooldown_hours', $inactivitySettings['cooldown_hours']) }}"
                                     hint="Minimum hours between repeat notifications during the same episode." />
                        </div>
                        <div>
                            <x-input label="Discord Channel ID" name="inactivity_discord_channel_id"
                                     value="{{ old('inactivity_discord_channel_id', $inactivitySettings['discord_channel_id']) }}"
                                     hint="Channel to post inactivity alerts." />
                        </div>
                    </div>
                </div>
                <div class="bg-base-200 rounded-box p-4">
                    <div class="font-semibold mb-3">Actions on Inactivity</div>
                    <div class="space-y-2">
                        @foreach($inactivityActionOptions as $action)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       name="inactivity_actions[]"
                                       value="{{ $action['value'] }}"
                                       class="checkbox checkbox-sm checkbox-primary"
                                       {{ in_array($action['value'], $inactivitySettings['actions'], true) ? 'checked' : '' }}>
                                <span class="text-sm">{{ $action['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="flex justify-end mt-4">
                <x-button label="Save Settings" type="submit" icon="o-check" class="btn-primary" />
            </div>
        </form>

        <div class="divider"></div>

        <div class="flex items-center justify-between mb-3">
            <div>
                <div class="font-semibold">Inactive Nations</div>
                <div class="text-sm text-base-content/50">Nations currently in an open inactivity episode.</div>
            </div>
            <x-badge label="{{ $members->where('is_inactive', true)->count() }} inactive" class="badge-error" />
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-base-content/60">
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
                                <a href="https://politicsandwar.com/nation/id={{ $nation['id'] }}" target="_blank"
                                   class="link link-primary">
                                    {{ $nation['nation_name'] ?? 'Nation '.$nation['id'] }}
                                </a>
                            </td>
                            <td>{{ optional($nation['inactive_since_at'])->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{{ optional($nation['last_pw_last_active_at'])->format('Y-m-d H:i') ?? '—' }}</td>
                            @php $event = $nation['current_inactivity_event']; @endphp
                            <td>{{ optional($event['last_notified_at'] ?? null)->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-base-content/50 py-4">No inactive nations.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        new Chart(document.getElementById('cityTierChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_keys($cityTiers)) !!},
                datasets: [{ label: 'Members', data: {!! json_encode(array_values($cityTiers)) !!}, borderWidth: 1 }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });

        new Chart(document.getElementById('cityGrowthChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: {!! json_encode(array_keys($cityGrowthHistory)) !!},
                datasets: [{ label: 'Total Cities', data: {!! json_encode(array_values($cityGrowthHistory)) !!}, fill: false, tension: 0.3 }]
            },
            options: { responsive: true }
        });
    </script>
@endpush
