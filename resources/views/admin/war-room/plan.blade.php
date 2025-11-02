@extends('layouts.admin')

@section('content')
    <datalist id="friendly-options-{{ $plan->id }}">
        @foreach ($allFriendlies as $friendly)
            <option value="{{ $friendly->id }}">{{ $friendly->leader_name }} ({{ $friendly->nation_name }})</option>
        @endforeach
    </datalist>

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3 align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0">War Plan: {{ $plan->name }}</h3>
                    <div class="text-muted">
                        Status:
                        <span class="badge text-bg-primary text-uppercase">{{ $plan->status }}</span>
                        <span class="ms-2" data-bs-toggle="tooltip"
                              title="Plan type drives the default war declaration when our members engage targets.">
                            <i class="bi bi-info-circle"></i>
                        </span>
                    </div>
                </div>
                <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
                    <a href="{{ route('admin.war-room') }}" class="btn btn-outline-secondary btn-sm">Back to War Room</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xxl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Plan Options</h5>
                    <span data-bs-toggle="tooltip" title="Baseline settings that influence automation heuristics.">
                        <i class="bi bi-question-circle"></i>
                    </span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-6">Plan Type</dt>
                        <dd class="col-6 text-end text-uppercase">
                            {{ $warTypes[strtolower($plan->plan_type ?? '')] ?? ucfirst($plan->plan_type ?? 'Unknown') }}
                        </dd>

                        <dt class="col-7">
                            Preferred / Target
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip"
                               title="How many of our nations should be mapped to each enemy by default."></i>
                        </dt>
                        <dd class="col-5 text-end">{{ $plan->preferred_nations_per_target }}</dd>

                        <dt class="col-7">
                            Max Squad Size
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip"
                               title="Upper bound when forming automatic strike squads."></i>
                        </dt>
                        <dd class="col-5 text-end">{{ $plan->max_squad_size }}</dd>

                        <dt class="col-7">
                            Cohesion tolerance
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip"
                               title="Tolerance (±) that keeps squads aligned on readiness."></i>
                        </dt>
                        <dd class="col-5 text-end">±{{ $plan->squad_cohesion_tolerance }}</dd>

                        <dt class="col-7">
                            Activity window
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip"
                               title="Recent activity (hours) factored into Target Priority Score."></i>
                        </dt>
                        <dd class="col-5 text-end">{{ $plan->activity_window_hours }}h</dd>

                        <dt class="col-7">Suppress counters</dt>
                        <dd class="col-5 text-end">
                            @if ($plan->suppress_counters_when_active)
                                <span class="badge text-bg-success">Enabled</span>
                            @else
                                <span class="badge text-bg-secondary">Disabled</span>
                            @endif
                        </dd>
                    </dl>
                </div>
                <div class="card-footer bg-body-tertiary">
                    <form method="post" action="{{ route('admin.war-plans.update', $plan) }}" class="row g-2">
                        @csrf
                        @method('PUT')

                        <div class="col-12">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $plan->name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Plan type</label>
                            <select name="plan_type" class="form-select" data-bs-toggle="tooltip"
                                    title="Default declaration applied to new targets.">
                                @foreach ($warTypes as $key => $label)
                                    <option value="{{ $key }}" @selected(old('plan_type', $plan->plan_type) === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred / target</label>
                            <input type="number" name="preferred_nations_per_target" class="form-control" min="1" max="10"
                                   value="{{ old('preferred_nations_per_target', $plan->preferred_nations_per_target) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max squad size</label>
                            <input type="number" name="max_squad_size" class="form-control" min="1" max="10"
                                   value="{{ old('max_squad_size', $plan->max_squad_size) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cohesion (±)</label>
                            <input type="number" name="squad_cohesion_tolerance" class="form-control" min="1" max="50"
                                   value="{{ old('squad_cohesion_tolerance', $plan->squad_cohesion_tolerance) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Activity window (h)</label>
                            <input type="number" name="activity_window_hours" class="form-control" min="12" max="240"
                                   value="{{ old('activity_window_hours', $plan->activity_window_hours) }}">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="suppress_counters_when_active" value="1"
                                       {{ old('suppress_counters_when_active', $plan->suppress_counters_when_active) ? 'checked' : '' }}>
                                <label class="form-check-label">Suppress counters</label>
                            </div>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-sm btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
        </div>
    </div>

        <div class="col-12 col-xxl-4">
            @include('admin.war-room.partials.score-guide')
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Alliances</h5>
                    <span data-bs-toggle="tooltip" title="Friendly alliances supply our assignment pool. Enemy alliances seed targets.">
                        <i class="bi bi-info-circle"></i>
                    </span>
                </div>
                <div class="card-body">
                    <h6 class="fw-semibold">Friendly</h6>
                    <ul class="list-unstyled mb-3">
                        @forelse ($plan->friendlyAlliances as $alliance)
                            <li class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-2">
                                <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-link btn-sm text-danger" type="submit" data-bs-toggle="tooltip" title="Remove">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                            </li>
                        @empty
                            <li class="text-muted">Using alliance membership defaults.</li>
                        @endforelse
                    </ul>

                    <h6 class="fw-semibold">Enemy</h6>
                    <ul class="list-unstyled mb-0">
                        @forelse ($plan->enemyAlliances as $alliance)
                            <li class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-2">
                                <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-link btn-sm text-danger" type="submit" data-bs-toggle="tooltip" title="Remove">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                            </li>
                        @empty
                            <li class="text-muted">Add enemy alliances to drive target discovery.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="card-footer bg-body-tertiary">
                    <form method="post" action="{{ route('admin.war-plans.alliances.store', $plan) }}" class="row g-2">
                        @csrf
                        <div class="col-6">
                            <label class="form-label">Alliance ID</label>
                            <input type="number" min="1" class="form-control" name="alliance_id" placeholder="1234" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <option value="friendly">Friendly</option>
                                <option value="enemy">Enemy</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">Add alliance</button>
                        </div>
                        <div class="col-12">
                            <p class="small text-muted mb-0">Alliance IDs appear in PW URLs (e.g. <code>.../alliance/id=1234</code>).</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">Automation &amp; Notifications</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <form method="post" action="{{ route('admin.war-plans.recompute', $plan) }}">
                            @csrf
                            <button class="btn btn-outline-secondary w-100" type="submit" data-bs-toggle="tooltip"
                                    title="Refresh Target Priority Scores using the latest intelligence.">Recompute priorities</button>
                        </form>
                        <form method="post" action="{{ route('admin.war-plans.auto-assign', $plan) }}">
                            @csrf
                            <button class="btn btn-outline-secondary w-100" type="submit" data-bs-toggle="tooltip"
                                    title="Regenerate assignments for unlocked slots.">Auto-generate assignments</button>
                        </form>
                        <form method="post" action="{{ route('admin.war-plans.activate', $plan) }}">
                            @csrf
                            <button class="btn btn-outline-success w-100" type="submit">Activate plan</button>
                        </form>
                        <form method="post" action="{{ route('admin.war-plans.archive', $plan) }}">
                            @csrf
                            <button class="btn btn-outline-danger w-100" type="submit">Archive plan</button>
                        </form>
                    </div>
                </div>
                <div class="card-footer bg-body-tertiary">
                    <form method="post" action="{{ route('admin.war-plans.publish', $plan) }}">
                        @csrf
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="notify_in_game" value="1" id="notifyInGame">
                            <label class="form-check-label" for="notifyInGame">Send in-game mail</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="notify_discord" value="1" id="notifyDiscord">
                            <label class="form-check-label" for="notifyDiscord">Queue Discord intents</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_discord_room" value="1" id="notifyDiscordRoom">
                            <label class="form-check-label" for="notifyDiscordRoom">Suggest Discord war room</label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Publish assignments</button>
                        <div class="d-flex gap-2 mt-3">
                            <a href="{{ route('admin.war-plans.export', $plan) }}" class="btn btn-outline-primary w-50">Export</a>
                            <button class="btn btn-outline-secondary w-50" type="button" data-bs-toggle="modal" data-bs-target="#importPlanModal">Import</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                    <div>
                        <h5 class="card-title mb-0">Targets</h5>
                        <small class="text-muted">TPS = Target Priority Score. Hover the badge to inspect factor breakdowns.</small>
                    </div>
                    <form class="ms-lg-auto d-flex flex-wrap gap-2" method="post" action="{{ route('admin.war-plans.targets.store', $plan) }}">
                        @csrf
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" data-bs-toggle="tooltip" title="Nation ID pulled from PW profile">Nation ID</span>
                            <input type="number" class="form-control" name="nation_id" min="1" placeholder="e.g. 123456" required>
                        </div>
                        <select name="preferred_war_type" class="form-select form-select-sm">
                            <option value="">War type (default {{ strtoupper($plan->plan_type) }})</option>
                            @foreach ($warTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Add target</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Enemy</th>
                                <th>Alliance</th>
                                <th>TPS</th>
                                <th>Assigned</th>
                                <th>War type</th>
                                <th>Recent activity</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($targets as $target)
                                @php
                                    $candidateList = $topCandidates[$target->id] ?? [];
                                @endphp
                                @php
                                    $enemyNation = $target->nation;
                                    $enemyAlliance = $enemyNation?->alliance;
                                    $enemyMilitary = $enemyNation?->military;
                                    $assignedCount = $target->assignments_count ?? $target->assignments->count();
                                    $preferredSlots = max(1, $plan->preferred_nations_per_target);
                                    $assignedBadgeClass = $assignedCount >= $preferredSlots ? 'text-bg-danger' : 'text-bg-success';
                                @endphp
                                <tr>
                                    <td>
                                        @if ($enemyNation)
                                            <a href="https://politicsandwar.com/nation/id={{ $enemyNation->id }}" target="_blank" class="fw-semibold">
                                                {{ $enemyNation->leader_name }}
                                            </a>
                                            <div class="small text-muted">{{ $enemyNation->nation_name }}</div>
                                            <div class="small">Score {{ number_format($enemyNation->score ?? 0, 2) }} • Cities {{ $enemyNation->num_cities ?? 0 }}</div>
                                            <div class="small text-muted">Soldiers {{ number_format(optional($enemyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($enemyMilitary)->tanks ?? 0) }}</div>
                                            <div class="small text-muted">Aircraft {{ number_format(optional($enemyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($enemyMilitary)->ships ?? 0) }}</div>
                                        @else
                                            <span class="fw-semibold text-muted">Unknown</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($enemyAlliance)
                                            <a href="https://politicsandwar.com/alliance/id={{ $enemyAlliance->id }}" target="_blank">
                                                {{ $enemyAlliance->name }}
                                            </a>
                                            <div class="small text-muted">{{ $enemyAlliance->acronym }}</div>
                                        @else
                                            <span class="text-muted">No alliance</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge text-bg-danger" data-bs-toggle="tooltip"
                                              title="Stored TPS meta">{{ number_format($target->target_priority_score ?? 0, 1) }}</span>
                                    </td>
                                    <td>
                                        <span class="badge {{ $assignedBadgeClass }}" data-bs-toggle="tooltip"
                                              title="Assigned friendlies / preferred slots">
                                            {{ $assignedCount }} / {{ $preferredSlots }}
                                        </span>
                                    </td>
                                    <td style="width: 180px;">
                                        <form method="post" action="{{ route('admin.war-plans.targets.update-war-type', [$plan, $target]) }}">
                                            @csrf
                                            <select class="form-select form-select-sm" name="preferred_war_type" onchange="this.form.submit()">
                                                @foreach ($warTypes as $value => $label)
                                                    <option value="{{ $value }}" @selected($target->preferred_war_type === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    </td>
                                    <td>{{ optional($target->nation->latestSignIn?->created_at)->diffForHumans() ?? 'Unknown' }}</td>
                                    <td class="text-end">
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#meta-{{ $target->id }}">
                                                Meta
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#assignTargetModal{{ $target->id }}">
                                                Assign
                                            </button>
                                            <form method="post" action="{{ route('admin.war-plans.targets.destroy', [$plan, $target]) }}"
                                                  onsubmit="return confirm('Remove this target from the plan?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="collapse" id="meta-{{ $target->id }}">
                                    <td colspan="7" class="bg-body-tertiary">
                                        <pre class="mb-0 small text-muted">{{ json_encode($target->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </td>
                                </tr>

                                @push('modals')
                                    <div class="modal fade" id="assignTargetModal{{ $target->id }}" tabindex="-1"
                                         aria-labelledby="assignTargetModalLabel{{ $target->id }}" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="assignTargetModalLabel{{ $target->id }}">
                                                        Manual assignments — {{ $target->nation->leader_name ?? 'Nation #'.$target->nation_id }}
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <h6 class="fw-semibold">Suggested friendlies</h6>
                                                    <p class="small text-muted">Top recommendations based on availability, load, and match score.</p>
                                                    <div class="table-responsive mb-3">
                                                        <table class="table table-sm align-middle">
                                                            <thead class="table-light">
                                                            <tr>
                                                                <th>Friendly</th>
                                                                <th>Alliance</th>
                                                                <th>Strength</th>
                                                                <th>Wars</th>
                                                                <th>Match score</th>
                                                                <th>Assignments</th>
                                                                <th>Open slots</th>
                                                                <th class="text-end">Action</th>
                                                            </tr>
                                                            </thead>
                                                            <tbody>
                                                            @forelse ($candidateList as $candidate)
                                                                @php
                                                                    $friendlyNation = $candidate['friendly'];
                                                                    $friendlyMilitary = $friendlyNation->military;
                                                                    $matchMeta = $candidate['match_meta'] ?? [];
                                                                    $collapseId = 'candidate-meta-'.$target->id.'-'.$friendlyNation->id;
                                                                @endphp
                                                                <tr>
                                                                    <td>
                                                                        <span class="fw-semibold">{{ $friendlyNation->leader_name }}</span>
                                                                        <div class="small text-muted">{{ $friendlyNation->nation_name }}</div>
                                                                    </td>
                                                                    <td>
                                                                        @if ($friendlyNation->alliance)
                                                                            <a href="https://politicsandwar.com/alliance/id={{ $friendlyNation->alliance->id }}" target="_blank">
                                                                                {{ $friendlyNation->alliance->name }}
                                                                            </a>
                                                                            <div class="small text-muted">{{ $friendlyNation->alliance->acronym }}</div>
                                                                        @else
                                                                            <span class="text-muted">No alliance</span>
                                                                        @endif
                                                                    </td>
                                                                    <td>
                                                                        <div class="small">Score {{ number_format($friendlyNation->score ?? 0, 2) }} • Cities {{ $friendlyNation->num_cities ?? 0 }}</div>
                                                                        <div class="small text-muted">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                                                        <div class="small text-muted">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars">
                                                                            {{ $friendlyNation->offensive_wars_count ?? 0 }} / {{ $friendlyNation->defensive_wars_count ?? 0 }}
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                                                            <span class="badge text-bg-info">{{ number_format($candidate['score'], 1) }}</span>
                                                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                                                                                Details
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                    <td>{{ $candidate['assignment_load'] }} / {{ $candidate['max_assignments'] }}</td>
                                                                    <td>{{ $candidate['available_slots'] }}</td>
                                                                    <td class="text-end">
                                                                        <form method="post" action="{{ route('admin.war-plans.assignments.manual', $plan) }}">
                                                                            @csrf
                                                                            <input type="hidden" name="war_plan_target_id" value="{{ $target->id }}">
                                                                            <input type="hidden" name="friendly_nation_id" value="{{ $friendlyNation->id }}">
                                                                            <input type="hidden" name="match_score" value="{{ $candidate['score'] }}">
                                                                            <button type="submit" class="btn btn-sm btn-outline-primary">Assign</button>
                                                                        </form>
                                                                    </td>
                                                                </tr>
                                                                <tr class="collapse" id="{{ $collapseId }}">
                                                                    <td colspan="8" class="bg-body-tertiary">
                                                                        @include('admin.war-room.partials.match-breakdown', ['meta' => $matchMeta])
                                                                    </td>
                                                                </tr>
                                                            @empty
                                                                <tr>
                                                                    <td colspan="8" class="text-center text-muted py-3">No recommended friendlies right now.</td>
                                                                </tr>
                                                            @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <hr>
                                                    <h6 class="fw-semibold">Manual assignment</h6>
                                                    <form method="post" action="{{ route('admin.war-plans.assignments.manual', $plan) }}" class="row g-2 align-items-end">
                                                        @csrf
                                                        <input type="hidden" name="war_plan_target_id" value="{{ $target->id }}">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Friendly nation ID</label>
                                                            <input type="number" name="friendly_nation_id" class="form-control"
                                                                   list="friendly-options-{{ $plan->id }}" placeholder="Type ID or select" required>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label">Match score</label>
                                                            <input type="number" name="match_score" class="form-control" min="0" max="100" placeholder="50">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <button type="submit" class="btn btn-primary w-100 mt-3 mt-md-0">Assign</button>
                                                        </div>
                                                    </form>
                                                    <p class="small text-muted mt-3 mb-0">Use the datalist to search by leader name if you already know the responder.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endpush
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No targets yet. Add alliances or seed specific nation IDs above.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="card-title mb-0">Assignments &amp; squads</h5>
                    <small class="text-muted">Full overview of friendlies per target. Max six offensive slots, three defensive.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Target</th>
                                <th>Squad</th>
                                <th>Friendly</th>
                                <th>Alliance</th>
                                <th>Wars</th>
                                <th>Assignments</th>
                                <th>Match score</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($assignments->sortBy(fn ($assignment) => [$assignment->war_plan_target_id, $assignment->squad?->label ?? 'ZZZ', $assignment->match_score * -1]) as $assignment)
                                @php
                                    $friendly = $assignment->friendlyNation;
                                    $friendlyAlliance = $friendly?->alliance;
                                    $enemyNation = $assignment->target->nation;
                                    $enemyMilitary = $enemyNation?->military;
                                    $friendlyMilitary = $friendly?->military;
                                    $stats = $friendlyStats->get($assignment->friendly_nation_id);
                                    $assignmentLoad = $stats['assignment_load'] ?? 0;
                                    $maxAssignments = $stats['max_assignments'] ?? max(1, $plan->preferred_nations_per_target);
                                    $collapseId = 'assignment-meta-'.$assignment->id;
                                @endphp
                                <tr>
                                    <td>
                                        @if ($enemyNation)
                                            <a href="https://politicsandwar.com/nation/id={{ $enemyNation->id }}" target="_blank" class="fw-semibold">
                                                {{ $enemyNation->leader_name }}
                                            </a>
                                            <div class="small text-muted">{{ $enemyNation->nation_name }}</div>
                                            <div class="small">Score {{ number_format($enemyNation->score ?? 0, 2) }} • Cities {{ $enemyNation->num_cities ?? 0 }}</div>
                                            <div class="small text-muted">Soldiers {{ number_format(optional($enemyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($enemyMilitary)->tanks ?? 0) }}</div>
                                            <div class="small text-muted">Aircraft {{ number_format(optional($enemyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($enemyMilitary)->ships ?? 0) }}</div>
                                        @else
                                            <span class="text-muted">Unknown</span>
                                        @endif
                                    </td>
                                    <td>{{ $assignment->squad->label ?? 'Unassigned' }}</td>
                                    <td>
                                        @if ($friendly)
                                            <a href="https://politicsandwar.com/nation/id={{ $friendly->id }}" target="_blank" class="fw-semibold">
                                                {{ $friendly->leader_name }}
                                            </a>
                                            <div class="small text-muted">{{ $friendly->nation_name }}</div>
                                            <div class="small">Score {{ number_format($friendly->score ?? 0, 2) }} • Cities {{ $friendly->num_cities ?? 0 }}</div>
                                            <div class="small text-muted">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                            <div class="small text-muted">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                        @else
                                            <span class="text-muted">Unknown</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($friendlyAlliance)
                                            <a href="https://politicsandwar.com/alliance/id={{ $friendlyAlliance->id }}" target="_blank">
                                                {{ $friendlyAlliance->name }}
                                            </a>
                                            <div class="small text-muted">{{ $friendlyAlliance->acronym }}</div>
                                        @else
                                            <span class="text-muted">No alliance</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars currently active">
                                            {{ $friendly->offensive_wars_count ?? 0 }} / {{ $friendly->defensive_wars_count ?? 0 }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light" data-bs-toggle="tooltip" title="Assigned targets vs capacity">
                                            {{ $assignmentLoad }} / {{ $maxAssignments }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <span class="badge text-bg-info">{{ number_format($assignment->match_score, 1) }}</span>
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                                                Details
                                            </button>
                                        </div>
                                        @if ($assignment->is_overridden)
                                            <span class="badge text-bg-secondary mt-1" data-bs-toggle="tooltip" title="Manual override">
                                                Manual
                                            </span>
                                        @endif
                                    </td>
                                    <td><span class="badge text-bg-light text-uppercase">{{ $assignment->status }}</span></td>
                                    <td class="text-end">
                                        <form method="post" action="{{ route('admin.war-plans.assignments.destroy', [$plan, $assignment]) }}"
                                              onsubmit="return confirm('Remove this assignment?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="collapse" id="{{ $collapseId }}">
                                    <td colspan="9" class="bg-body-tertiary">
                                        @include('admin.war-room.partials.match-breakdown', ['meta' => $assignment->meta ?? []])
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No assignments yet. Auto-generate or use the Assign buttons to attach friendlies.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Live attacks</h5>
                    <small class="text-muted">Filter window, scope, or attack type to focus the feed.</small>
                </div>
                <div class="card-body">
                    <form class="row g-3 mb-3" method="get">
                        <div class="col-md-3">
                            <label class="form-label">Minutes</label>
                            <input type="number" class="form-control" name="minutes" value="{{ request('minutes') }}"
                                   placeholder="{{ config('war.live_feed.default_window_minutes') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Scope</label>
                            <select name="scope" class="form-select">
                                <option value="both" @selected(request('scope') === 'both')>All</option>
                                <option value="ours" @selected(request('scope') === 'ours')>Friendlies</option>
                                <option value="theirs" @selected(request('scope') === 'theirs')>Enemies</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Attack types (CSV)</label>
                            <input type="text" class="form-control" name="attack_types"
                                   value="{{ is_array(request('attack_types')) ? implode(',', request('attack_types')) : request('attack_types') }}"
                                   placeholder="ground,air">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-outline-primary w-100" type="submit">Apply</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Attacker</th>
                                <th>Defender</th>
                                <th>Type</th>
                                <th>Result</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($liveFeed as $attack)
                                <tr>
                                    <td>{{ optional($attack->date)->diffForHumans() }}</td>
                                    <td>{{ $attack->attacker->leader_name ?? $attack->att_id }}</td>
                                    <td>{{ $attack->defender->leader_name ?? $attack->def_id }}</td>
                                    <td>{{ $attack->type?->name ?? $attack->type }}</td>
                                    <td>{{ $attack->victor ?? 'Inconclusive' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No attacks within the selected window.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importPlanModal" tabindex="-1" aria-labelledby="importPlanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importPlanModalLabel">Import plan JSON</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="{{ route('admin.war-plans.import', $plan) }}">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Payload</label>
                            <textarea class="form-control" name="payload" rows="10" placeholder="Paste exported JSON here" required></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="dry_run" value="1" id="dryRun">
                            <label class="form-check-label" for="dryRun">Dry run (preview diff only)</label>
                        </div>
                        <p class="small text-muted mt-3 mb-0">Imports honor the latest schema and override conflicting targets or assignments.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Process import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @stack('modals')
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl));
        });
    </script>
@endpush
