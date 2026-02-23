@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Loan Management</h3>
                </div>
            </div>
        </div>
    </div>

    @if (! $loanPaymentsEnabled)
        <div class="alert alert-warning mt-3">
            <i class="bi bi-pause-circle me-2"></i>
            Loan payments are currently paused. Required due and weekly accrual are frozen until payments are resumed.
        </div>
    @endif

    {{-- Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-check-circle" bgColor="text-bg-primary" title="Total Approved Loans"
                              :value="$totalApproved"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-x-circle" bgColor="text-bg-danger" title="Total Denied Loans"
                              :value="$totalDenied"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning" title="Pending Loans"
                              :value="$pendingCount"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Total Loaned Funds"
                              :value="number_format($totalLoanedFunds, 2)"/>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-piggy-bank" bgColor="text-bg-info" title="Outstanding Principal"
                              :value="number_format((float) $portfolioStats['outstanding_principal'], 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-credit-card" bgColor="text-bg-secondary" title="Current Due (All Active)"
                              :value="number_format((float) $portfolioStats['current_due_total'], 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-exclamation-triangle" bgColor="text-bg-warning" title="Total Past Due"
                              :value="number_format((float) $portfolioStats['past_due_total'], 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-percent" bgColor="text-bg-dark" title="Accrued Interest Due"
                              :value="number_format((float) $portfolioStats['accrued_interest_total'], 2)"/>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Operational Snapshot</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="mb-1 fw-semibold">Portfolio Health</p>
                        <p class="mb-1">Active loans: <strong>{{ $portfolioStats['active_count'] }}</strong></p>
                        <p class="mb-1">Loans in missed status: <strong>{{ $portfolioStats['missed_count'] }}</strong></p>
                        <p class="mb-0">Total payoff if closed now: <strong>${{ number_format((float) $portfolioStats['total_payoff_now'], 2) }}</strong></p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="mb-1 fw-semibold">Servicing Rules</p>
                        <ul class="mb-0 ps-3">
                            <li>Interest accrues weekly at cycle close, not daily.</li>
                            <li>Cycle interest is locked to opening principal for that cycle.</li>
                            <li>Payments apply to interest first, then principal.</li>
                            <li>No penalty fees are added for missed cycles.</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="mb-1 fw-semibold">Cycle Mechanics</p>
                        <ul class="mb-0 ps-3">
                            <li>Partial in-cycle payments reduce that cycle shortfall.</li>
                            <li>Only unpaid shortfall rolls to past due at closeout.</li>
                            <li>Early overpayment accelerates principal reduction.</li>
                            <li>Manual disbursement bypasses borrower eligibility checks.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pending Loan --}}
    <div class="card mt-4">
        <div class="card-header">Pending Loan Applications</div>
        <div class="card-body">
            @if($pendingLoans->isEmpty())
                <p>No pending loan requests.</p>
            @else
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Nation</th>
                        <th>Requested Amount</th>
                        <th>Requested Term (Weeks)</th>
                        <th>Requested At</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingLoans as $loan)
                        <tr>
                            <td>
                                @if ($loan->nation)
                                    <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}"
                                       target="_blank" rel="noopener noreferrer">
                                        {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                                    </a>
                                    <div class="small text-muted">
                                        {{ $loan->nation->nation_name ?? 'Unknown Nation' }}
                                    </div>
                                @else
                                    <span class="text-muted">Unknown Nation</span>
                                @endif
                            </td>
                            <td>${{ number_format($loan->amount) }}</td>
                            <td>{{ $loan->term_weeks }}</td>
                            <td>{{ $loan->created_at->format('M d, Y') }}</td>
                            <td>
                                <button class="btn btn-success btn-sm" data-bs-toggle="modal"
                                        data-bs-target="#approveLoanModal"
                                        onclick="setApproveLoanData({{ json_encode($loan) }})">
                                    Approve
                                </button>
                                <form action="{{ route('admin.loans.deny', $loan) }}" method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Deny</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Active Loan --}}
    <div class="card mt-4">
        <div class="card-header">Active Loans</div>
        <div class="card-body">
            @if($activeLoans->isEmpty())
                <p>No active loans.</p>
            @else
                <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Status</th>
                        <th>Nation</th>
                        <th>Principal</th>
                        <th>Interest Rate</th>
                        <th>Term</th>
                        <th>Scheduled Weekly Payment</th>
                        <th>Current Due</th>
                        <th>Cycle Paid / Remaining</th>
                        <th>Past Due</th>
                        <th>Interest Due Now</th>
                        <th>Total Owed Now</th>
                        <th>Remaining Principal</th>
                        <th>Next Due</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($activeLoans as $loan)
                        <tr>
                            <td>
                                @if ($loan->status === 'missed')
                                    <span class="badge text-bg-warning">Missed</span>
                                @else
                                    <span class="badge text-bg-success">Approved</span>
                                @endif
                            </td>
                            <td>
                                @if ($loan->nation)
                                    <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}"
                                       target="_blank" rel="noopener noreferrer">
                                        {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                                    </a>
                                    <div class="small text-muted">
                                        {{ $loan->nation->nation_name ?? 'Unknown Nation' }}
                                    </div>
                                @else
                                    <span class="text-muted">Unknown Nation</span>
                                @endif
                            </td>
                            <td>${{ number_format((float) $loan->amount, 2) }}</td>
                            <td>{{ number_format((float) $loan->interest_rate, 2) }}%</td>
                            <td>{{ (int) $loan->term_weeks }} weeks</td>
                            <td>${{ number_format((float) $loan->scheduled_weekly_payment, 2) }}</td>
                            <td>${{ number_format((float) $loan->current_amount_due, 2) }}</td>
                            <td>
                                <div>${{ number_format((float) $loan->cycle_paid, 2) }}</div>
                                <div class="small text-muted">remaining ${{ number_format((float) $loan->cycle_remaining, 2) }}</div>
                            </td>
                            <td>${{ number_format((float) $loan->past_due_amount, 2) }}</td>
                            <td>${{ number_format((float) $loan->effective_interest_due_now, 2) }}</td>
                            <td>${{ number_format((float) $loan->total_owed_now, 2) }}</td>
                            <td>${{ number_format((float) $loan->remaining_balance, 2) }}</td>
                            <td>
                                <div>{{ optional($loan->next_due_date)->format('M d, Y') ?? 'N/A' }}</div>
                                @if (! is_null($loan->days_to_due))
                                    <div class="small text-muted">
                                        @if ($loan->days_to_due > 0)
                                            in {{ $loan->days_to_due }} days
                                        @elseif ($loan->days_to_due === 0)
                                            due today
                                        @else
                                            {{ abs($loan->days_to_due) }} days past due date
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.loans.view', $loan) }}" class="btn btn-primary btn-sm">
                                    View Loan
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>

    @can('manage-loans')
        <div class="card mt-4">
            <div class="card-header">Manual Loan Disbursement</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Creates and approves a loan immediately. This bypasses borrower eligibility and duplicate checks—use when an admin needs to push funds without an application.
                </p>
                <form method="POST" action="{{ route('admin.manual-disbursements.loans') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Nation ID</label>
                            <input type="number" name="nation_id" class="form-control" required min="1" value="{{ old('nation_id') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Account ID</label>
                            <input type="number" name="account_id" class="form-control" required min="1" value="{{ old('account_id') }}">
                            <small class="text-muted">Must belong to the nation above.</small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" class="form-control" required step="0.01" min="1" value="{{ old('amount') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Interest %</label>
                            <input type="number" name="interest_rate" class="form-control" required step="0.01" min="0" max="100" value="{{ old('interest_rate') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Term (weeks)</label>
                            <input type="number" name="term_weeks" class="form-control" required min="1" max="52" value="{{ old('term_weeks') }}">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button class="btn btn-primary" type="submit">Send Loan</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @can('manage-loans')
        <div class="row g-3 mt-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Default Loan Interest Rate</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.loans.default-interest-rate') }}">
                            @csrf
                            <label class="form-label" for="default_interest_rate">Default Interest Rate (%)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="default_interest_rate"
                                       name="default_interest_rate" step="0.01" min="0" max="100" required
                                       value="{{ old('default_interest_rate', number_format($defaultLoanInterestRate, 2, '.', '')) }}">
                                <button class="btn btn-primary" type="submit">Update Default</button>
                            </div>
                            <small class="text-muted d-block mt-2">Used to prefill approvals; each loan can still be adjusted.</small>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Loan Applications</span>
                        <span class="badge {{ $loanApplicationsEnabled ? 'text-bg-success' : 'text-bg-warning' }}">
                            {{ $loanApplicationsEnabled ? 'Open' : 'Closed' }}
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Control whether members can submit new loan applications. Existing loans can still be repaid, and
                            manual disbursements remain available.
                        </p>
                        <form method="POST" action="{{ route('admin.loans.applications') }}">
                            @csrf
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="loan_applications_enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="loanApplicationsEnabled"
                                       name="loan_applications_enabled" value="1" @checked($loanApplicationsEnabled)>
                                <label class="form-check-label" for="loanApplicationsEnabled">Accept New Loan Applications</label>
                            </div>
                            <button class="btn btn-primary">Save Loan Application Setting</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endcan

    {{-- Approve Loan Modal --}}
    <div class="modal fade" id="approveLoanModal" tabindex="-1" aria-labelledby="approveLoanModalLabel"
         aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveLoanModalLabel">Approve Loan Application</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="approveLoanForm" method="POST">
                        @csrf
                        <input type="hidden" name="loan_id" id="loan_id">

                        <div class="mb-3">
                            <label for="approve_amount" class="form-label">Loan Amount</label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="approve_amount"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="approve_interest_rate" class="form-label">Interest Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control"
                                   name="interest_rate" id="approve_interest_rate" required>
                        </div>

                        <div class="mb-3">
                            <label for="approve_term_weeks" class="form-label">Term (Weeks)</label>
                            <input type="number" min="0" max="52" class="form-control" name="term_weeks"
                                   id="approve_term_weeks" required>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Approve Loan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection

@section("scripts")
    <script>
        const defaultLoanInterestRate = {{ json_encode($defaultLoanInterestRate) }};

        function setApproveLoanData(loan) {
            document.getElementById('approveLoanForm').action = `{{ url('admin/loans') }}/${loan.id}/approve`;

            document.getElementById('loan_id').value = loan.id;
            document.getElementById('approve_amount').value = loan.amount;
            const interestRate = loan.interest_rate ?? defaultLoanInterestRate;
            document.getElementById('approve_interest_rate').value =
                interestRate !== null && interestRate !== undefined ? interestRate : '';
            document.getElementById('approve_term_weeks').value = loan.term_weeks;
        }
    </script>
@endsection
