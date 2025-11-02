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
                    <h3 class="mb-0">Counter: {{ $counter->aggressor->leader_name ?? 'Unknown Aggressor' }}</h3>
                    <div class="text-muted">Status: <span class="badge text-bg-primary text-uppercase">{{ $counter->status }}</span></div>
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
                        <dd class="col-6 text-end">{{ $counter->aggressor->alliance->name ?? 'No Alliance' }}</dd>

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

            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="card-title mb-0">Aggressor Context</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <li><strong>Nation:</strong> {{ $aggressor->nation_name ?? '—' }}</li>
                        <li><strong>Score / Cities:</strong> {{ number_format($aggressor->score ?? 0, 2) }} • {{ $aggressor->num_cities ?? '—' }}</li>
                        <li><strong>Ground Strength:</strong> Soldiers {{ number_format(optional($aggressorMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($aggressorMilitary)->tanks ?? 0) }}</li>
                        <li><strong>Air &amp; Naval:</strong> Aircraft {{ number_format(optional($aggressorMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($aggressorMilitary)->ships ?? 0) }}</li>
                        <li><strong>Wars:</strong> {{ $aggressor->wars_won ?? '—' }} won / {{ $aggressor->wars_lost ?? '—' }} lost</li>
                    </ul>
                </div>
            </div>

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
                                <th>Details</th>
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
                                        <span class="fw-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</span>
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
                                    <td><span class="badge text-bg-light text-uppercase">{{ $assignment->status }}</span></td>
                                    <td>
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
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Live Attacks</h5>
                        </div>
                        <div class="card-body">
                            <form class="d-flex mb-3" method="get">
                                <input type="number" class="form-control form-control-sm me-2" name="minutes" value="{{ request('minutes') }}" placeholder="Window (min)">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
                            </form>
                            <div class="table-responsive">
                                <table class="table table-striped table-sm mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Time</th>
                                        <th>Opponent</th>
                                        <th>Type</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($liveFeed as $attack)
                                        <tr>
                                            <td>{{ optional($attack->date)->diffForHumans() }}</td>
                                            <td>{{ $attack->att_id === $counter->aggressor_nation_id ? ($attack->defender->leader_name ?? $attack->def_id) : ($attack->attacker->leader_name ?? $attack->att_id) }}</td>
                                            <td>{{ $attack->type?->name ?? $attack->type }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center py-3 text-muted">No attacks logged in window.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Recent Aggressor Activity</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                @forelse($recentAggressorAttacks as $attack)
                                    <li class="list-group-item">
                                        <div class="fw-semibold">{{ optional($attack->date)->diffForHumans() }}</div>
                                        <div class="small text-muted">vs {{ $attack->att_id === $counter->aggressor_nation_id ? ($attack->defender->leader_name ?? $attack->def_id) : ($attack->attacker->leader_name ?? $attack->att_id) }} — {{ $attack->type?->name ?? $attack->type }}</div>
                                    </li>
                                @empty
                                    <li class="list-group-item text-muted">No recent activity recorded.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
