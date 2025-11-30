@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0 d-flex align-items-center gap-2">
                    Round {{ $round->round_number }} — {{ \Illuminate\Support\Str::headline(strtolower($round->op_type?->name ?? '')) }}
                    <span class="ms-auto text-muted" data-bs-toggle="tooltip" title="This view lists every assignment for the round with odds, safety, and policy synergy.">
                        <i class="bi bi-question-circle"></i>
                    </span>
                </h3>
                <p class="text-muted mb-0">Campaign: {{ $campaign->name }}</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.spy-campaigns.show', $campaign) }}" class="btn btn-outline-secondary btn-sm">Back to campaign</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small">Assignments</div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $assignments->count() }}</span>
                            <span class="badge text-bg-secondary">Orders</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small">Average Odds</div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ number_format($avgOdds, 1) }}%</span>
                            <span class="badge text-bg-warning">Quality</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small">High Odds (80%+)</div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $highOdds }}</span>
                            <span class="badge text-bg-success">Safe</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small">Low Odds Flags</div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $lowOdds }}</span>
                            <span class="badge text-bg-danger">Review</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Assignments</h5>
                <span class="text-muted ms-auto" data-bs-toggle="tooltip" title="Safety levels 1/2/3 map to Quick/Normal/Covert, picked to meet the campaign threshold.">
                    <i class="bi bi-shield-lock"></i>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Attacker</th>
                        <th>Defender</th>
                        <th>Odds</th>
                        <th>Safety</th>
                        <th>Impact</th>
                        <th>Synergy</th>
                        <th>Link</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($assignments as $assignment)
                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    <a href="https://politicsandwar.com/nation/id={{ $assignment->attacker?->id }}" target="_blank" rel="noopener">
                                        {{ $assignment->attacker?->leader_name }}
                                    </a>
                                </div>
                                <div class="text-muted small">{{ $assignment->attacker?->nation_name }}</div>
                                <div class="text-muted small d-flex flex-wrap gap-2">
                                    <span class="badge text-bg-secondary">Score {{ number_format($assignment->attacker?->score ?? 0, 2) }}</span>
                                    <span class="badge text-bg-secondary">Cities {{ $assignment->attacker?->num_cities ?? 0 }}</span>
                                    <span class="badge text-bg-secondary">Spies {{ $assignment->attacker?->military?->spies ?? 0 }}</span>
                                    <span class="badge text-bg-secondary">Policy {{ $assignment->attacker?->war_policy }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold">
                                    <a href="https://politicsandwar.com/nation/id={{ $assignment->defender?->id }}" target="_blank" rel="noopener">
                                        {{ $assignment->defender?->leader_name }}
                                    </a>
                                </div>
                                <div class="text-muted small">{{ $assignment->defender?->nation_name }}</div>
                                <div class="text-muted small d-flex flex-wrap gap-2">
                                    <span class="badge text-bg-secondary">Score {{ number_format($assignment->defender?->score ?? 0, 2) }}</span>
                                    <span class="badge text-bg-secondary">Cities {{ $assignment->defender?->num_cities ?? 0 }}</span>
                                    <span class="badge text-bg-secondary">Spies {{ $assignment->defender?->military?->spies ?? 0 }}</span>
                                    <span class="badge text-bg-secondary">Policy {{ $assignment->defender?->war_policy }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $assignment->calculated_odds >= 80 ? 'success' : ($assignment->low_odds_flag ? 'danger' : 'secondary') }}"
                                      data-bs-toggle="tooltip"
                                      title="{{ $assignment->low_odds_flag ? 'Below campaign threshold' : 'Meets threshold' }}">
                                    {{ number_format($assignment->calculated_odds, 1) }}%
                                </span>
                            </td>
                            <td>
                                @php
                                    $safetyLabels = [1 => 'Quick and Dirty', 2 => 'Normal Precautions', 3 => 'Extremely Covert'];
                                    $label = $safetyLabels[$assignment->safety_level] ?? 'Unknown';
                                @endphp
                                <span class="badge text-bg-secondary">{{ $label }}</span>
                            </td>
                            <td>{{ number_format($assignment->expected_impact, 1) }}</td>
                            <td>
                                <span class="badge text-bg-{{ $assignment->policy_synergy > 0 ? 'primary' : 'secondary' }}">
                                    {{ number_format($assignment->policy_synergy, 2) }}
                                </span>
                            </td>
                            <td>
                                <a href="https://politicsandwar.com/nation/espionage/eid={{ $assignment->defender?->id }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                                    Espionage
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No assignments yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@pushOnce('scripts', 'spy-round-tooltips')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endPushOnce
