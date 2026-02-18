@extends('layouts.admin')

@section('content')
    <datalist id="friendly-options-{{ $plan->id }}" x-data>
        <template x-for="friendly in $store?.warPlan?.friendlies || []" :key="friendly.id">
            <option :value="friendly.id" x-text="`${friendly.leader_name} (${friendly.nation_name})`"></option>
        </template>
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
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Targets</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $enemyCount }}</span>
                                <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Enemy nations tracked with TPS">
                                    <i class="bi bi-bullseye"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Assign coverage</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $coverage !== null ? $coverage.'%' : 'n/a' }}</span>
                                <span class="badge text-bg-primary" data-bs-toggle="tooltip" title="Assignments / preferred slots">
                                    {{ $assignmentCount }} / {{ $preferredSlotsTotal }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Locked slots</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $lockedCount }}</span>
                                <span class="badge text-bg-success" data-bs-toggle="tooltip" title="Locked or overridden assignments remain untouched">
                                    Safe
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Preferred wars / nation</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $preferredTargetsPerNation }}</span>
                                <span class="badge text-bg-info" data-bs-toggle="tooltip" title="Activity window drives readiness weighting">
                                    {{ $plan->activity_window_hours }}h
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-5">
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
                            Preferred targets / nation
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip"
                               title="Offensive wars we aim to give each friendly before applying slot caps."></i>
                        </dt>
                        <dd class="col-5 text-end">{{ $preferredTargetsPerNation }}</dd>

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
                            <label class="form-label">Preferred targets / nation</label>
                            <input type="number" name="preferred_targets_per_nation" class="form-control" min="1" max="6"
                                   value="{{ old('preferred_targets_per_nation', $plan->preferred_targets_per_nation) }}">
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

        <div class="col-12 col-xl-3">
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

    {{-- Quick assign modal --}}
    <div class="modal fade" id="quickAssignModal" tabindex="-1" aria-labelledby="quickAssignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" x-data>
                <div class="modal-header">
                    <h5 class="modal-title" id="quickAssignModalLabel">Quick assign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" :action="$store.warPlan.routes.manualAssignment">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Friendly nation</label>
                            <input type="text" class="form-control" name="friendly_nation_id" id="quickAssignFriendly" :value="$store.warPlan.quickAssign?.id || ''" readonly>
                            <div class="form-text" id="quickAssignFriendlyName" x-text="$store.warPlan.quickAssign ? `${$store.warPlan.quickAssign.leader_name} (${$store.warPlan.quickAssign.nation_name})` : 'Select a friendly to assign'"></div>
                            <a x-show="$store.warPlan.quickAssign?.id"
                               :href="`https://politicsandwar.com/nation/id=${$store.warPlan.quickAssign?.id}`"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="small">
                                Open nation in-game
                            </a>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Target</label>
                            <select name="war_plan_target_id" class="form-select" required>
                                <template x-for="target in $store.warPlan.targets" :key="target.id">
                                    <template x-if="$store.warPlan.targetAvailable(target)">
                                        <option :value="target.id" x-text="`${target.nation?.leader_name ?? 'Unknown'} (TPS ${formatNumber(target.target_priority_score, 1)}) • Slots ${(target.assignments_count ?? 0)} / ${$store.warPlan.preferredAssignmentsPerTarget || '-'}`"></option>
                                    </template>
                                </template>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Match score (optional)</label>
                            <input type="number" name="match_score" class="form-control" min="0" max="100" placeholder="50">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm" x-data="targetsTable()">
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
                    <div class="p-3 d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group input-group-sm w-auto flex-grow-1 flex-lg-grow-0">
                            <span class="input-group-text">Search</span>
                            <input type="search" class="form-control" placeholder="Enemy, alliance, TPS, status" x-model.debounce.300ms="search">
                        </div>
                        <div class="d-flex align-items-center gap-2 ms-auto">
                            <a href="{{ route('admin.war-plans.targets.export-csv', $plan) }}" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-download me-1"></i> Export CSV
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="fetchTargets">
                                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                            </button>
                            <div class="align-items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <span class="text-muted small">Loading targets…</span>
                            </div>
                            <span class="text-danger small" x-show="error" x-text="error"></span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" x-show="error" @click="fetchTargets">Retry</button>
                        </div>
                    </div>
                    <div class="table-responsive" id="targets-table" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light position-sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Enemy</th>
                                <th>Alliance</th>
                                <th>TPS</th>
                                <th>Slots</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>War type</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredTargets.length && !error">
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No targets yet. Add alliances or seed specific nation IDs above.</td>
                                </tr>
                            </template>
                            <template x-for="target in filteredTargets" :key="target.id">
                                <tr x-show="!isVacation(target)" x-transition>
                                    <td>
                                        <template x-if="target.nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${target.nation.id}`" target="_blank" class="fw-semibold">
                                                    <span x-text="target.nation.leader_name"></span>
                                                </a>
                                                <div class="small text-muted" x-text="target.nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(target.nation.score, 2)"></span>
                                                    • Cities <span x-text="target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Soldiers <span x-text="formatNumber(target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Aircraft <span x-text="formatNumber(target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="fw-semibold text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="target.nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${target.nation.alliance.id}`" target="_blank" x-text="target.nation.alliance.name"></a>
                                                <div class="small text-muted" x-text="target.nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation?.alliance">
                                            <span class="text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-danger" data-bs-toggle="tooltip"
                                              title="Stored TPS meta" x-text="formatNumber(target.target_priority_score, 1)"></span>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              :class="badgeForSlots(target)"
                                              data-bs-toggle="tooltip"
                                              title="Assigned friendlies / preferred slots"
                                              x-text="`${target.assignments_count ?? 0} / ${slotsFor(target)}`">
                                        </span>
                                    </td>
                                    <td>
                                        <template x-if="target.nation">
                                            <div class="d-flex flex-wrap gap-1">
                                                <span class="badge text-bg-warning" data-bs-toggle="tooltip" title="In vacation mode" x-show="(target.nation.vacation_mode_turns ?? 0) > 0">VM</span>
                                                <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="On beige" x-show="(target.nation.beige_turns ?? 0) > 0" x-text="`${target.nation.beige_turns} beige`"></span>
                                                <span class="badge text-bg-info" data-bs-toggle="tooltip" title="Active wars"
                                                      x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) > 0"
                                                      x-text="`Wars ${target.nation.offensive_wars_count ?? 0} / ${target.nation.defensive_wars_count ?? 0}`"></span>
                                                <span class="text-muted small" x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) === 0 && (target.nation.beige_turns ?? 0) === 0 && (target.nation.vacation_mode_turns ?? 0) === 0">No flags</span>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="lastActive(target)"></td>
                                    <td style="width: 180px;">
                                        <form method="post" :action="routes.updateTargetWarType(target.id)">
                                            @csrf
                                            <select class="form-select form-select-sm"
                                                    name="preferred_war_type"
                                                    data-bs-toggle="tooltip"
                                                    title="Preferred declaration for this enemy"
                                                    @change="$event.target.form.submit()">
                                                <template x-for="(label, value) in warTypes" :key="value">
                                                    <option :value="value" :selected="target.preferred_war_type === value" x-text="label"></option>
                                                </template>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" @click="toggleTargetMeta(target.id)">
                                                Meta
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#assignTargetModal" @click="setActiveTarget(target)">
                                                Assign
                                            </button>
                                            <form method="post"
                                                  :action="routes.deleteTarget(target.id)"
                                                  onsubmit="return confirm('Remove this target from the plan?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                        <div class="mt-2 text-start" x-show="isTargetMetaOpen(target.id)" x-transition>
                                            <pre class="mb-0 small text-muted" x-text="prettyMeta(target.meta)"></pre>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal fade" id="assignTargetModal" tabindex="-1" aria-labelledby="assignTargetModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="assignTargetModalLabel">
                                    Manual assignments —
                                    <template x-if="activeTarget?.nation?.id">
                                        <a :href="`https://politicsandwar.com/nation/id=${activeTarget.nation.id}`" target="_blank" rel="noopener noreferrer" x-text="activeTarget.nation.leader_name"></a>
                                    </template>
                                    <template x-if="!activeTarget?.nation?.id">
                                        <span x-text="`Nation #${activeTarget?.nation_id ?? ''}`"></span>
                                    </template>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <h6 class="fw-semibold">In-range friendlies</h6>
                                <p class="small text-muted">All nations in war range, sorted with recommended options first.</p>
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
                                        <template x-if="candidatesLoading">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-3">Loading candidates…</td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && candidatesError">
                                            <tr>
                                                <td colspan="8" class="text-center text-danger py-3" x-text="candidatesError"></td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && !candidatesError && !candidatesForActiveTarget().length">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-3">No friendlies are in war range right now.</td>
                                            </tr>
                                        </template>
                                        <template x-for="candidate in (candidatesLoading ? [] : candidatesForActiveTarget())" :key="candidate.friendly.id">
                                            <tr>
                                                <td>
                                                    <a :href="`https://politicsandwar.com/nation/id=${candidate.friendly.id}`" target="_blank" rel="noopener noreferrer" class="fw-semibold" x-text="candidate.friendly.leader_name"></a>
                                                    <div class="small text-muted" x-text="candidate.friendly.nation_name"></div>
                                                </td>
                                                <td>
                                                    <template x-if="candidate.friendly.alliance">
                                                        <div>
                                                            <a :href="`https://politicsandwar.com/alliance/id=${candidate.friendly.alliance.id}`" target="_blank" x-text="candidate.friendly.alliance.name"></a>
                                                            <div class="small text-muted" x-text="candidate.friendly.alliance.acronym"></div>
                                                        </div>
                                                    </template>
                                                    <template x-if="!candidate.friendly.alliance">
                                                        <span class="text-muted">No alliance</span>
                                                    </template>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        Score <span x-text="formatNumber(candidate.friendly.score, 2)"></span>
                                                        • Cities <span x-text="candidate.friendly.num_cities ?? 0"></span>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Soldiers <span x-text="formatNumber(candidate.friendly.military?.soldiers || 0)"></span>
                                                        • Tanks <span x-text="formatNumber(candidate.friendly.military?.tanks || 0)"></span>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Aircraft <span x-text="formatNumber(candidate.friendly.military?.aircraft || 0)"></span>
                                                        • Ships <span x-text="formatNumber(candidate.friendly.military?.ships || 0)"></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars">
                                                        <span x-text="candidate.friendly.offensive_wars_count ?? 0"></span>
                                                        /
                                                        <span x-text="candidate.friendly.defensive_wars_count ?? 0"></span>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge text-bg-info" x-text="formatNumber(candidate.score, 1)"></span>
                                                        <span class="badge"
                                                              :class="candidate.recommended ? 'text-bg-success' : 'text-bg-secondary'"
                                                              x-text="candidate.recommended ? 'Recommended' : 'Manual only'"></span>
                                                    </div>
                                                </td>
                                                <td x-text="`${candidate.assignment_load} / ${candidate.max_assignments}`"></td>
                                                <td x-text="candidate.available_slots"></td>
                                                <td class="text-end">
                                                    <form method="post" :action="routes.manualAssignment">
                                                        @csrf
                                                        <input type="hidden" name="war_plan_target_id" :value="activeTarget?.id">
                                                        <input type="hidden" name="friendly_nation_id" :value="candidate.friendly.id">
                                                        <input type="hidden" name="match_score" :value="candidate.score">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Assign</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        </template>
                                        </tbody>
                                    </table>
                                </div>
                                <hr>
                                <h6 class="fw-semibold">Manual assignment</h6>
                                <form method="post" :action="routes.manualAssignment" class="row g-2 align-items-end">
                                    @csrf
                                    <input type="hidden" name="war_plan_target_id" :value="activeTarget?.id">
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
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm" x-data="assignmentsTable()">
                <div class="card-header">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h5 class="card-title mb-0">Assignments &amp; squads</h5>
                            <small class="text-muted">Full overview of friendlies per target. Max six offensive slots, three defensive.</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="{{ route('admin.war-plans.assignments.export-csv', $plan) }}" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-download me-1"></i> Export CSV
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="fetchAssignments">
                                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                            </button>
                            <div class="align-items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <span class="text-muted small">Loading assignments…</span>
                            </div>
                            <span class="text-danger small" x-show="error" x-text="error"></span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" x-show="error" @click="fetchAssignments">Retry</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="p-3 d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group input-group-sm w-auto flex-grow-1 flex-lg-grow-0">
                            <span class="input-group-text">Search</span>
                            <input type="search" class="form-control" placeholder="Friendly, target, status" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="table-responsive" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light position-sticky top-0" style="z-index: 1;">
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
                            <template x-if="!filteredAssignments.length && !error">
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No assignments yet. Auto-generate or use the Assign buttons to attach friendlies.</td>
                                </tr>
                            </template>
                            <template x-for="assignment in filteredAssignments" :key="assignment.id">
                                <tr>
                                    <td>
                                        <template x-if="assignment.target?.nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.target.nation.id}`" target="_blank" class="fw-semibold" x-text="assignment.target.nation.leader_name"></a>
                                                <div class="small text-muted" x-text="assignment.target.nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(assignment.target.nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Soldiers <span x-text="formatNumber(assignment.target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Aircraft <span x-text="formatNumber(assignment.target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.target?.nation">
                                            <span class="text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="assignment.squad?.label ?? 'Unassigned'"></td>
                                    <td>
                                        <template x-if="assignment.friendly_nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.friendly_nation.id}`" target="_blank" class="fw-semibold" x-text="assignment.friendly_nation.leader_name"></a>
                                                <div class="small text-muted" x-text="assignment.friendly_nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(assignment.friendly_nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.friendly_nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Soldiers <span x-text="formatNumber(assignment.friendly_nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.friendly_nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Aircraft <span x-text="formatNumber(assignment.friendly_nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.friendly_nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation">
                                            <span class="text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="assignment.friendly_nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${assignment.friendly_nation.alliance.id}`" target="_blank" x-text="assignment.friendly_nation.alliance.name"></a>
                                                <div class="small text-muted" x-text="assignment.friendly_nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation?.alliance">
                                            <span class="text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive wars currently active">
                                            <span x-text="assignment.friendly_nation?.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="assignment.friendly_nation?.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light" data-bs-toggle="tooltip" title="Assigned targets vs capacity"
                                              x-text="assignmentCapacity(assignment)">
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <div class="progress flex-grow-1" style="height: 8px;" aria-label="Match strength">
                                                <div class="progress-bar bg-info" role="progressbar" :style="`width: ${Math.min(100, assignment.match_score ?? 0)}%`"
                                                     :aria-valuenow="assignment.match_score ?? 0" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <span class="badge text-bg-info" x-text="formatNumber(assignment.match_score, 1)"></span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" @click="toggleAssignmentMeta(assignment.id)">
                                                Details
                                            </button>
                                            <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Manual override" x-show="assignment.is_overridden">Manual</span>
                                            <span class="badge text-bg-success" data-bs-toggle="tooltip" title="Locked assignment" x-show="assignment.is_locked">Locked</span>
                                        </div>
                                        <div class="mt-2" x-show="isAssignmentMetaOpen(assignment.id)" x-transition>
                                            <pre class="mb-0 small text-muted" x-text="prettyMeta(assignment.meta)"></pre>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <span class="badge text-bg-light text-uppercase" x-text="assignment.status"></span>
                                            <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Friendly on beige" x-show="(assignment.friendly_nation?.beige_turns ?? 0) > 0">Beige</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" :action="routes.deleteAssignment(assignment.id)"
                                              onsubmit="return confirm('Remove this assignment?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            </template>
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

                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light position-sticky top-0" style="z-index: 1;">
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

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                    <div>
                        <h5 class="card-title mb-0">Comparative Stats</h5>
                        <small class="text-muted">Quick glance at friendly vs enemy scale and militarization.</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="fw-semibold d-flex justify-content-between">
                                <span>Cities</span>
                                <span class="text-muted small">Friendly {{ number_format($friendlyCityTotal) }} / Enemy {{ number_format($enemyCityTotal) }}</span>
                            </h6>
                            <div class="progress" style="height: 10px;" aria-label="Cities share">
                                @php
                                    $cityTotal = max(1, $friendlyCityTotal + $enemyCityTotal);
                                    $friendlyCityPct = round(($friendlyCityTotal / $cityTotal) * 100, 1);
                                @endphp
                                <div class="progress-bar bg-primary" style="width: {{ $friendlyCityPct }}%" data-bs-toggle="tooltip" title="Friendly avg {{ number_format($friendlyCityAvg ?? 0, 1) }} | Enemy avg {{ number_format($enemyCityAvg ?? 0, 1) }}"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-semibold d-flex justify-content-between">
                                <span>Force Readiness</span>
                                <span class="text-muted small">By unit type</span>
                            </h6>
                            @foreach (['soldiers' => 'Soldiers', 'tanks' => 'Tanks', 'aircraft' => 'Aircraft', 'ships' => 'Ships'] as $unitKey => $label)
                                @php
                                    $friendlyVal = $friendlyMilTotals[$unitKey] ?? 0;
                                    $enemyVal = $enemyMilTotals[$unitKey] ?? 0;
                                    $totalUnits = $friendlyVal + $enemyVal;
                                    if ($totalUnits <= 0) {
                                        $friendlyPct = 50;
                                        $enemyPct = 50;
                                    } else {
                                        $friendlyPct = round(($friendlyVal / $totalUnits) * 100, 1);
                                        $enemyPct = round(100 - $friendlyPct, 1);
                                    }
                                @endphp
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $label }}</span>
                                        <span class="text-muted">Friendly {{ number_format($friendlyVal) }} • Enemy {{ number_format($enemyVal) }}</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-primary" style="width: {{ $friendlyPct }}%" data-bs-toggle="tooltip" title="Friendly {{ number_format($friendlyVal) }} units ({{ $friendlyPct }}% share)"></div>
                                        <div class="progress-bar bg-danger" style="width: {{ $enemyPct }}%" data-bs-toggle="tooltip" title="Enemy {{ number_format($enemyVal) }} units ({{ $enemyPct }}% share)"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-semibold">Average Cities</h6>
                            <p class="mb-1"><span class="badge text-bg-primary">Friendly {{ number_format($friendlyCityAvg ?? 0, 1) }}</span></p>
                            <p class="mb-1"><span class="badge text-bg-danger">Enemy {{ number_format($enemyCityAvg ?? 0, 1) }}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-semibold">Assignments Coverage</h6>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: {{ $coverage ?? 0 }}%" data-bs-toggle="tooltip" title="Assignments {{ $assignmentCount }} / Desired {{ $preferredSlotsTotal ?? 0 }}"></div>
                            </div>
                            <small class="text-muted">
                                Remaining gap: {{ $preferredSlotsTotal > 0 ? max(0, $preferredSlotsTotal - $assignmentCount).' slots' : 'n/a' }}.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm h-100" x-data="friendliesPanel()">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Unassigned friendlies</h5>
                        <small class="text-muted">No current target — fill gaps manually if needed.</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="fetchFriendlies">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                        </button>
                        <div class="align-items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <span class="text-muted small">Loading friendlies…</span>
                        </div>
                        <span class="text-danger small" x-show="error" x-text="error"></span>
                        <button class="btn btn-sm btn-outline-secondary" type="button" x-show="error" @click="fetchFriendlies">Retry</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="p-3 d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group input-group-sm w-auto flex-grow-1 flex-lg-grow-0">
                            <span class="input-group-text">Search</span>
                            <input type="search" class="form-control" placeholder="Friendly, alliance" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="table-responsive" id="assignments-table" x-show="!loading" x-cloak style="max-height: 520px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light position-sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Nation</th>
                                <th>Alliance</th>
                                <th>Wars</th>
                                <th>Activity</th>
                                <th class="text-end">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredUnassigned.length && !error">
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">Everyone is assigned.</td>
                                </tr>
                            </template>
                            <template x-for="friendly in filteredUnassigned" :key="friendly.id">
                                <tr>
                                    <td>
                                        <a :href="`https://politicsandwar.com/nation/id=${friendly.id}`" target="_blank" class="fw-semibold" x-text="friendly.leader_name"></a>
                                        <div class="small text-muted" x-text="friendly.nation_name"></div>
                                        <div class="small">
                                            Cities <span x-text="friendly.num_cities ?? 0"></span>
                                            • Score <span x-text="formatNumber(friendly.score, 2)"></span>
                                        </div>
                                    </td>
                                    <td>
                                        <template x-if="friendly.alliance">
                                            <div>
                                                <span class="d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-people-fill text-muted"></i>
                                                    <a :href="`https://politicsandwar.com/alliance/id=${friendly.alliance.id}`" target="_blank" x-text="friendly.alliance.name"></a>
                                                </span>
                                                <div class="small text-muted" x-text="friendly.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!friendly.alliance">
                                            <span class="text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Offensive / defensive">
                                            <span x-text="friendly.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="friendly.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td x-text="relativeTime(friendly.account_profile?.last_active)"></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#quickAssignModal"
                                                @click="$store.warPlan.setQuickAssign(friendly)">
                                            Assign
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            @include('admin.war-room.partials.score-guide')
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
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        window.warPlanConfig = {
            planId: {{ $plan->id }},
            warTypes: @json($warTypes),
            preferredAssignmentsPerTarget: {{ $preferredAssignmentsPerTarget }},
            routes: {
                targets: "{{ route('api.admin.war-plans.targets', $plan) }}",
                targetCandidates: "{{ route('api.admin.war-plans.target-candidates', [$plan, '__TARGET__']) }}",
                assignments: "{{ route('api.admin.war-plans.assignments', $plan) }}",
                friendlies: "{{ route('api.admin.war-plans.friendlies', $plan) }}",
                updateTargetWarType: "{{ route('admin.war-plans.targets.update-war-type', [$plan, '__TARGET__']) }}",
                deleteTarget: "{{ route('admin.war-plans.targets.destroy', [$plan, '__TARGET__']) }}",
                manualAssignment: "{{ route('admin.war-plans.assignments.manual', $plan) }}",
                deleteAssignment: "{{ route('admin.war-plans.assignments.destroy', [$plan, '__ASSIGNMENT__']) }}",
            },
        };

        function refreshTooltips() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl));
        }

        function formatNumber(value, decimals = 0) {
            const number = Number(value ?? 0);
            return Number.isFinite(number) ? number.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) : '0';
        }

        async function httpGet(url) {
            if (window.axios) {
                const {data} = await window.axios.get(url);
                return data;
            }

            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.json();
        }

        function prettyMeta(meta) {
            if (!meta) {
                return 'No metadata';
            }

            try {
                return JSON.stringify(meta, null, 2);
            } catch (e) {
                return 'Unable to render metadata';
            }
        }

        function fuzzyMatch(haystack, needle) {
            if (!needle) {
                return true;
            }

            const normalizedHaystack = (haystack || '').toString().toLowerCase();
            const normalizedNeedle = needle.toString().toLowerCase();

            let position = 0;

            for (const char of normalizedNeedle) {
                position = normalizedHaystack.indexOf(char, position);
                if (position === -1) {
                    return false;
                }
                position++;
            }

            return true;
        }

        function relativeTime(dateString) {
            if (!dateString) {
                return 'Unknown';
            }

            const date = new Date(dateString);

            if (Number.isNaN(date.getTime())) {
                return dateString;
            }

            const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
            const intervals = [
                {label: 'year', seconds: 31536000},
                {label: 'month', seconds: 2592000},
                {label: 'day', seconds: 86400},
                {label: 'hour', seconds: 3600},
                {label: 'minute', seconds: 60},
            ];

            for (const interval of intervals) {
                const count = Math.floor(seconds / interval.seconds);
                if (count >= 1) {
                    return `${count} ${interval.label}${count > 1 ? 's' : ''} ago`;
                }
            }

            return 'Just now';
        }

        document.addEventListener('alpine:init', () => {
            const config = window.warPlanConfig;

            Alpine.store('warPlan', {
                planId: config.planId,
                warTypes: config.warTypes,
                preferredAssignmentsPerTarget: config.preferredAssignmentsPerTarget,
                routes: {
                    targets: config.routes.targets,
                    targetCandidates: (targetId) => config.routes.targetCandidates.replace('__TARGET__', targetId),
                    assignments: config.routes.assignments,
                    friendlies: config.routes.friendlies,
                    updateTargetWarType: (targetId) => config.routes.updateTargetWarType.replace('__TARGET__', targetId),
                    deleteTarget: (targetId) => config.routes.deleteTarget.replace('__TARGET__', targetId),
                    manualAssignment: config.routes.manualAssignment,
                    deleteAssignment: (assignmentId) => config.routes.deleteAssignment.replace('__ASSIGNMENT__', assignmentId),
                },
                metaOpen: {},
                targets: [],
                friendlies: [],
                friendlyStats: {},
                unassigned: [],
                candidateMap: {},
                quickAssign: null,
                setTargets(targets) {
                    this.targets = targets || [];
                },
                setFriendlies(friendlies) {
                    this.friendlies = friendlies || [];
                },
                setFriendlyStats(stats) {
                    const map = {};
                    (stats || []).forEach((stat) => {
                        map[stat.friendly_nation_id] = stat;
                    });
                    this.friendlyStats = map;
                },
                setUnassigned(unassigned) {
                    this.unassigned = unassigned || [];
                },
                setCandidateMap(map) {
                    this.candidateMap = map || {};
                },
                setQuickAssign(friendly) {
                    this.quickAssign = friendly || null;
                },
                toggleMeta(key) {
                    this.metaOpen[key] = !this.metaOpen[key];
                },
                isMetaOpen(key) {
                    return this.metaOpen[key] ?? false;
                },
                targetAvailable(target) {
                    if (!target?.nation || (target.nation.vacation_mode_turns ?? 0) > 0) {
                        return false;
                    }

                    const assigned = target.assignments_count ?? 0;
                    return (this.preferredAssignmentsPerTarget ?? 0) === 0 || assigned < this.preferredAssignmentsPerTarget;
                },
            });
        });

        function targetsTable() {
            return {
                loading: true,
                error: null,
                search: '',
                targets: [],
                activeTarget: null,
                candidatesLoading: false,
                candidatesError: null,
                expandedTargets: {},
                init() {
                    this.fetchTargets();
                },
                get warTypes() {
                    return Alpine.store('warPlan').warTypes || {};
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchTargets() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.targets);
                        this.targets = data.targets || [];
                        Alpine.store('warPlan').setTargets(this.targets);
                        Alpine.store('warPlan').preferredAssignmentsPerTarget = data.preferred_assignments_per_target ?? Alpine.store('warPlan').preferredAssignmentsPerTarget;
                        Alpine.store('warPlan').setCandidateMap({});
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load targets.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredTargets() {
                    const query = this.search.trim();

                    if (!query) {
                        return this.targets;
                    }

                    return this.targets.filter((target) => fuzzyMatch(this.buildSearchBlob(target), query));
                },
                buildSearchBlob(target) {
                    const parts = [];

                    if (target?.nation) {
                        parts.push(
                            target.nation.leader_name,
                            target.nation.nation_name,
                            target.nation.alliance?.name,
                            target.nation.alliance?.acronym
                        );
                    }

                    parts.push(target.target_priority_score ?? '', target.preferred_war_type ?? '');

                    return parts.join(' ').toLowerCase();
                },
                slotsFor(target) {
                    const preferred = Alpine.store('warPlan').preferredAssignmentsPerTarget ?? 1;

                    return Math.max(1, preferred);
                },
                badgeForSlots(target) {
                    return (target.assignments_count ?? 0) >= this.slotsFor(target) ? 'text-bg-danger' : 'text-bg-success';
                },
                isVacation(target) {
                    return (target.nation?.vacation_mode_turns ?? 0) > 0;
                },
                lastActive(target) {
                    return relativeTime(target.nation?.account_profile?.last_active);
                },
                toggleTargetMeta(targetId) {
                    this.expandedTargets[targetId] = !this.expandedTargets[targetId];
                },
                isTargetMetaOpen(targetId) {
                    return !!this.expandedTargets[targetId];
                },
                setActiveTarget(target) {
                    this.activeTarget = target;
                    this.fetchCandidatesForTarget(target?.id);
                },
                candidatesForActiveTarget() {
                    if (!this.activeTarget) {
                        return [];
                    }

                    return Alpine.store('warPlan').candidateMap?.[this.activeTarget.id] || [];
                },
                async fetchCandidatesForTarget(targetId) {
                    if (!targetId) {
                        return;
                    }

                    const existing = Alpine.store('warPlan').candidateMap?.[targetId];
                    if (Array.isArray(existing)) {
                        this.candidatesError = null;
                        return;
                    }

                    this.candidatesLoading = true;
                    this.candidatesError = null;

                    try {
                        const data = await httpGet(this.routes.targetCandidates(targetId));
                        const candidateMap = {...(Alpine.store('warPlan').candidateMap || {})};
                        candidateMap[targetId] = data.candidates || [];
                        Alpine.store('warPlan').setCandidateMap(candidateMap);
                    } catch (e) {
                        this.candidatesError = 'Unable to load target candidates.';
                    } finally {
                        this.candidatesLoading = false;
                    }
                },
                formatNumber,
                prettyMeta,
            };
        }

        function assignmentsTable() {
            return {
                loading: true,
                error: null,
                search: '',
                assignments: [],
                expandedAssignments: {},
                init() {
                    this.fetchAssignments();
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchAssignments() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.assignments);
                        const assignments = data.assignments || [];

                        assignments.sort((a, b) => {
                            if (a.war_plan_target_id !== b.war_plan_target_id) {
                                return (a.war_plan_target_id ?? 0) - (b.war_plan_target_id ?? 0);
                            }

                            const squadA = a.squad?.label || 'ZZZ';
                            const squadB = b.squad?.label || 'ZZZ';

                            if (squadA !== squadB) {
                                return squadA.localeCompare(squadB);
                            }

                            return (b.match_score ?? 0) - (a.match_score ?? 0);
                        });

                        this.assignments = assignments;
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load assignments.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredAssignments() {
                    const query = this.search.trim();

                    if (!query) {
                        return this.assignments;
                    }

                    return this.assignments.filter((assignment) => fuzzyMatch(this.buildSearchBlob(assignment), query));
                },
                buildSearchBlob(assignment) {
                    const parts = [];
                    const friendly = assignment.friendly_nation;
                    const target = assignment.target?.nation;

                    if (friendly) {
                        parts.push(friendly.leader_name, friendly.nation_name, friendly.alliance?.name, friendly.alliance?.acronym);
                    }

                    if (target) {
                        parts.push(target.leader_name, target.nation_name, target.alliance?.name);
                    }

                    parts.push(assignment.status ?? '');

                    return parts.join(' ').toLowerCase();
                },
                assignmentCapacity(assignment) {
                    const stats = Alpine.store('warPlan').friendlyStats?.[assignment.friendly_nation_id] || {};
                    const load = stats.assignment_load ?? 0;
                    const max = stats.max_assignments ?? Math.max(1, Alpine.store('warPlan').preferredAssignmentsPerTarget ?? 1);

                    return `${load} / ${max}`;
                },
                toggleAssignmentMeta(assignmentId) {
                    this.expandedAssignments[assignmentId] = !this.expandedAssignments[assignmentId];
                },
                isAssignmentMetaOpen(assignmentId) {
                    return !!this.expandedAssignments[assignmentId];
                },
                formatNumber,
                prettyMeta,
            };
        }

        function friendliesPanel() {
            return {
                loading: true,
                error: null,
                search: '',
                init() {
                    this.fetchFriendlies();
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchFriendlies() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.friendlies);
                        const friendlies = (data.friendlies || []).sort((a, b) => (a.leader_name || '').localeCompare(b.leader_name || ''));
                        const unassigned = (data.unassigned || []).sort((a, b) => (a.leader_name || '').localeCompare(b.leader_name || ''));

                        Alpine.store('warPlan').setFriendlies(friendlies);
                        Alpine.store('warPlan').setFriendlyStats(data.friendly_stats || []);
                        Alpine.store('warPlan').setUnassigned(unassigned);
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load friendlies.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredUnassigned() {
                    const query = this.search.trim();
                    const unassigned = Alpine.store('warPlan').unassigned || [];

                    if (!query) {
                        return unassigned;
                    }

                    return unassigned.filter((friendly) => fuzzyMatch(this.buildSearchBlob(friendly), query));
                },
                buildSearchBlob(friendly) {
                    return [
                        friendly.leader_name,
                        friendly.nation_name,
                        friendly.alliance?.name,
                        friendly.alliance?.acronym,
                    ].join(' ').toLowerCase();
                },
                formatNumber,
            };
        }

        document.addEventListener('DOMContentLoaded', () => {
            refreshTooltips();
        });
    </script>
@endpush
