@php use Carbon\Carbon; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-9">
                    <h3 class="mb-1">
                        Loan #{{ $loan->id }} -
                        <a href="https://politicsandwar.com/nation/id={{ $loan->nation_id }}" target="_blank" rel="noopener noreferrer">
                            {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation_id) }}
                        </a>
                    </h3>
                    <p class="text-muted mb-0">
                        Status:
                        @if ($loan->status === 'missed')
                            <span class="badge text-bg-warning">Missed</span>
                        @elseif ($loan->status === 'approved')
                            <span class="badge text-bg-success">Approved</span>
                        @elseif ($loan->status === 'paid')
                            <span class="badge text-bg-primary">Paid</span>
                        @else
                            <span class="badge text-bg-secondary">{{ ucfirst($loan->status) }}</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if (! $loanPaymentsEnabled)
        <div class="alert alert-warning mt-2">
            <i class="bi bi-pause-circle me-2"></i>
            Loan payments are paused globally. Weekly accrual and required due are frozen until resumed.
        </div>
    @endif

    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Original Principal"
                              :value="number_format((float) $loan->amount, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-piggy-bank" bgColor="text-bg-primary" title="Remaining Principal"
                              :value="number_format((float) $loan->remaining_balance, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-arrow-repeat" bgColor="text-bg-info" title="Scheduled Weekly Payment"
                              :value="number_format((float) $scheduledWeeklyPayment, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-credit-card" bgColor="text-bg-danger" title="Current Required Payment"
                              :value="number_format((float) $nextMinimumPayment, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-exclamation-triangle" bgColor="text-bg-warning" title="Past Due Amount"
                              :value="number_format((float) $loan->past_due_amount, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-percent" bgColor="text-bg-secondary" title="Interest Due Now"
                              :value="number_format((float) ($fullPayoffPreview['interest'] ?? 0), 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-wallet2" bgColor="text-bg-dark" title="Total Payoff Now"
                              :value="number_format((float) (($fullPayoffPreview['interest'] ?? 0) + $loan->remaining_balance), 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-calendar" bgColor="text-bg-warning" title="Next Due Date"
                              :value="$loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('M d, Y') : 'N/A'"/>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Loan Operations Summary</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="fw-semibold mb-2">Cycle Progress</p>
                        <p class="mb-1">
                            Cycle window:
                            <strong>
                                {{ $cycleProgress['cycle_start'] ? Carbon::parse($cycleProgress['cycle_start'])->format('M d') : 'N/A' }}
                                -
                                {{ $cycleProgress['cycle_end'] ? Carbon::parse($cycleProgress['cycle_end'])->format('M d') : 'N/A' }}
                            </strong>
                        </p>
                        <p class="mb-1">Paid this cycle: <strong>${{ number_format((float) $cycleProgress['paid_this_cycle'], 2) }}</strong></p>
                        <p class="mb-0">Remaining to scheduled target: <strong>${{ number_format((float) $cycleProgress['remaining_to_scheduled'], 2) }}</strong></p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="fw-semibold mb-2">Next Required Payment Split</p>
                        <p class="mb-1">Required now: <strong>${{ number_format((float) $nextMinimumPayment, 2) }}</strong></p>
                        <p class="mb-1">Interest portion: <strong>${{ number_format((float) ($nextPaymentPreview['interest'] ?? 0), 2) }}</strong></p>
                        <p class="mb-0">Principal portion: <strong>${{ number_format((float) ($nextPaymentPreview['principal'] ?? 0), 2) }}</strong></p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="fw-semibold mb-2">Payment History Totals</p>
                        <p class="mb-1">Total paid: <strong>${{ number_format((float) $totals['paid_total'], 2) }}</strong></p>
                        <p class="mb-1">Principal paid: <strong>${{ number_format((float) $totals['paid_principal'], 2) }}</strong></p>
                        <p class="mb-0">Interest paid: <strong>${{ number_format((float) $totals['paid_interest'], 2) }}</strong></p>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <p class="fw-semibold mb-2">Contract Context</p>
                        <p class="mb-1">Approved at: <strong>{{ $loan->approved_at ? Carbon::parse($loan->approved_at)->format('M d, Y') : 'N/A' }}</strong></p>
                        <p class="mb-1">Weeks elapsed: <strong>{{ min($weeksElapsed, (int) $loan->term_weeks) }}</strong> / {{ (int) $loan->term_weeks }}</p>
                        <p class="mb-0">Weekly rate: <strong>{{ number_format((float) $loan->interest_rate, 2) }}%</strong></p>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="border rounded p-3 h-100">
                        <p class="fw-semibold mb-2">Accounting Rules in Effect</p>
                        <ul class="mb-0 ps-3">
                            <li>Interest accrues weekly at cycle close (not daily).</li>
                            <li>Cycle interest is locked to cycle-opening principal.</li>
                            <li>Payments apply to accrued interest first, then principal.</li>
                            <li>Only cycle shortfall rolls into past due.</li>
                            <li>No penalty fees are currently added on missed cycles.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Modify Loan Details</div>
        <div class="card-body">
            @php
                $loanIsImmutable = $loan->payments->isNotEmpty();
            @endphp

            @if ($loanIsImmutable)
                <div class="alert alert-warning">
                    <p class="mb-1 fw-semibold">This loan is locked because payments already exist.</p>
                    <p class="mb-1">To preserve accounting integrity, terms and balances can no longer be edited directly.</p>
                    <p class="mb-0">
                        If a correction is required:
                        1) mark this loan as paid, and
                        2) create a replacement loan via manual disbursement with corrected terms/amount.
                    </p>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.loans.update', $loan) }}">
                @csrf

                <div class="mb-3">
                    <label for="amount" class="form-label">Loan Amount</label>
                    <input type="number" name="amount" id="amount" min="1"
                           value="{{ $loan->amount }}" class="form-control"
                           @if($loanIsImmutable) disabled @endif>
                    @if($loanIsImmutable)
                        <p class="text-sm text-muted mb-0">Locked after first payment.</p>
                    @endif
                </div>

                <div class="mb-3">
                    <label for="interest_rate" class="form-label">Weekly Interest Rate (%)</label>
                    <input type="number" name="interest_rate" id="interest_rate" min="0" max="100" step="0.01"
                           value="{{ $loan->interest_rate }}" class="form-control"
                           @if($loanIsImmutable) disabled @endif>
                </div>

                <div class="mb-3">
                    <label for="term_weeks" class="form-label">Loan Term (Weeks)</label>
                    <input type="number" name="term_weeks" id="term_weeks" min="1" max="52"
                           value="{{ $loan->term_weeks }}" class="form-control"
                           @if($loanIsImmutable) disabled @endif>
                </div>

                <div class="mb-3">
                    <label for="next_due_date" class="form-label">Next Payment Due Date</label>
                    <input type="date" name="next_due_date" id="next_due_date"
                           value="{{ $loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('Y-m-d') : '' }}" class="form-control"
                           @if($loanIsImmutable) disabled @endif>
                </div>

                <div class="mb-3">
                    <label for="remaining_balance" class="form-label">Remaining Principal Balance</label>
                    <input type="number" name="remaining_balance" id="remaining_balance" min="0" step="0.01"
                           max="{{ $loan->amount }}"
                           value="{{ $loan->remaining_balance }}" class="form-control"
                           @if($loanIsImmutable) disabled @endif>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4" @if($loanIsImmutable) disabled @endif>
                        Update Loan
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.loans.markPaid', $loan) }}" class="mt-4">
                @csrf
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-outline-danger px-4"
                            onclick="return confirm('Are you sure you want to mark this loan as fully paid? This action cannot be undone.')">
                        Mark Loan as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Contract Amortization Schedule</div>
        <div class="card-body">
            @if (empty($amortizationSchedule))
                <p class="text-muted mb-0">No schedule available for this loan.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Week</th>
                            <th>Scheduled Date</th>
                            <th>Opening Balance</th>
                            <th>Payment</th>
                            <th>Interest</th>
                            <th>Principal</th>
                            <th>Closing Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($amortizationSchedule as $row)
                            <tr>
                                <td>{{ $row['week'] }}</td>
                                <td>{{ ! empty($row['due_date']) ? Carbon::parse($row['due_date'])->format('M d, Y') : 'N/A' }}</td>
                                <td>${{ number_format((float) $row['opening_balance'], 2) }}</td>
                                <td>${{ number_format((float) $row['payment'], 2) }}</td>
                                <td>${{ number_format((float) $row['interest'], 2) }}</td>
                                <td>${{ number_format((float) $row['principal'], 2) }}</td>
                                <td>${{ number_format((float) $row['closing_balance'], 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Loan Payment History</div>
        <div class="card-body">
            @if ($loan->payments->isEmpty())
                <p class="text-muted mb-0">No payments have been made for this loan.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Amount</th>
                            <th>Interest Paid</th>
                            <th>Principal Paid</th>
                            <th>Paid From Account</th>
                            <th>Payment Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($loan->payments as $payment)
                            <tr>
                                <td>{{ $payment->id }}</td>
                                <td>${{ number_format((float) $payment->amount, 2) }}</td>
                                <td>${{ number_format((float) $payment->interest_paid, 2) }}</td>
                                <td>${{ number_format((float) $payment->principal_paid, 2) }}</td>
                                <td>{{ $payment->account->name ?? 'N/A' }}</td>
                                <td>{{ Carbon::parse($payment->payment_date)->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
