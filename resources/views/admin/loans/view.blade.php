@php use Carbon\Carbon; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">View Loan #{{ $loan->id }} - <a
                                href="https://politicsandwar.com/nation/id={{ $loan->nation_id }}"
                                target="_blank">{{ $loan->nation->leader_name }}</a></h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Loan Summary Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Loan Amount"
                              :value="number_format($loan->amount, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-piggy-bank" bgColor="text-bg-primary" title="Remaining Balance"
                              :value="number_format($loan->remaining_balance, 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-calendar" bgColor="text-bg-warning" title="Next Payment Due"
                              :value="$loan->next_due_date ? $loan->next_due_date->format('M d, Y') : 'N/A'"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-credit-card" bgColor="text-bg-danger" title="Minimum Payment Due"
                              :value="number_format($nextMinimumPayment, 2)"/>
        </div>
    </div>

    {{-- Loan Edit Form --}}
    <div class="card mt-4">
        <div class="card-header">Modify Loan Details</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.loans.update', $loan) }}">
                @csrf

                <div class="mb-3">
                    <label for="amount" class="form-label">Loan Amount</label>
                    <input type="number" name="amount" id="amount" min="1"
                           value="{{ $loan->amount }}" class="form-control"
                           @if($loan->payments()->exists()) disabled @endif>
                    @if($loan->payments()->exists())
                        <p class="text-sm text-muted">If there have been payments towards this loan, you cannot modify
                            the loan amount</p>
                    @endif
                </div>

                <div class="mb-3">
                    <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                    <input type="number" name="interest_rate" id="interest_rate" min="0" max="100" step="0.01"
                           value="{{ $loan->interest_rate }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label for="term_weeks" class="form-label">Loan Term (Weeks)</label>
                    <input type="number" name="term_weeks" id="term_weeks" min="1" max="104"
                           value="{{ $loan->term_weeks }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label for="next_due_date" class="form-label">Next Payment Due Date</label>
                    <input type="date" name="next_due_date" id="next_due_date"
                           value="{{ $loan->next_due_date->format('Y-m-d') }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label for="remaining_balance" class="form-label">Remaining Balance</label>
                    <input type="number" name="remaining_balance" id="remaining_balance" min="0" step="0.01"
                           max="{{ $loan->amount }}"
                           value="{{ $loan->remaining_balance }}" class="form-control">
                </div>

                <button type="submit" class="btn btn-primary w-full">Update Loan</button>
            </form>
            {{-- Mark Loan as Paid Button --}}
            <form method="POST" action="{{ route('admin.loans.markPaid', $loan) }}" class="mt-4">
                @csrf
                <button type="submit" class="btn btn-danger w-full"
                        onclick="return confirm('Are you sure you want to mark this loan as fully paid? This action cannot be undone.')">
                    Mark Loan as Paid
                </button>
            </form>
        </div>
    </div>

    {{-- Loan Payment History --}}
    <div class="card mt-4">
        <div class="card-header">Loan Payment History</div>
        <div class="card-body">
            @if ($loan->payments->isEmpty())
                <p class="text-gray-500">No payments have been made for this loan.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Amount</th>
                            <th>Principal Paid</th>
                            <th>Interest Paid</th>
                            <th>Paid From Account</th>
                            <th>Payment Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($loan->payments as $payment)
                            <tr>
                                <td>{{ $payment->id }}</td>
                                <td>${{ number_format($payment->amount, 2) }}</td>
                                <td>${{ number_format($payment->principal_paid, 2) }}</td>
                                <td>${{ number_format($payment->interest_paid, 2) }}</td>
                                <td>{{ $payment->account->name ?? 'N/A' }}</td>
                                <td>{{ Carbon::create($payment->payment_date)->format('M d, Y') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection