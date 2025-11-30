@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h3 class="mb-0 d-flex align-items-center gap-2">
                        {{ $campaign->name }}
                        <span class="text-muted" data-bs-toggle="tooltip" title="Each round runs a single op type. Assignments respect spy range, slots, policy synergy, and your min success target.">
                            <i class="bi bi-question-circle"></i>
                        </span>
                    </h3>
                    <div class="text-muted">
                        Status:
                        <span class="badge text-bg-primary text-uppercase">{{ $campaign->status }}</span>
                        <span class="ms-2 small">{{ $campaign->description }}</span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.spy-campaigns.index') }}" class="btn btn-outline-secondary btn-sm">Back</a>
                </div>
            </div>

            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
                <div>
                    <strong>How this works:</strong> add allied and enemy alliances, create rounds with a single spy op, then click <em>Generate assignments</em>. The system builds attacker/defender pairs within spy range, applies slot caps (2 offensive / 3 defensive), finds the lowest safety level meeting the minimum success target, and boosts matches with policy synergy. Use the message action to push formatted PW mails to each aggressor with links.
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Allied / Enemy Alliances</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $campaign->alliances->where('role', 'ally')->count() }} / {{ $campaign->alliances->where('role', 'enemy')->count() }}</span>
                                <span class="badge text-bg-secondary" data-bs-toggle="tooltip" title="Alliances synced to feed aggressors and targets">Range</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Rounds</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $campaign->rounds->count() }}</span>
                                <span class="badge text-bg-primary">{{ $latestRound?->op_type?->name ?? 'n/a' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Assignments</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ $latestRound?->assignments->count() ?? 0 }}</span>
                                <span class="badge text-bg-info" data-bs-toggle="tooltip" title="Latest round assignment count">Live</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-none border h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">Avg Odds</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="h5 mb-0">{{ number_format($oddsDistribution->avg() ?? 0, 1) }}%</span>
                                <span class="badge text-bg-warning" data-bs-toggle="tooltip" title="Mean odds of the latest round">Quality</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rounds-tab" data-bs-toggle="tab" data-bs-target="#rounds" type="button" role="tab">Rounds</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="alliances-tab" data-bs-toggle="tab" data-bs-target="#alliances" type="button" role="tab">Alliances</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab">Settings</button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Odds Distribution</h5>
                                    <i class="bi bi-info-circle text-muted ms-auto" data-bs-toggle="tooltip" title="Spread of calculated odds for the latest round."></i>
                                </div>
                                <div class="card-body">
                                    <canvas id="oddsChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Impact Projections</h5>
                                    <i class="bi bi-activity text-muted ms-auto" data-bs-toggle="tooltip" title="Expected impact scaled by op type and policy synergy."></i>
                                </div>
                                <div class="card-body">
                                    <canvas id="impactChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Slot Usage</h5>
                                    <i class="bi bi-collection text-muted ms-auto" data-bs-toggle="tooltip" title="Max 2 offensive slots per aggressor, 3 defensive per target."></i>
                                </div>
                                <div class="card-body">
                                    <canvas id="slotsChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Top Targets</h5>
                                    <i class="bi bi-bullseye text-muted ms-auto" data-bs-toggle="tooltip" title="Sorted by expected impact."></i>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush">
                                        @forelse ($topTargets as $target)
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span>{{ $target['defender'] ?? 'Unknown' }}</span>
                                                <span class="badge text-bg-primary">{{ number_format($target['impact'], 1) }} impact</span>
                                            </div>
                                        @empty
                                            <div class="text-muted small">No assignments yet.</div>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="rounds" role="tabpanel">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Rounds</h5>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addRoundModal">
                                <i class="bi bi-plus-circle me-1"></i> Add Round
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Op</th>
                                    <th>Status</th>
                                    <th>Assignments</th>
                                    <th>Average Odds</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($campaign->rounds->sortBy('round_number') as $round)
                                    <tr>
                                        <td>{{ $round->round_number }}</td>
                                        <td>{{ $round->op_type?->name ?? 'n/a' }}</td>
                                        <td><span class="badge text-bg-secondary text-uppercase">{{ $round->status }}</span></td>
                                        <td>{{ $round->assignments->count() }}</td>
                                        <td>{{ number_format($round->assignments->avg('calculated_odds') ?? 0, 1) }}%</td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.spy-campaigns.rounds.show', $round) }}" class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="View assignments">
                                                <i class="bi bi-list-check"></i>
                                            </a>
                                            <form action="{{ route('admin.spy-campaigns.rounds.generate', $round) }}" method="post" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tooltip" title="Generate assignments for this round">
                                                    <i class="bi bi-cpu"></i>
                                                </button>
                                            </form>
                                            <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#messageModal-{{ $round->id }}">
                                                <i class="bi bi-envelope"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    {{-- Message modal --}}
                                    <div class="modal fade" id="messageModal-{{ $round->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Send messages for Round {{ $round->round_number }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="post" action="{{ route('admin.spy-campaigns.rounds.message', $round) }}">
                                                    @csrf
                                                    <div class="modal-body">
                                                        <label class="form-label">Message body</label>
                                                        <textarea name="message" class="form-control" rows="6" placeholder="Include tactics, timing, and reminders."></textarea>
                                                        <p class="text-muted small mt-2">Assignments auto-append target names, op type, safety, odds, and PW espionage links.</p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Queue Messages</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="alliances" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Allied</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        @foreach ($campaign->alliances->where('role', 'ally') as $alliance)
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span>{{ $alliance->alliance?->name ?? 'Unknown' }}</span>
                                                <form method="post" action="{{ route('admin.spy-campaigns.alliances.destroy', [$campaign, $alliance]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Remove alliance">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div class="card-footer">
                                    <form method="post" action="{{ route('admin.spy-campaigns.alliances.store', $campaign) }}" class="row g-2">
                                        @csrf
                                        <input type="hidden" name="role" value="ally">
                                        <div class="col-8">
                                            <label class="form-label">Alliance ID</label>
                                            <input type="number" name="alliance_id" class="form-control" placeholder="1234">
                                        </div>
                                        <div class="col-4 d-flex align-items-end">
                                            <button class="btn btn-primary w-100" type="submit">Add</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Enemy</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        @foreach ($campaign->alliances->where('role', 'enemy') as $alliance)
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span>{{ $alliance->alliance?->name ?? 'Unknown' }}</span>
                                                <form method="post" action="{{ route('admin.spy-campaigns.alliances.destroy', [$campaign, $alliance]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div class="card-footer">
                                    <form method="post" action="{{ route('admin.spy-campaigns.alliances.store', $campaign) }}" class="row g-2">
                                        @csrf
                                        <input type="hidden" name="role" value="enemy">
                                        <div class="col-8">
                                            <label class="form-label">Alliance ID</label>
                                            <input type="number" name="alliance_id" class="form-control" placeholder="5678">
                                        </div>
                                        <div class="col-4 d-flex align-items-end">
                                            <button class="btn btn-danger w-100" type="submit">Add</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form method="post" action="{{ route('admin.spy-campaigns.update', $campaign) }}" class="row g-3">
                                @csrf
                                @method('PUT')
                                <div class="col-md-6">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" class="form-control" value="{{ old('name', $campaign->name) }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        @foreach (['draft', 'active', 'archived'] as $status)
                                            <option value="{{ $status }}" @selected($campaign->status === $status)>{{ ucfirst($status) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label d-flex align-items-center gap-2">
                                        Min success %
                                        <span class="text-muted" data-bs-toggle="tooltip" title="Assignments test safety 1→3 and stop at the first level meeting this threshold; otherwise they’re flagged low-odds.">
                                            <i class="bi bi-question-circle"></i>
                                        </span>
                                    </label>
                                    <input type="number" name="settings[min_success_chance]" class="form-control" min="0" max="100" step="1" value="{{ old('settings.min_success_chance', $campaign->settings['min_success_chance'] ?? 65) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3">{{ old('description', $campaign->description) }}</textarea>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary">Save settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Add round modal --}}
    <div class="modal fade" id="addRoundModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Round</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="{{ route('admin.spy-campaigns.rounds.store', $campaign) }}">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Operation Type</label>
                            <select name="op_type" class="form-select" required>
                                @foreach ($opTypes as $type)
                                    <option value="{{ $type->value }}">{{ \Illuminate\Support\Str::headline(strtolower($type->name)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Round # (optional)</label>
                            <input type="number" name="round_number" class="form-control" min="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex align-items-center gap-2">
                                Min success target (%)
                                <span class="text-muted" data-bs-toggle="tooltip" title="Assignments pick the lowest safety level that meets this chance; lower odds are still assigned but flagged.">
                                    <i class="bi bi-question-circle"></i>
                                </span>
                            </label>
                            <input type="number" name="min_success_chance" class="form-control" min="0" max="100" step="1" value="{{ $campaign->settings['min_success_chance'] ?? 65 }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@pushOnce('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const odds = {{ Js::from($oddsDistribution->all()) }};
        const impacts = {{ Js::from($impactSeries->all()) }};
        const attackerSlots = {{ Js::from($slotUsage['attackers'] ?? []) }};
        const defenderSlots = {{ Js::from($slotUsage['defenders'] ?? []) }};

        const oddsCtx = document.getElementById('oddsChart');
        if (oddsCtx && odds.length) {
            new Chart(oddsCtx, {
                type: 'bar',
                data: {
                    labels: odds.map((_, idx) => `#${idx + 1}`),
                    datasets: [{
                        label: 'Odds %',
                        data: odds,
                        backgroundColor: '#2563eb'
                    }]
                }
            });
        }

        const impactCtx = document.getElementById('impactChart');
        if (impactCtx && impacts.length) {
            new Chart(impactCtx, {
                type: 'line',
                data: {
                    labels: impacts.map((_, idx) => `#${idx + 1}`),
                    datasets: [{
                        label: 'Expected impact',
                        data: impacts,
                        borderColor: '#d946ef',
                        fill: false,
                        tension: 0.3,
                    }]
                }
            });
        }

        const slotsCtx = document.getElementById('slotsChart');
        if (slotsCtx) {
            new Chart(slotsCtx, {
                type: 'bar',
                data: {
                    labels: ['Aggressors', 'Targets'],
                    datasets: [{
                        label: 'Slots used',
                        data: [Object.values(attackerSlots).reduce((sum, val) => sum + val, 0), Object.values(defenderSlots).reduce((sum, val) => sum + val, 0)],
                        backgroundColor: ['#22c55e', '#f97316'],
                    }]
                }
            });
        }
    </script>
@endPushOnce

@pushOnce('scripts', 'spy-campaign-show-tooltips')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endPushOnce
