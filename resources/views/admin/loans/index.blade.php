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
        function setApproveLoanData(loan) {
            document.getElementById('approveLoanForm').action = `{{ url('admin/loans') }}/${loan.id}/approve`;

            document.getElementById('loan_id').value = loan.id;
            document.getElementById('approve_amount').value = loan.amount;
            document.getElementById('approve_interest_rate').value = loan.interest_rate || '';
            document.getElementById('approve_term_weeks').value = loan.term_weeks;
        }
    </script>
@endsection
