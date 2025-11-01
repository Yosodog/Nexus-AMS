@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-12 col-lg-6">
                    <h3 class="mb-1">Alliance Dashboard</h3>
                    <p class="text-secondary mb-0">
                        Telemetry cached {{ $lastRefreshedAt->diffForHumans() }} (expires {{ $lastRefreshedAt->copy()->addMinutes($cacheTtlMinutes)->diffForHumans() }}).
                    </p>
                </div>
                <div class="col-12 col-lg-6 mt-3 mt-lg-0">
                    <div class="d-flex flex-wrap gap-2 justify-content-lg-end align-items-center">
                        <span class="badge bg-primary-subtle text-primary-emphasis">
                            <i class="bi bi-people me-1"></i> Members: {{ number_format($totalMembers) }}
                        </span>
                        <span class="badge bg-success-subtle text-success-emphasis">
                            <i class="bi bi-building me-1"></i> Cities: {{ number_format($totalCities) }}
                        </span>
                        <span class="badge bg-dark-subtle text-dark-emphasis">
                            <i class="bi bi-cash-stack me-1"></i> Cash: ${{ number_format($cashTotal, 0) }}
                        </span>
                        <a href="{{ route('admin.dashboard', ['refresh' => 1]) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4">
        @foreach ($kpis as $kpi)
            <div class="col">
                <div class="info-box shadow-sm h-100">
                    <span class="info-box-icon {{ $kpi['bg'] }} text-white shadow">
                        <i class="{{ $kpi['icon'] }}"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text text-uppercase text-secondary fw-semibold">{{ $kpi['title'] }}</span>
                        <div class="d-flex align-items-baseline justify-content-between">
                            <span class="info-box-number fs-3 fw-semibold">{{ $kpi['value'] }}</span>
                            @if (! is_null($kpi['trend']))
                                <span class="badge {{ $kpi['trend'] >= 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                    <i class="bi {{ $kpi['trend'] >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right' }}"></i>
                                    {{ $kpi['trend'] >= 0 ? '+' : '' }}{{ number_format($kpi['trend'], 1) }}%
                                </span>
                            @endif
                        </div>
                        @if (! empty($kpi['helper']))
                            <span class="text-secondary small d-block mt-1">{{ $kpi['helper'] }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Tax Intake (Money)</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success-subtle text-success-emphasis">
                            ${{ number_format($taxMoneyThisWeek, 0) }} / 7d
                        </span>
                        @if (! is_null($taxMoneyTrend))
                            <span class="badge {{ $taxMoneyTrend >= 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                {{ $taxMoneyTrend >= 0 ? '+' : '' }}{{ number_format($taxMoneyTrend, 1) }}% vs prior week
                            </span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="taxMoneyChart" height="220"></canvas>
                    <p class="text-secondary small mt-3 mb-0">
                        Shows cash delivered into alliance banks (primary + offshore) across the past two weeks.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Tax Intake (Resources)</div>
                <div class="card-body">
                    <canvas id="taxResourceChart" height="220"></canvas>
                    <p class="text-secondary small mt-3 mb-0">
                        Focused on steel, munitions, aluminum, and food flows captured in the same window.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">War Tempo & Damage (14 Days)</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger-subtle text-danger-emphasis">
                            {{ number_format($warsThisWeek) }} wars launched
                        </span>
                        @if (! is_null($warTrend))
                            <span class="badge {{ $warTrend >= 0 ? 'bg-danger-subtle text-danger-emphasis' : 'bg-success-subtle text-success-emphasis' }}">
                                {{ $warTrend >= 0 ? '+' : '' }}{{ number_format($warTrend, 1) }}% vs prior week
                            </span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="warChart" height="220"></canvas>
                    <p class="text-secondary small mt-3 mb-0">
                        Tracks wars involving our alliance family only, combining infra destruction and looted cash.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">MMR Readiness</span>
                    <span class="badge bg-info-subtle text-info-emphasis">
                        {{ number_format($mmrCoverage, 1) }}% compliant ({{ $mmrCompliantCount }}/{{ number_format($totalMembers) }})
                    </span>
                </div>
                <div class="card-body">
                    <canvas id="mmrChart" height="220"></canvas>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-secondary small">Coverage to {{ $mmrThreshold }} score</span>
                            <span class="fw-semibold small">{{ number_format($mmrCoverage, 1) }}%</span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-info" role="progressbar"
                                 style="width: {{ min(100, $mmrCoverage) }}%"
                                 aria-valuenow="{{ $mmrCoverage }}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Resource Stockpile</span>
                    <span class="badge bg-dark-subtle text-dark-emphasis">
                        Total value ≈ ${{ number_format($resourceTotalValue, 0) }}
                    </span>
                </div>
                <div class="card-body">
                    <canvas id="resourceChart" height="240"></canvas>
                    <p class="text-secondary small mt-3 mb-2">
                        {{ $latestTradePriceDate ? "Valued with market snapshot {$latestTradePriceDate}." : 'Market snapshot unavailable; displaying raw tonnage.' }}
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Resource</th>
                                <th class="text-end">Units</th>
                                <th class="text-end">Value</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($resourceValueBreakdown as $resource => $value)
                                @php
                                    $resourceKey = is_string($resource) ? $resource : 'Other';
                                    $units = $resourceTotals[$resourceKey] ?? null;
                                @endphp
                                <tr>
                                    <td class="text-capitalize">{{ str_replace('_', ' ', $resourceKey) }}</td>
                                    <td class="text-end">{{ $units !== null ? number_format($units, $units >= 1000 ? 0 : 2) : '—' }}</td>
                                    <td class="text-end">${{ number_format($value, 0) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Military Readiness</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis">
                        Max posture: 15k soldiers / 1.25k tanks / 75 aircraft / 15 ships (per city) · 60 spies (per nation)
                    </span>
                </div>
                <div class="card-body">
                    <canvas id="militaryChart" height="240"></canvas>
                    <div class="row text-secondary small mt-3">
                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <p class="mb-1">Current vs capacity</p>
                            <ul class="list-unstyled mb-0">
                                @foreach ($militaryTotals as $unit => $total)
                                    <li class="d-flex justify-content-between">
                                        <span class="text-capitalize">{{ str_replace('_', ' ', $unit) }}</span>
                                        <span class="text-dark fw-semibold">
                                            {{ number_format($total) }}
                                            /
                                            {{ number_format($militaryCapacity[$unit]) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="col-12 col-md-6">
                            <p class="mb-1">Average posture</p>
                            <ul class="list-unstyled mb-0">
                                <li class="d-flex justify-content-between">
                                    <span>Soldiers / city</span>
                                    <span class="text-dark fw-semibold">{{ number_format($militaryPerUnitAverage['soldiers']) }}</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Tanks / city</span>
                                    <span class="text-dark fw-semibold">{{ number_format($militaryPerUnitAverage['tanks']) }}</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Aircraft / city</span>
                                    <span class="text-dark fw-semibold">{{ number_format($militaryPerUnitAverage['aircraft'], 1) }}</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Ships / city</span>
                                    <span class="text-dark fw-semibold">{{ number_format($militaryPerUnitAverage['ships'], 2) }}</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Spies / member</span>
                                    <span class="text-dark fw-semibold">{{ number_format($militaryPerUnitAverage['spies'], 1) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Infrastructure Focus</div>
                <div class="card-body">
                    <p class="text-secondary small">
                        Alliance infrastructure totals {{ number_format($totalInfrastructure, 0) }}. Power coverage at {{ number_format($powerCoverage, 1) }}% keeps the network ready.
                    </p>
                    <ul class="list-group list-group-flush">
                        @forelse ($topInfrastructureCities as $city)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="https://politicsandwar.com/city/id={{ $city->id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                        {{ $city->name }}
                                    </a>
                                    <span class="text-secondary small d-block">
                                        <a href="https://politicsandwar.com/nation/id={{ $city->nation?->id }}" target="_blank" rel="noopener" class="text-decoration-none">
                                            {{ $city->nation?->leader_name ?? 'Unknown' }}
                                        </a>
                                    </span>
                                </div>
                                <div class="text-end">
                                    <div class="fw-semibold">{{ number_format($city->infrastructure, 0) }} infra</div>
                                    <div class="text-secondary small">{{ number_format($city->land, 0) }} land</div>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No city telemetry captured yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Wealth Concentration</div>
                <div class="card-body">
                    <p class="text-secondary small">
                        Alliance-wide cash reserves average ${{ number_format($cashPerMember, 0) }} per member.
                    </p>
                    <ul class="list-group list-group-flush">
                        @forelse ($topCashHolders as $holder)
                            <li class="list-group-item d-flex justify-content-between">
                                <div>
                                    <a href="https://politicsandwar.com/nation/id={{ $holder->nation_id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                        {{ $holder->leader_name }}
                                    </a>
                                    <span class="text-secondary small d-block">{{ $holder->nation_name }}</span>
                                </div>
                                <div class="text-end">
                                    <div class="fw-semibold">${{ number_format($holder->money, 0) }}</div>
                                    <div class="text-secondary small">{{ optional($holder->snapshot_at)->diffForHumans() ?? 'Snapshot pending' }}</div>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No resource telemetry available yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Top Scoring Nations</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Nation</th>
                                <th class="text-end">Score</th>
                                <th class="text-end">Cities</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($topScoringNations as $nation)
                                <tr>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                            {{ $nation->leader_name }}
                                        </a>
                                        <span class="text-secondary small d-block">{{ $nation->nation_name }}</span>
                                    </td>
                                    <td class="text-end">{{ number_format($nation->score, 2) }}</td>
                                    <td class="text-end">{{ number_format($nation->num_cities) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-secondary small">No nations tracked yet.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Loan Program Snapshot</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-secondary small text-uppercase">Pending approvals</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($loanStats['pending']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Active or delinquent</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($loanStats['active']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Paid-off loans</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($loanStats['paid']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Outstanding balance</dt>
                        <dd class="col-5 text-end fw-semibold">${{ number_format($loanStats['outstanding_balance'], 0) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Avg interest</dt>
                        <dd class="col-5 text-end fw-semibold">
                            {{ $loanStats['avg_interest'] !== null ? number_format($loanStats['avg_interest'], 2).'%' : '—' }}
                        </dd>

                        <dt class="col-7 text-secondary small text-uppercase">Avg term (weeks)</dt>
                        <dd class="col-5 text-end fw-semibold">
                            {{ $loanStats['avg_term'] !== null ? number_format($loanStats['avg_term'], 1) : '—' }}
                        </dd>
                    </dl>
                    <p class="text-secondary small mt-3 mb-0">
                        Balances include approved and missed loans across the primary alliance and all offshores.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Grant Program Snapshot</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-secondary small text-uppercase">Pending applications</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($grantStats['pending']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Approved this week</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($grantStats['approved_this_week']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Total approvals</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format($grantStats['approved_total']) }}</dd>

                        <dt class="col-7 text-secondary small text-uppercase">Money issued (30d)</dt>
                        <dd class="col-5 text-end fw-semibold">${{ number_format($grantStats['money_disbursed_30d'], 0) }}</dd>
                    </dl>
                    <p class="text-secondary small mt-3 mb-0">
                        Resource payouts are calculated from the latest 30 days of approved grant disbursements.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Active War Rooms</div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($activeWarDetails as $war)
                            @php
                                $attIsMember = in_array($war->att_id, $memberNationIds, true);
                                $defIsMember = in_array($war->def_id, $memberNationIds, true);
                            @endphp
                            <li class="list-group-item py-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                            {{ $war->attacker?->leader_name ?? 'Unknown' }}
                                        </a>
                                        @if ($attIsMember)
                                            <span class="badge bg-primary-subtle text-primary-emphasis align-middle ms-1"
                                                  data-bs-toggle="tooltip" title="Alliance member">
                                                <i class="bi bi-shield-check"></i>
                                            </span>
                                        @endif
                                        <span class="text-secondary small d-block">
                                            vs <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank" rel="noopener" class="text-decoration-none">
                                                {{ $war->defender?->leader_name ?? 'Unknown' }}
                                            </a>
                                            @if ($defIsMember)
                                                <span class="badge bg-primary-subtle text-primary-emphasis align-middle ms-1"
                                                      data-bs-toggle="tooltip" title="Alliance member">
                                                    <i class="bi bi-shield-check"></i>
                                                </span>
                                            @endif
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-danger-subtle text-danger-emphasis text-uppercase small">
                                            {{ \Illuminate\Support\Str::headline($war->war_type) }}
                                        </span>
                                        <div class="text-secondary small mt-1">Turns left: {{ number_format($war->turns_left) }}</div>
                                    </div>
                                </div>
                                <div class="mt-2 text-secondary small">
                                    <div class="d-flex justify-content-between">
                                        <span>Resistance</span>
                                        <span>{{ $war->att_resistance }} / {{ $war->def_resistance }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Points</span>
                                        <span>{{ $war->att_points }} - {{ $war->def_points }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Infra loss</span>
                                        <span>{{ number_format($war->att_infra_destroyed + $war->def_infra_destroyed, 0) }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Bank loot</span>
                                        <span>${{ number_format($war->att_money_looted + $war->def_money_looted, 0) }}</span>
                                    </div>
                                    @if ($war->att_fortify || $war->def_fortify)
                                        <div class="d-flex justify-content-between">
                                            <span>Fortify</span>
                                            <span>{{ $war->att_fortify ? 'Att' : '' }}{{ $war->att_fortify && $war->def_fortify ? ' & ' : '' }}{{ $war->def_fortify ? 'Def' : '' }}</span>
                                        </div>
                                    @endif
                                </div>
                                <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank" rel="noopener" class="d-inline-block mt-2 small">
                                    View timeline →
                                </a>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No active conflicts at the moment.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Recent War Outcomes</span>
                    <span class="text-secondary small">Last {{ $recentWars->count() }} wars involving alliance members</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Attacker</th>
                                <th>Defender</th>
                                <th class="text-end">Infra Loss</th>
                                <th class="text-end">Bank Loot</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($recentWars as $war)
                                @php
                                    $attIsMember = in_array($war->att_id, $memberNationIds, true);
                                    $defIsMember = in_array($war->def_id, $memberNationIds, true);
                                @endphp
                                <tr>
                                    <td>{{ $war->date ? \Carbon\Carbon::parse($war->date)->format('M d') : '—' }}</td>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                            {{ $war->attacker?->leader_name ?? 'Unknown' }}
                                        </a>
                                        @if ($attIsMember)
                                            <span class="badge bg-primary-subtle text-primary-emphasis align-middle ms-1"
                                                  data-bs-toggle="tooltip" title="Alliance member">
                                                <i class="bi bi-shield-check"></i>
                                            </span>
                                        @endif
                                        @if ($war->winner_id === $war->att_id)
                                            <span class="badge bg-success-subtle text-success-emphasis ms-1"
                                                  data-bs-toggle="tooltip" title="Winner">
                                                W
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                                            {{ $war->defender?->leader_name ?? 'Unknown' }}
                                        </a>
                                        @if ($defIsMember)
                                            <span class="badge bg-primary-subtle text-primary-emphasis align-middle ms-1"
                                                  data-bs-toggle="tooltip" title="Alliance member">
                                                <i class="bi bi-shield-check"></i>
                                            </span>
                                        @endif
                                        @if ($war->winner_id === $war->def_id)
                                            <span class="badge bg-success-subtle text-success-emphasis ms-1"
                                                  data-bs-toggle="tooltip" title="Winner">
                                                W
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {{ number_format($war->att_infra_destroyed + $war->def_infra_destroyed, 0) }}
                                    </td>
                                    <td class="text-end">
                                        ${{ number_format($war->att_money_looted + $war->def_money_looted, 0) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-secondary small">No recent wars recorded.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const palette = {
                primary: '#2563eb',
                secondary: '#1d4ed8',
                success: '#16a34a',
                warning: '#f97316',
                danger: '#dc2626',
                info: '#0ea5e9',
                neutral: '#64748b',
            };

            const taxMoneyData = @json($taxMoneyDaily);
            const taxResourceData = @json($taxResourceDaily);
            const warData = @json($warDaily);
            const mmrBuckets = @json($mmrDistribution);
            const resourceBreakdown = @json($resourceValueBreakdown instanceof \Illuminate\Support\Collection ? $resourceValueBreakdown->toArray() : $resourceValueBreakdown);
            const militaryReadiness = @json($militaryReadiness instanceof \Illuminate\Support\Collection ? $militaryReadiness->toArray() : $militaryReadiness);

            const numberFormat = (value) => new Intl.NumberFormat('en-US').format(value ?? 0);
            const currencyFormat = (value) => '$' + new Intl.NumberFormat('en-US', {maximumFractionDigits: 0}).format(value ?? 0);

            const ctxTaxMoney = document.getElementById('taxMoneyChart');
            if (ctxTaxMoney) {
                new Chart(ctxTaxMoney, {
                    type: 'line',
                    data: {
                        labels: taxMoneyData.map(item => item.day),
                        datasets: [
                            {
                                label: 'Money',
                                data: taxMoneyData.map(item => item.money),
                                borderColor: palette.success,
                                backgroundColor: palette.success + '33',
                                fill: true,
                                tension: 0.3,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        return `${context.dataset.label}: ${currencyFormat(context.parsed.y)}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            y: { beginAtZero: true },
                        },
                    },
                });
            }

            const ctxTaxResource = document.getElementById('taxResourceChart');
            if (ctxTaxResource) {
                new Chart(ctxTaxResource, {
                    type: 'line',
                    data: {
                        labels: taxResourceData.map(item => item.day),
                        datasets: [
                            {
                                label: 'Steel',
                                data: taxResourceData.map(item => item.steel),
                                borderColor: palette.primary,
                                backgroundColor: palette.primary + '22',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Munitions',
                                data: taxResourceData.map(item => item.munitions),
                                borderColor: palette.warning,
                                backgroundColor: palette.warning + '22',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Aluminum',
                                data: taxResourceData.map(item => item.aluminum),
                                borderColor: palette.info,
                                backgroundColor: palette.info + '22',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Food',
                                data: taxResourceData.map(item => item.food),
                                borderColor: palette.neutral,
                                backgroundColor: palette.neutral + '22',
                                fill: true,
                                tension: 0.3,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: true },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        return `${context.dataset.label}: ${numberFormat(context.parsed.y)}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            y: { beginAtZero: true },
                        },
                    },
                });
            }

            const ctxWar = document.getElementById('warChart');
            if (ctxWar) {
                new Chart(ctxWar, {
                    data: {
                        labels: warData.map(item => item.day),
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Wars Started',
                                data: warData.map(item => item.wars_started),
                                backgroundColor: palette.danger + '55',
                                borderRadius: 6,
                                yAxisID: 'y',
                            },
                            {
                                type: 'line',
                                label: 'Infra Destroyed',
                                data: warData.map(item => item.infra_destroyed),
                                borderColor: palette.warning,
                                borderWidth: 2,
                                tension: 0.3,
                                yAxisID: 'y1',
                            },
                            {
                                type: 'line',
                                label: 'Money Looted',
                                data: warData.map(item => item.money_looted),
                                borderColor: palette.success,
                                borderDash: [6, 4],
                                borderWidth: 2,
                                tension: 0.3,
                                yAxisID: 'y1',
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true, position: 'left' },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: { drawOnChartArea: false },
                            },
                        },
                        plugins: {
                            legend: { display: true },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        const label = context.dataset.label;
                                        const value = context.parsed.y;

                                        if (label === 'Money Looted') {
                                            return `${label}: ${currencyFormat(value)}`;
                                        }

                                        return `${label}: ${numberFormat(value)}`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const ctxMmr = document.getElementById('mmrChart');
            if (ctxMmr) {
                new Chart(ctxMmr, {
                    type: 'bar',
                    data: {
                        labels: mmrBuckets.map(item => `${item.bucket}s`),
                        datasets: [
                            {
                                label: 'Nations',
                                data: mmrBuckets.map(item => item.total),
                                backgroundColor: palette.info + '66',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        return `${context.parsed.y} nations`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const ctxResource = document.getElementById('resourceChart');
            if (ctxResource) {
                const labels = Object.keys(resourceBreakdown || {});
                const values = Object.values(resourceBreakdown || {});
                new Chart(ctxResource, {
                    type: 'doughnut',
                    data: {
                        labels,
                        datasets: [
                            {
                                data: values,
                                backgroundColor: [
                                    palette.primary,
                                    palette.success,
                                    palette.info,
                                    palette.warning,
                                    palette.danger,
                                    palette.neutral,
                                ],
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        return `${context.label}: ${currencyFormat(context.parsed)}`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const ctxMilitary = document.getElementById('militaryChart');
            if (ctxMilitary) {
                const labels = Object.keys(militaryReadiness || {}).map(label => label.replace(/_/g, ' '));
                const readiness = Object.values(militaryReadiness || {});
                new Chart(ctxMilitary, {
                    type: 'radar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Readiness %',
                                data: readiness,
                                borderColor: palette.primary,
                                backgroundColor: palette.primary + '33',
                                borderWidth: 2,
                                pointBackgroundColor: palette.primary,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        scales: {
                            r: {
                                beginAtZero: true,
                                suggestedMax: 100,
                                ticks: {
                                    callback(value) {
                                        return `${value}%`;
                                    },
                                },
                            },
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label(context) {
                                        return `${context.label}: ${context.parsed.r}%`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(triggerEl => {
                if (window.bootstrap?.Tooltip) {
                    new bootstrap.Tooltip(triggerEl);
                }
            });
        });
    </script>
@endsection
