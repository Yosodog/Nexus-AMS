@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Beige Alerts</h3>
                    <div class="text-muted small">
                        Tracks enemy alliances for beige sniping windows and early beige exits.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Alert Settings</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.beige-alerts.settings') }}">
                @csrf
                <input type="hidden" name="beige_alerts_enabled" value="0">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="beige_alerts_enabled"
                                   name="beige_alerts_enabled" value="1" @checked(old('beige_alerts_enabled', $enabled))>
                            <label class="form-check-label" for="beige_alerts_enabled">
                                Enable beige alerts
                            </label>
                        </div>
                        <div class="form-text">Schedules at `:50` odd hours and `:10` even hours.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="beige_alerts_discord_channel_id" class="form-label">Discord Channel ID</label>
                        <input type="text"
                               id="beige_alerts_discord_channel_id"
                               name="beige_alerts_discord_channel_id"
                               class="form-control @error('beige_alerts_discord_channel_id') is-invalid @enderror"
                               value="{{ old('beige_alerts_discord_channel_id', $channelId) }}"
                               placeholder="123456789012345678">
                        @error('beige_alerts_discord_channel_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3 text-md-end">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row mt-4 g-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Tracked Alliances</div>
                    <div class="fs-4 fw-semibold">{{ number_format($trackedAlliances->count()) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Beige Nations</div>
                    <div class="fs-4 fw-semibold">{{ number_format($totalBeigeNations) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Leaving Next Turn</div>
                    <div class="fs-4 fw-semibold text-warning">{{ number_format($nextTurnLeavers) }}</div>
                    <div class="small text-muted">
                        Next turn: {{ $nextTurnChangeAt->format('M d, H:i') }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Average Score</div>
                    <div class="fs-4 fw-semibold">{{ number_format($avgScore, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            Beige Turn Breakdown
        </div>
        <div class="card-body d-flex flex-wrap gap-2">
            @forelse($beigeTurnsBreakdown as $turns => $count)
                <span class="badge text-bg-secondary">
                    {{ $turns }} turn{{ (int) $turns === 1 ? '' : 's' }}: {{ number_format((int) $count) }}
                </span>
            @empty
                <span class="text-muted small">No tracked beige nations.</span>
            @endforelse
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Tracked Alliances</span>
            <form method="POST" action="{{ route('admin.beige-alerts.alliances.store') }}" class="d-flex gap-2 align-items-center">
                @csrf
                <input type="number"
                       name="alliance_id"
                       class="form-control @error('alliance_id') is-invalid @enderror"
                       placeholder="Alliance ID"
                       min="1"
                       required>
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead>
                <tr>
                    <th>Alliance ID</th>
                    <th>Name</th>
                    <th>Currently Beige</th>
                    <th>Leaving Next Turn</th>
                    <th class="text-end">Actions</th>
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
                                <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}" target="_blank">
                                    {{ $alliance->name }}
                                </a>
                            @else
                                <span class="text-muted">Unknown Alliance</span>
                            @endif
                        </td>
                        <td>{{ number_format((int) ($beigeCounts[$trackedAlliance->alliance_id] ?? 0)) }}</td>
                        <td>{{ number_format((int) ($nextTurnCounts[$trackedAlliance->alliance_id] ?? 0)) }}</td>
                        <td class="text-end">
                            <form method="POST"
                                  action="{{ route('admin.beige-alerts.alliances.destroy', $trackedAlliance) }}"
                                  onsubmit="return confirm('Remove this alliance from beige alerts?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">
                            No tracked alliances configured.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Tracked Beige Nations</span>
            <span class="text-muted small">Sorted by earliest beige exit</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
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
                            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank">
                                {{ $nation->leader_name }}
                            </a>
                        </td>
                        <td>{{ $nation->nation_name }}</td>
                        <td>
                            @if($alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}" target="_blank">
                                    {{ $alliance->name }}
                                </a>
                            @else
                                <span class="text-muted">No Alliance</span>
                            @endif
                        </td>
                        <td>{{ number_format((int) $nation->num_cities) }}</td>
                        <td>{{ number_format((float) $nation->score, 2) }}</td>
                        <td>
                            <span class="badge {{ (int) $nation->beige_turns === 1 ? 'text-bg-warning' : 'text-bg-secondary' }}">
                                {{ (int) $nation->beige_turns }}
                            </span>
                        </td>
                        <td>{{ $estimatedExit->format('M d, H:i') }}</td>
                        <td class="small">
                            @if($military)
                                S: {{ number_format((int) $military->soldiers) }},
                                T: {{ number_format((int) $military->tanks) }},
                                A: {{ number_format((int) $military->aircraft) }},
                                N: {{ number_format((int) $military->ships) }}
                            @else
                                <span class="text-muted">No data</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-3">
                            No tracked beige nations found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
