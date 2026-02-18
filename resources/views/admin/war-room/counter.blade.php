@extends('layouts.admin')

@section('content')
    @php
        $aggressor = $counter->aggressor;
        $aggressorMilitary = $aggressor?->military;
    @endphp
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3 align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0">Counter:
                        @if($counter->aggressor)
                            <a href="https://politicsandwar.com/nation/id={{ $counter->aggressor->id }}" target="_blank" rel="noopener noreferrer">{{ $counter->aggressor->leader_name }}</a>
                        @else
                            Unknown Aggressor
                        @endif
                    </h3>
                    <div class="text-muted">Status: <span class="badge text-bg-primary text-uppercase">{{ $counter->status }}</span></div>
                    <div class="small text-muted">Leader names open in Politics &amp; War for quick validation and targeting.</div>
                </div>
                <div class="col-sm-4 text-end">
                    <a href="{{ route('admin.war-room') }}" class="btn btn-outline-secondary btn-sm">Back to War Room</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Counter Overview</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-6">Team Size</dt>
                        <dd class="col-6 text-end"><span class="badge text-bg-info">{{ $counter->team_size }}</span></dd>

                        <dt class="col-6">Aggressor Alliance</dt>
                        <dd class="col-6 text-end">
                            @if($counter->aggressor?->alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $counter->aggressor->alliance->id }}" target="_blank">{{ $counter->aggressor->alliance->name }}</a>
                            @else
                                No Alliance
                            @endif
                        </dd>

                        <dt class="col-6">Last War Declared</dt>
                        <dd class="col-6 text-end">{{ optional($counter->last_war_declared_at)->diffForHumans() ?? '—' }}</dd>

                        <dt class="col-6">Created</dt>
                        <dd class="col-6 text-end">{{ $counter->created_at->diffForHumans() }}</dd>

                        <dt class="col-6">War Type</dt>
                        <dd class="col-6 text-end text-uppercase">
                            {{ config('war.war_types')[strtolower($counter->war_declaration_type ?? '')] ?? ucfirst($counter->war_declaration_type ?? 'Unknown') }}
                        </dd>
                    </dl>
                    <div class="mt-3 small text-muted">
                        <div>Aggressor score {{ number_format($aggressor->score ?? 0, 2) }} • Cities {{ $aggressor->num_cities ?? 0 }}</div>
                        <div>Soldiers {{ number_format(optional($aggressorMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($aggressorMilitary)->tanks ?? 0) }}</div>
                        <div>Aircraft {{ number_format(optional($aggressorMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($aggressorMilitary)->ships ?? 0) }}</div>
                    </div>
                </div>
                <div class="card-footer bg-body-tertiary">
                    <form method="post" action="{{ route('admin.war-counters.update', $counter) }}" class="mb-3">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-6">
                                <label class="form-label mb-1">War Type</label>
                                <select class="form-select form-select-sm" name="war_declaration_type">
                                    @foreach (config('war.war_types') as $value => $label)
                                        <option value="{{ $value }}" @selected(old('war_declaration_type', $counter->war_declaration_type) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Team Size</label>
                                <input type="number" class="form-control form-control-sm" name="team_size" min="1" max="10" value="{{ old('team_size', $counter->team_size) }}">
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-primary w-100 mt-2" type="submit">Save Counter Settings</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-counters.auto-pick', $counter) }}">
                        @csrf
                        <button class="btn btn-outline-secondary w-100 mb-2" type="submit">Auto-Pick Assignments</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-counters.assignments.manual', $counter) }}" class="mb-2">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-8">
                                <label class="form-label mb-1">Manually Add Nation (ID)</label>
                                <input type="number" class="form-control form-control-sm" name="friendly_nation_id" placeholder="e.g. 12345" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label mb-1">Score</label>
                                <input type="number" class="form-control form-control-sm" name="match_score" min="0" max="100" step="0.1" placeholder="auto">
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-success w-100 mt-2" type="submit">Assign & Lock Manually</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-counters.finalize', $counter) }}">
                        @csrf
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="notify_in_game" value="1" id="counterNotifyInGame">
                            <label class="form-check-label" for="counterNotifyInGame">Send in-game message</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="notify_discord" value="1" id="counterNotifyDiscord">
                            <label class="form-check-label" for="counterNotifyDiscord">Queue Discord intent</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_discord_room" value="1" id="counterNotifyRoom">
                            <label class="form-check-label" for="counterNotifyRoom">Suggest Discord room</label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Finalize Counter</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-counters.archive', $counter) }}" class="mt-2">
                        @csrf
                        <button class="btn btn-outline-danger w-100" type="submit">Archive Counter</button>
                    </form>
                </div>
            </div>

            <!-- Aggressor context removed; merged into overview above -->

            <div class="mt-4">
                @include('admin.war-room.partials.score-guide')
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Proposed Assignments</h5>
                    <small class="text-muted">Scores reflect availability, readiness, cohesion.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Friendly Nation</th>
                                <th>Alliance</th>
                                <th>Strength</th>
                                <th>Wars</th>
                                <th>Match Score</th>
                                <th>Status</th>
                                <th>Actions</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($assignments as $assignment)
                                @php
                                    $friendly = $assignment->friendlyNation;
                                    $friendlyMilitary = $friendly?->military;
                                    $collapseId = 'counter-meta-'.$assignment->id;
                                @endphp
                                <tr>
                                    <td>
                                        @if($friendly?->id)
                                            <a href="https://politicsandwar.com/nation/id={{ $friendly->id }}" target="_blank" rel="noopener noreferrer" class="fw-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</a>
                                        @else
                                            <span class="fw-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</span>
                                        @endif
                                        <div class="small text-muted">{{ $friendly->nation_name ?? '—' }}</div>
                                    </td>
                                    <td>{{ $friendly?->alliance?->name ?? '—' }}</td>
                                    <td>
                                        <div class="small">Score {{ number_format($friendly->score ?? 0, 2) }} • Cities {{ $friendly->num_cities ?? 0 }}</div>
                                        <div class="small text-muted">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                        <div class="small text-muted">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars">
                                            {{ $friendly->offensive_wars_count ?? 0 }} / {{ $friendly->defensive_wars_count ?? 0 }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-info">{{ number_format($assignment->match_score, 1) }}</span>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light text-uppercase">{{ $assignment->status }}</span>
                                    </td>
                                    <td>
                                        @if($assignment->status === 'proposed')
                                            <form method="post" action="{{ route('admin.war-counters.assignments.assign', [$counter, $assignment]) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-success me-1" type="submit">Mark Assigned</button>
                                            </form>
                                            <form method="post" action="{{ route('admin.war-counters.assignments.destroy', [$counter, $assignment]) }}" class="d-inline" onsubmit="return confirm('Remove this proposed assignment?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger me-1" type="submit">Remove</button>
                                            </form>
                                        @elseif($assignment->status === 'assigned')
                                            <form method="post" action="{{ route('admin.war-counters.assignments.unassign', [$counter, $assignment]) }}" class="d-inline" onsubmit="return confirm('Revert this assignment back to proposed?')">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-warning me-1" type="submit">Unassign</button>
                                            </form>
                                        @endif
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                                            Details
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="{{ $collapseId }}">
                                    <td colspan="7" class="bg-body-tertiary">
                                        @include('admin.war-room.partials.match-breakdown', ['meta' => $assignment->meta ?? []])
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No assignments proposed yet.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">All Candidate Nations (In Range)</h5>
                            <small class="text-muted">All in-range nations, sorted with recommended options first.</small>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Friendly Nation</th>
                                        <th>Alliance</th>
                                        <th>Strength</th>
                                        <th>Wars</th>
                                        <th>Match Score</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($candidates ?? collect() as $row)
                                        @php $friendly = $row['friendly'] @endphp
                                        @php $friendlyMilitary = $friendly?->military @endphp
                                        <tr>
                                            <td>
                                                @if($friendly?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $friendly->id }}" target="_blank" rel="noopener noreferrer" class="fw-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</a>
                                                @else
                                                    <span class="fw-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</span>
                                                @endif
                                                <div class="small text-muted">{{ $friendly->nation_name ?? '—' }}</div>
                                            </td>
                                            <td>{{ $friendly?->alliance?->name ?? '—' }}</td>
                                            <td>
                                                <div class="small">Score {{ number_format($friendly->score ?? 0, 2) }} • Cities {{ $friendly->num_cities ?? 0 }}</div>
                                                <div class="small text-muted">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                                <div class="small text-muted">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars">
                                                    {{ $friendly->offensive_wars_count ?? 0 }} / {{ $friendly->defensive_wars_count ?? 0 }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge text-bg-info">{{ number_format($row['score'] ?? 0, 1) }}</span>
                                                    <span class="badge {{ ($row['recommended'] ?? false) ? 'text-bg-success' : 'text-bg-secondary' }}">
                                                        {{ ($row['recommended'] ?? false) ? 'Recommended' : 'Manual only' }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <form method="post" action="{{ route('admin.war-counters.assignments.manual', $counter) }}" class="d-inline">
                                                    @csrf
                                                    <input type="hidden" name="friendly_nation_id" value="{{ $friendly->id }}">
                                                    <input type="hidden" name="match_score" value="{{ $row['score'] ?? '' }}">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Assign</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No nations are in war range.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-7">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h5 class="card-title mb-0">War Attacks Involving Enemy Nation</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="px-3 pt-3 small text-muted">
                                Most recent 50 attacks where this aggressor was either the attacker or defender.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-sm mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Time</th>
                                        <th>Role</th>
                                        <th>Attacker</th>
                                        <th>Defender</th>
                                        <th>Type</th>
                                        <th>War</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($enemyWarAttacks as $attack)
                                        <tr>
                                            <td>{{ optional($attack->date)->diffForHumans() }}</td>
                                            <td>
                                                <span class="badge {{ $attack->att_id === $counter->aggressor_nation_id ? 'text-bg-warning' : 'text-bg-info' }}">
                                                    {{ $attack->att_id === $counter->aggressor_nation_id ? 'Attacker' : 'Defender' }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($attack->attacker?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $attack->attacker->id }}" target="_blank" rel="noopener noreferrer">{{ $attack->attacker->leader_name ?? $attack->att_id }}</a>
                                                @else
                                                    {{ $attack->att_id }}
                                                @endif
                                            </td>
                                            <td>
                                                @if($attack->defender?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $attack->defender->id }}" target="_blank" rel="noopener noreferrer">{{ $attack->defender->leader_name ?? $attack->def_id }}</a>
                                                @else
                                                    {{ $attack->def_id }}
                                                @endif
                                            </td>
                                            <td>{{ $attack->type?->name ?? $attack->type }}</td>
                                            <td>
                                                @if($attack->war_id)
                                                    <a href="https://politicsandwar.com/nation/war/timeline/war={{ $attack->war_id }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">Timeline</a>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-3 text-muted">No war attacks recorded for this enemy nation yet.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-5">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Last 30d Wars vs Us</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-sm mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Start</th>
                                        <th>Role</th>
                                        <th>Opponent</th>
                                        <th>Status</th>
                                        <th>Link</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($recentWarsAgainstUs ?? collect() as $war)
                                        @php
                                            $isAggAtt = $war->att_id === $counter->aggressor_nation_id;
                                            $opponent = $isAggAtt ? $war->defender : $war->attacker;
                                            $opAlliance = $opponent?->alliance;
                                        @endphp
                                        <tr>
                                            <td>{{ optional($war->date)->diffForHumans() }}</td>
                                            <td>{{ $isAggAtt ? 'Attacking' : 'Defending' }}</td>
                                            <td>
                                                <div class="fw-semibold">
                                                    @if($opponent?->id)
                                                        <a href="https://politicsandwar.com/nation/id={{ $opponent->id }}" target="_blank" rel="noopener noreferrer">{{ $opponent->leader_name ?? $opponent->id }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </div>
                                                <div class="small text-muted">
                                                    @if($opAlliance)
                                                        <a href="https://politicsandwar.com/alliance/id={{ $opAlliance->id }}" target="_blank" rel="noopener noreferrer">{{ $opAlliance->name }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                @if($war->end_date)
                                                    <span class="badge text-bg-secondary">Ended</span>
                                                @else
                                                    <span class="badge text-bg-success">Ongoing</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">Timeline</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-3 text-muted">No wars in last 30 days.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
