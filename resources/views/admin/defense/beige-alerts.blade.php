@extends('layouts.admin')

@section('content')
    <x-header title="Beige Alerts" separator>
        <x-slot:subtitle>Track enemy alliances for beige sniping windows, early exits, and next-turn opportunities.</x-slot:subtitle>
    </x-header>

    <x-card title="Alert Settings" class="mb-6">
        <form method="POST" action="{{ route('admin.beige-alerts.settings') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] md:items-end">
            @csrf
            <input type="hidden" name="beige_alerts_enabled" value="0">
            <x-toggle
                id="beige_alerts_enabled"
                label="Enable beige alerts"
                hint="Schedules at :50 on odd hours and :10 on even hours."
                name="beige_alerts_enabled"
                value="1"
                @checked(old('beige_alerts_enabled', $enabled))
            />
            <x-input
                id="beige_alerts_discord_channel_id"
                label="Discord Channel ID"
                name="beige_alerts_discord_channel_id"
                :value="old('beige_alerts_discord_channel_id', $channelId)"
                error-field="beige_alerts_discord_channel_id"
                placeholder="123456789012345678"
            />
            <div class="flex md:justify-end">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>
    </x-card>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Tracked Alliances" :value="number_format($trackedAlliances->count())" icon="o-building-library" color="text-primary" description="Enemy groups monitored for beige windows" class="admin-stat-card admin-stat-card-primary" />
        <x-stat title="Beige Nations" :value="number_format($totalBeigeNations)" icon="o-eye" color="text-info" description="Current targets in beige" class="admin-stat-card admin-stat-card-info" />
        <x-stat title="Leaving Next Turn" :value="number_format($nextTurnLeavers)" icon="o-clock" color="text-warning" :description="'Next turn: ' . $nextTurnChangeAt->format('M d, H:i')" class="admin-stat-card admin-stat-card-warning" />
        <x-stat title="Average Score" :value="number_format($avgScore, 2)" icon="o-scale" color="text-success" description="Average score of tracked beige nations" class="admin-stat-card admin-stat-card-success" />
    </div>

    <x-card title="Beige Turn Breakdown" class="mb-6">
        <div class="flex flex-wrap gap-2">
            @forelse($beigeTurnsBreakdown as $turns => $count)
                <span class="badge badge-ghost">
                    {{ $turns }} turn{{ (int) $turns === 1 ? '' : 's' }}: {{ number_format((int) $count) }}
                </span>
            @empty
                <span class="text-base-content/50 text-sm">No tracked beige nations.</span>
            @endforelse
        </div>
    </x-card>

    <x-card title="Tracked Alliances" class="mb-6">
        <x-slot:menu>
            <form method="POST" action="{{ route('admin.beige-alerts.alliances.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <x-input
                    name="alliance_id"
                    type="number"
                    min="1"
                    error-field="alliance_id"
                    placeholder="Alliance ID"
                    required
                />
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
        </x-slot:menu>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Alliance ID</th>
                    <th>Name</th>
                    <th>Currently Beige</th>
                    <th>Leaving Next Turn</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($trackedAlliances as $trackedAlliance)
                    @php
                        $alliance = $trackedAlliance->alliance;
                    @endphp
                    <tr>
                        <td>{{ $trackedAlliance->alliance_id }}</td>
                        <td>
                            @if($alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}" target="_blank" rel="noopener">
                                    {{ $alliance->name }}
                                </a>
                            @else
                                <span class="text-base-content/50">Unknown Alliance</span>
                            @endif
                        </td>
                        <td>{{ number_format((int) ($beigeCounts[$trackedAlliance->alliance_id] ?? 0)) }}</td>
                        <td>{{ number_format((int) ($nextTurnCounts[$trackedAlliance->alliance_id] ?? 0)) }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('admin.beige-alerts.alliances.destroy', $trackedAlliance) }}" onsubmit="return confirm('Remove this alliance from beige alerts?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-error btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-base-content/50 py-3">
                            No tracked alliances configured.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Tracked Beige Nations" subtitle="Sorted by earliest beige exit.">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm">
                <thead>
                <tr>
                    <th>Leader</th>
                    <th>Nation</th>
                    <th>Alliance</th>
                    <th>Cities</th>
                    <th>Score</th>
                    <th>Beige Turns</th>
                    <th>Estimated Exit</th>
                    <th>Military</th>
                </tr>
                </thead>
                <tbody>
                @forelse($beigeNations as $nation)
                    @php
                        $alliance = $nation->alliance;
                        $military = $nation->military;
                        $estimatedExit = $nextTurnChangeAt->copy()->addHours(max(0, ((int) $nation->beige_turns - 1) * 2));
                    @endphp
                    <tr>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener">
                                {{ $nation->leader_name }}
                            </a>
                        </td>
                        <td>{{ $nation->nation_name }}</td>
                        <td>
                            @if($alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}" target="_blank" rel="noopener">
                                    {{ $alliance->name }}
                                </a>
                            @else
                                <span class="text-base-content/50">No Alliance</span>
                            @endif
                        </td>
                        <td>{{ number_format((int) $nation->num_cities) }}</td>
                        <td>{{ number_format((float) $nation->score, 2) }}</td>
                        <td>
                            <span class="badge {{ (int) $nation->beige_turns === 1 ? 'badge-warning' : 'badge-ghost' }}">
                                {{ (int) $nation->beige_turns }}
                            </span>
                        </td>
                        <td>{{ $estimatedExit->format('M d, H:i') }}</td>
                        <td class="text-sm">
                            @if($military)
                                S: {{ number_format((int) $military->soldiers) }},
                                T: {{ number_format((int) $military->tanks) }},
                                A: {{ number_format((int) $military->aircraft) }},
                                N: {{ number_format((int) $military->ships) }}
                            @else
                                <span class="text-base-content/50">No data</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-base-content/50 py-3">
                            No tracked beige nations found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
