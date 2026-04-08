@extends('layouts.admin')

@section('content')
    <x-header title="Rebuilding Management" separator>
        <x-slot:subtitle>Track estimates, payout throughput, and member eligibility through the current rebuilding cycle.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap justify-end gap-2">
                <form method="POST" action="{{ route('admin.rebuilding.toggle') }}">
                    @csrf
                    <button class="btn btn-{{ $enabled ? 'warning' : 'success' }} btn-sm">
                        {{ $enabled ? 'Close Rebuilding' : 'Open Rebuilding' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.rebuilding.reset') }}" onsubmit="return confirm('Reset rebuilding for a new cycle?');">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm">Reset Cycle</button>
                </form>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        <div class="w-full">
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100 bg-primary text-white bg-gradient">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="text-uppercase font-semibold text-white-50 small">Current Cycle</span>
                                    <h2 class="fw-bold mb-0">{{ number_format($cycleId) }}</h2>
                                </div>
                                <span class="badge text-bg-light text-primary-emphasis">
                                    <i class="o-arrow-path"></i>
                                </span>
                            </div>
                            <p class="mb-0 small text-white-50">Rebuilding state: {{ $enabled ? 'Open' : 'Closed' }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100 bg-success text-white bg-gradient">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="text-uppercase font-semibold text-white-50 small">Eligible Estimate</span>
                                    <h2 class="fw-bold mb-0">${{ number_format((float) $estimateTotal) }}</h2>
                                </div>
                                <span class="badge text-bg-light text-success-emphasis">
                                    <i class="o-calculator"></i>
                                </span>
                            </div>
                            <p class="mb-0 small text-white-50">Projected total for current cycle payouts.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100 bg-dark text-white bg-gradient">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="text-uppercase font-semibold text-white-50 small">Total Sent</span>
                                    <h2 class="fw-bold mb-0">${{ number_format((float) $totalSentThisCycle) }}</h2>
                                </div>
                                <span class="badge text-bg-light text-dark">
                                    <i class="o-banknotes-coin"></i>
                                </span>
                            </div>
                            <p class="mb-0 small text-white-50">Approved rebuilding disbursements this cycle.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100 bg-secondary text-white bg-gradient">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="text-uppercase font-semibold text-white-50 small">Last Refresh</span>
                                    <h2 class="fw-bold mb-0">{{ $lastEstimateRefreshAt ? $lastEstimateRefreshAt->format('M d H:i') : 'Never' }}</h2>
                                </div>
                                <span class="badge text-bg-light text-base-content/50-emphasis">
                                    <i class="o-clock"></i>
                                </span>
                            </div>
                            <p class="mb-0 small text-white-50">Estimate snapshot refresh timestamp.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="text-uppercase text-base-content/50 small">Request Pipeline</span>
                                    <h3 class="fw-bold mb-0">{{ number_format($pendingCount) }} pending</h3>
                                    <p class="mb-0 text-base-content/50 small">{{ number_format($approvedCount) }} approved · {{ number_format($deniedCount) }} denied</p>
                                </div>
                                <span class="badge badge-primary"><i class="o-list-bullet-check"></i></span>
                            </div>
                            <div class="flex flex-wrap gap-2 small text-base-content/50">
                                <span class="badge text-bg-light text-base-content/50 border">Cycle {{ number_format($cycleId) }}</span>
                                <span class="badge text-bg-light text-base-content/50 border">{{ number_format($estimateCount) }} with estimates</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="text-uppercase text-base-content/50 small">Payout Quality</span>
                                    <h3 class="fw-bold mb-0">{{ number_format($approvalRate, 1) }}%</h3>
                                    <p class="mb-0 text-base-content/50 small">Approval rate of decided requests</p>
                                </div>
                                <span class="badge badge-success"><i class="o-arrow-trending-up"></i></span>
                            </div>
                            <div class="flex flex-wrap gap-2 small text-base-content/50">
                                <span class="badge text-bg-light text-base-content/50 border">Avg payout ${{ number_format($averageApprovedPayout, 0) }}</span>
                                <span class="badge text-bg-light text-base-content/50 border">Remaining est. ${{ number_format($estimatedButUnsent, 0) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="text-uppercase text-base-content/50 small">Eligibility Snapshot</span>
                                    <h3 class="fw-bold mb-0">{{ number_format($applicantCount + $vacationCount + $ineligibleCount) }} excluded</h3>
                                    <p class="mb-0 text-base-content/50 small">Current cycle exclusion footprint</p>
                                </div>
                                <span class="badge badge-warning"><i class="o-shield-check-exclamation"></i></span>
                            </div>
                            <div class="flex flex-wrap gap-2 small text-base-content/50">
                                <span class="badge text-bg-light text-base-content/50 border">{{ number_format($applicantCount) }} applicants</span>
                                <span class="badge text-bg-light text-base-content/50 border">{{ number_format($vacationCount) }} in VM</span>
                                <span class="badge text-bg-light text-base-content/50 border">{{ number_format($ineligibleCount) }} ineligible</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header flex justify-content-between align-items-center">
                    <span>Refresh Estimates</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.rebuilding.refresh-estimates') }}" class="flex gap-2 align-items-end">
                        @csrf
                        <div>
                            <label class="form-label">Cycle Override (optional)</label>
                            <input type="number" class="form-control" name="cycle_id" min="1" value="{{ $cycleId }}">
                        </div>
                        <button class="btn btn-primary" type="submit">Refresh Now</button>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>All Nations Rebuilding Overview</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Cities</th>
                                <th>Rebuilding Amount</th>
                                <th>Request Status</th>
                                <th>Approved</th>
                                <th>Flags</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($nationRows as $row)
                                <tr>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $row['nation_id'] }}" target="_blank" rel="noopener noreferrer">
                                            {{ $row['leader_name'] ?: ('Nation #'.$row['nation_id']) }}
                                        </a>
                                    </td>
                                    <td>{{ $row['city_count'] }}</td>
                                    <td>${{ number_format((float) $row['estimated_amount']) }}</td>
                                    <td>
                                        <span class="badge bg-{{ $row['status'] === 'approved' ? 'success' : ($row['status'] === 'denied' ? 'danger' : ($row['status'] === 'pending' ? 'warning' : 'secondary')) }}">
                                            {{ $row['status'] === 'not_applied' ? 'Not Applied' : ucfirst($row['status']) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $row['is_approved'] ? 'success' : 'secondary' }}">
                                            {{ $row['is_approved'] ? 'Yes' : 'No' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($row['is_applicant'])
                                            <span class="badge bg-secondary">Applicant</span>
                                        @endif
                                        @if($row['is_vacation_mode'])
                                            <span class="badge bg-dark">VM</span>
                                        @endif
                                        @if($row['is_ineligible'])
                                            <span class="badge bg-warning text-dark">Ineligible</span>
                                        @endif
                                        @if(! $row['is_applicant'] && ! $row['is_vacation_mode'] && ! $row['is_ineligible'])
                                            <span class="text-base-content/50">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-base-content/50">No nations available in alliance scope.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>Tier Configuration</strong></div>
                <div class="card-body">
                    @php
                        $requirementOptions = [
                            'urban_planning' => 'Urban Planning',
                            'advanced_urban_planning' => 'Advanced Urban Planning',
                            'center_for_civil_engineering' => 'Center for Civil Engineering',
                            'advanced_engineering_corps' => 'Advanced Engineering Corps',
                            'government_support_agency' => 'Government Support Agency',
                        ];
                    @endphp
                    <form method="POST" action="{{ route('admin.rebuilding.tiers.store') }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <div class="col-md-2">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" placeholder="Optional">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Min Cities</label>
                            <input type="number" class="form-control" name="min_city_count" min="1" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Max Cities</label>
                            <input type="number" class="form-control" name="max_city_count" min="1" placeholder="No max">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Target Infrastructure</label>
                            <input type="number" step="0.01" class="form-control" name="target_infrastructure" min="0" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Assumed Cost-Reduction Projects (for calculation)</label>
                            <div class="flex flex-wrap gap-3">
                                @foreach($requirementOptions as $value => $label)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="requirements[]" value="{{ $value }}" id="req-create-{{ $value }}">
                                        <label class="form-check-label" for="req-create-{{ $value }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-1 form-check mt-4">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="form-check-input" name="is_active" value="1" checked>
                            <label class="form-check-label">Active</label>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success w-100" type="submit">Add Tier</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Range</th>
                                <th>Target Infra</th>
                                <th>Calc Assumptions</th>
                                <th>Active</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($tiers as $tier)
                                <tr>
                                    <td>{{ $tier->name ?: '-' }}</td>
                                    <td>{{ $tier->min_city_count }} - {{ $tier->max_city_count ?? '∞' }}</td>
                                    <td>{{ number_format((float) $tier->target_infrastructure, 2) }}</td>
                                    <td>
                                        @forelse(($tier->requirements ?? []) as $requirement)
                                            <span class="badge bg-light text-dark">{{ str_replace('_', ' ', $requirement) }}</span>
                                        @empty
                                            <span class="text-base-content/50">None</span>
                                        @endforelse
                                    </td>
                                    <td>{{ $tier->is_active ? 'Yes' : 'No' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.rebuilding.tiers.update', $tier) }}" class="d-inline-flex gap-1 align-items-center flex-wrap">
                                            @csrf
                                            @method('PUT')
                                            <input class="form-control form-control-sm" name="name" value="{{ $tier->name }}" placeholder="Name">
                                            <input type="number" class="form-control form-control-sm" name="min_city_count" min="1" value="{{ $tier->min_city_count }}" required>
                                            <input type="number" class="form-control form-control-sm" name="max_city_count" min="1" value="{{ $tier->max_city_count }}" placeholder="Max">
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="target_infrastructure" min="0" value="{{ $tier->target_infrastructure }}" required>
                                            @foreach($requirementOptions as $value => $label)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="requirements[]" value="{{ $value }}" id="req-{{ $tier->id }}-{{ $value }}"
                                                           @checked(in_array($value, $tier->requirements ?? [], true))>
                                                    <label class="form-check-label small" for="req-{{ $tier->id }}-{{ $value }}">{{ $label }}</label>
                                                </div>
                                            @endforeach
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($tier->is_active)>
                                            <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.rebuilding.tiers.destroy', $tier) }}" class="d-inline" onsubmit="return confirm('Delete this tier?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>Ineligible Nations (Current Cycle)</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.rebuilding.ineligible.store') }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label">Nation ID</label>
                            <input type="number" class="form-control" name="nation_id" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reason (optional)</label>
                            <input type="text" class="form-control" name="reason" maxlength="255">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-warning w-100" type="submit">Mark Ineligible</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Nation</th>
                                <th>Reason</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($ineligible as $entry)
                                <tr>
                                    <td>
                                        @if ($entry->nation)
                                            <a href="https://politicsandwar.com/nation/id={{ $entry->nation->id }}"
                                               target="_blank" rel="noopener noreferrer">
                                                {{ $entry->nation->leader_name ?? ('Nation #'.$entry->nation->id) }}
                                            </a>
                                            <div class="small text-base-content/50">{{ $entry->nation->nation_name ?? 'Unknown Nation' }}</div>
                                        @else
                                            <span class="text-base-content/50">{{ 'Nation #'.$entry->nation_id }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $entry->reason ?: '-' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.rebuilding.ineligible.destroy', $entry->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-base-content/50">No ineligible nations this cycle.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if ($pending->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header"><strong>Pending Requests</strong></div>
                    <div class="card-body">
                        @foreach ($pending as $req)
                            <div class="border rounded p-3 mb-3">
                                <div class="flex justify-content-between flex-wrap gap-2 mb-2">
                                    <div>
                                        @if ($req->nation)
                                            <strong>
                                                <a href="https://politicsandwar.com/nation/id={{ $req->nation->id }}"
                                                   target="_blank" rel="noopener noreferrer">
                                                    {{ $req->nation->leader_name ?? ('Nation #'.$req->nation->id) }}
                                                </a>
                                            </strong>
                                            <div class="small text-base-content/50">{{ $req->nation->nation_name ?? 'Unknown Nation' }}</div>
                                        @else
                                            <strong>{{ 'Nation #'.$req->nation_id }}</strong>
                                        @endif
                                        <div class="small text-base-content/50">
                                            Account: {{ $req->account?->name ?? 'Unknown' }} |
                                            Cities: {{ $req->city_count_snapshot }} |
                                            Target: {{ number_format((float) $req->target_infrastructure_snapshot, 2) }}
                                        </div>
                                        <div class="small text-base-content/50">Estimated: ${{ number_format((float) $req->estimated_amount) }}</div>
                                        @if($req->note)
                                            <div class="small text-base-content/50">Note: {{ $req->note }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <form method="POST" action="{{ route('admin.rebuilding.approve', $req) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PATCH')
                                            <div class="col-sm-5">
                                                <label class="form-label">Approved Amount</label>
                                                <input type="number" min="0" step="1" name="approved_amount" class="form-control" value="{{ (int) round($req->estimated_amount) }}">
                                            </div>
                                            <div class="col-sm-5">
                                                <label class="form-label">Review Note</label>
                                                <input type="text" name="review_note" class="form-control" maxlength="255" placeholder="Optional">
                                            </div>
                                            <div class="col-sm-2">
                                                <button class="btn btn-success w-100" type="submit">Approve</button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-6">
                                        <form method="POST" action="{{ route('admin.rebuilding.deny', $req) }}" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PATCH')
                                            <div class="col-sm-10">
                                                <label class="form-label">Review Note</label>
                                                <input type="text" name="review_note" class="form-control" maxlength="255" placeholder="Optional">
                                            </div>
                                            <div class="col-sm-2">
                                                <button class="btn btn-danger w-100" type="submit">Deny</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><strong>Cycle History</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Nation</th>
                                <th>Status</th>
                                <th>Estimated</th>
                                <th>Approved</th>
                                <th>Created</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($history as $req)
                                <tr>
                                    <td>
                                        @if ($req->nation)
                                            <a href="https://politicsandwar.com/nation/id={{ $req->nation->id }}"
                                               target="_blank" rel="noopener noreferrer">
                                                {{ $req->nation->leader_name ?? ('Nation #'.$req->nation->id) }}
                                            </a>
                                            <div class="small text-base-content/50">{{ $req->nation->nation_name ?? 'Unknown Nation' }}</div>
                                        @else
                                            <span class="text-base-content/50">{{ 'Nation #'.$req->nation_id }}</span>
                                        @endif
                                    </td>
                                    <td><span class="badge bg-{{ $req->status === 'approved' ? 'success' : ($req->status === 'denied' ? 'danger' : 'secondary') }}">{{ ucfirst($req->status) }}</span></td>
                                    <td>${{ number_format((float) $req->estimated_amount) }}</td>
                                    <td>${{ number_format((float) ($req->approved_amount ?? 0)) }}</td>
                                    <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-base-content/50">No history records this cycle.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div>{{ $history->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
