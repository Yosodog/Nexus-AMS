@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3 align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0">War Room Dashboard</h3>
                    <p class="text-muted mb-0">Track reactive counters alongside proactive war plans.</p>
                </div>
                <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createWarPlanModal">
                        <i class="bi bi-plus-circle me-1"></i> Create War Plan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Counters Table --}}
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <div class="d-flex flex-column flex-xl-row align-items-xl-center gap-3">
                        <div>
                            <h5 class="card-title mb-0">Counters</h5>
                            <small class="text-muted">Live and planning counters sharing assignment pools.</small>
                        </div>
                        <form class="ms-xl-auto d-flex flex-wrap gap-2" method="get">
                            <input type="hidden" name="plan_search" value="{{ $planSearch }}">
                            <input type="hidden" name="plan_status" value="{{ $planStatus }}">

                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="counter_active_search"
                                   value="{{ $counterSearch }}"
                                   placeholder="Search aggressor">

                            <select name="counter_status" class="form-select form-select-sm">
                                <option value="all" @selected($counterStatus === 'all')>All statuses</option>
                                <option value="active" @selected($counterStatus === 'active')>Active</option>
                                <option value="draft" @selected($counterStatus === 'draft')>Draft</option>
                            </select>

                            <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
                        </form>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Aggressor</th>
                                <th>Alliance</th>
                                <th>Status</th>
                                <th>War Type</th>
                                <th>Team Size</th>
                                <th>Last Update</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($counters as $counter)
                                <tr>
                                    <td>
                                        @if($counter->aggressor?->id)
                                            <a href="https://politicsandwar.com/nation/id={{ $counter->aggressor->id }}" target="_blank" rel="noopener noreferrer" class="fw-semibold">
                                                {{ $counter->aggressor->leader_name ?? 'Unknown' }}
                                            </a>
                                        @else
                                            <span class="fw-semibold">{{ $counter->aggressor->leader_name ?? 'Unknown' }}</span>
                                        @endif
                                        <div class="small text-muted">{{ $counter->aggressor->nation_name ?? '—' }}</div>
                                    </td>
                                    <td>{{ $counter->aggressor->alliance->name ?? 'No Alliance' }}</td>
                                    <td>
                                        <span class="badge {{ $counter->status === 'active' ? 'text-bg-success' : 'text-bg-warning' }} text-uppercase">
                                            {{ $counter->status }}
                                        </span>
                                    </td>
                                    <td>
                    <span class="badge text-bg-secondary text-uppercase"
                          data-bs-toggle="tooltip"
                          title="Preferred war declaration type">
                        {{ config('war.war_types')[strtolower($counter->war_declaration_type ?? '')] ?? ucfirst($counter->war_declaration_type ?? 'Unknown') }}
                    </span>
                                    </td>
                                    <td><span class="badge text-bg-primary">{{ $counter->team_size }}</span></td>
                                    <td>
                                        <span class="text-muted"
                                              data-bs-toggle="tooltip"
                                              title="{{ optional($counter->updated_at)->toDayDateTimeString() }}">
                                            {{ optional($counter->updated_at)->diffForHumans() ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.war-counters.show', $counter) }}" class="btn btn-sm btn-outline-primary">
                                            View Counter
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No counters match the current filters.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($counters->hasPages())
                    <div class="card-footer bg-body-tertiary">
                        {{ $counters->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- War Plans Table --}}
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <div class="d-flex flex-column flex-xl-row align-items-xl-center gap-3">
                        <div>
                            <h5 class="card-title mb-0">War Plans</h5>
                            <small class="text-muted">Planning and active campaigns with assignment state.</small>
                        </div>
                        <form class="ms-xl-auto d-flex flex-wrap gap-2" method="get">
                            <input type="hidden" name="counter_active_search" value="{{ $counterSearch }}">
                            <input type="hidden" name="counter_status" value="{{ $counterStatus }}">

                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="plan_search"
                                   value="{{ $planSearch }}"
                                   placeholder="Search plan">

                            <select name="plan_status" class="form-select form-select-sm">
                                <option value="all" @selected($planStatus === 'all')>All statuses</option>
                                <option value="active" @selected($planStatus === 'active')>Active</option>
                                <option value="planning" @selected($planStatus === 'planning')>Planning</option>
                            </select>

                            <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
                        </form>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Default War Type</th>
                                <th>Targets</th>
                                <th>Assignments</th>
                                <th>Updated</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($plans as $plan)
                                <tr>
                                    <td class="fw-semibold">{{ $plan->name }}</td>
                                    <td>
                                        <span class="badge {{ $plan->status === 'active' ? 'text-bg-success' : 'text-bg-warning' }} text-uppercase">
                                            {{ $plan->status }}
                                        </span>
                                    </td>
                                    <td>
                    <span class="badge text-bg-info text-uppercase">
                        {{ config('war.war_types')[strtolower($plan->plan_type ?? '')] ?? ucfirst($plan->plan_type ?? 'Unknown') }}
                    </span>
                                    </td>
                                    <td>{{ $plan->targets_count }}</td>
                                    <td>{{ $plan->assignments_count }}</td>
                                    <td>
                                        <span class="text-muted"
                                              data-bs-toggle="tooltip"
                                              title="{{ optional($plan->updated_at)->toDayDateTimeString() }}">
                                            {{ optional($plan->updated_at)->diffForHumans() ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.war-plans.show', $plan) }}" class="btn btn-sm btn-outline-primary">
                                            Open Plan
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No war plans match the current filters.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($plans->hasPages())
                    <div class="card-footer bg-body-tertiary">
                        {{ $plans->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="card-title mb-0">Discord War Alerts</h5>
                        <small class="text-muted">Channel ID used for {{ config('app.name') }} → Discord war alerts.</small>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.war-room.discord-channel') }}" class="row g-3">
                        @csrf
                        <div class="col-12">
                            <label for="channel_id" class="form-label">Channel ID</label>
                            <input type="text"
                                   id="channel_id"
                                   name="channel_id"
                                   class="form-control"
                                   placeholder="e.g. 123456789012345678"
                                   value="{{ old('channel_id', $discordWarChannelId) }}">
                            <div class="form-text">Leave blank to disable Discord war alerts.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input"
                                       type="checkbox"
                                       role="switch"
                                       id="enabled"
                                       name="enabled"
                                       value="1"
                                       {{ old('enabled', $discordWarAlertsEnabled) ? 'checked' : '' }}>
                                <label class="form-check-label" for="enabled">Enable war alert dispatches</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Save Channel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="card-title mb-0">Default War Room Forum</h5>
                        <small class="text-muted">Forum channel ID used when plans/counters do not override it.</small>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.war-room.default-forum') }}" class="row g-3">
                        @csrf
                        <div class="col-12">
                            <label for="default_forum_channel_id" class="form-label">Forum Channel ID</label>
                            <input type="text"
                                   id="default_forum_channel_id"
                                   name="default_forum_channel_id"
                                   class="form-control"
                                   placeholder="e.g. 123456789012345678"
                                   value="{{ old('default_forum_channel_id', $defaultWarRoomForumId) }}">
                            <div class="form-text">This is used for war-plan and counter room creation when no override is set.</div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Save Forum
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Create War Plan Modal --}}
    <div class="modal fade" id="createWarPlanModal" tabindex="-1" aria-labelledby="createWarPlanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="post" action="{{ route('admin.war-plans.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createWarPlanModalLabel">Create War Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" maxlength="120" value="{{ old('name') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Plan Type
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Default declaration used when launching wars under this plan."></i>
                            </label>
                            <select class="form-select" name="plan_type">
                                @foreach (config('war.war_types') as $value => $label)
                                    <option value="{{ $value }}" @selected(old('plan_type', config('war.plan_defaults.plan_type', 'ordinary')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Activity Window (hours)
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Recency window used when scoring targets during automation."></i>
                            </label>
                            <input type="number" class="form-control" name="activity_window_hours" min="12" max="240"
                                   value="{{ old('activity_window_hours', config('war.plan_defaults.activity_window_hours', 72)) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred Targets per Nation
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Desired offensive wars per friendly before slot caps and active wars are applied."></i>
                            </label>
                            <input type="number" class="form-control" name="preferred_targets_per_nation" min="1" max="6"
                                   value="{{ old('preferred_targets_per_nation', config('war.plan_defaults.preferred_targets_per_nation', 2)) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max Squad Size
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Upper limit for automated squads. Adjust when coordinating large stacks."></i>
                            </label>
                            <input type="number" class="form-control" name="max_squad_size" min="1" max="10"
                                   value="{{ old('max_squad_size', config('war.squads.max_size', 3)) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Squad Cohesion Tolerance (±)
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" title="Allows grouping nations with similar readiness without over-penalising activity differences."></i>
                            </label>
                            <input type="number" class="form-control" name="squad_cohesion_tolerance" min="1" max="50"
                                   value="{{ old('squad_cohesion_tolerance', config('war.squads.cohesion_tolerance', 10)) }}">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="suppress_counters_when_active" value="1"
                                       {{ old('suppress_counters_when_active', config('war.plan_defaults.suppress_counters_when_active', true)) ? 'checked' : '' }}>
                                <label class="form-check-label">Suppress counters while plan is active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Friendly Alliance IDs (optional, comma separated)</label>
                            <input type="text" class="form-control" name="friendly_alliances_raw" placeholder="e.g. 123,456" value="{{ old('friendly_alliances_raw') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Enemy Alliance IDs (optional, comma separated)</label>
                            <input type="text" class="form-control" name="enemy_alliances_raw" placeholder="e.g. 789,321" value="{{ old('enemy_alliances_raw') }}">
                        </div>
                    </div>
                    <p class="mt-3 mb-0 small text-muted">
                        You can adjust alliance membership, targets, and assignments inside the plan room after creation.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Plan</button>
                </div>
            </form>
        </div>
</div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl));
        });
    </script>
@endpush
