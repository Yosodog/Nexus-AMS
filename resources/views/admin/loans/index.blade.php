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
                              :value="number_format($totalLoanedFunds)"/>
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
                            <td>{{ $loan->nation?->nation_name ?? 'Unknown Nation' }}</td>
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
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Nation</th>
                        <th>Loan Amount</th>
                        <th>Interest Rate</th>
                        <th>Term (Weeks)</th>
                        <th>Remaining Balance</th>
                        <th>Next Payment Due</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($activeLoans as $loan)
                        <tr>
                            <td>{{ $loan->nation?->nation_name ?? 'Unknown Nation' }}</td>
                            <td>${{ number_format($loan->amount) }}</td>
                            <td>{{ number_format($loan->interest_rate, 2) }}%</td>
                            <td>{{ $loan->term_weeks }}</td>
                            <td>${{ number_format($loan->remaining_balance) }}</td>
                            <td>{{ optional($loan->next_due_date)->format('M d, Y') ?? 'N/A' }}</td>
                            <td>
                                <a href="{{ route('admin.loans.view', $loan) }}" class="btn btn-primary btn-sm">
                                    Edit Loan
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
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
