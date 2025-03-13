@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            Loans
        </h1>
    </div>

    {{-- Loan Request Form --}}
    <div class="mt-8">
        <x-utils.card title="Apply for a Loan" extraClasses="shadow-lg">
            <form method="POST" action="{{ route('loans.apply') }}">
                @csrf

                <label class="label" for="amount">Loan Amount:</label>
                <input type="number" name="amount" id="amount" min="1" required
                       placeholder="Enter loan amount" class="input input-bordered w-full mb-4">

                <label class="label" for="account_id">Select Bank Account:</label>
                <select name="account_id" id="account_id" class="select select-bordered w-full mb-4">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }} (Balance:
                            ${{ number_format($account->money, 2) }})
                        </option>
                    @endforeach
                </select>

                <label class="label" for="term_weeks">Loan Term (Weeks):</label>
                <input type="number" id="term_weeks" name="term_weeks" min="4" max="52" required
                       placeholder="Enter term length" class="input input-bordered w-full mb-4">

                <button type="submit" class="btn btn-primary w-full">
                    Apply for Loan
                </button>
            </form>
        </x-utils.card>
    </div>

    <hr class="mt-4 mb-4">

    {{-- Loan Repayment Form (Only show if there are active loans) --}}
    @if (!$activeLoans->isEmpty())
        <x-utils.card title="Make an Early Loan Payment">
            <form method="POST" action="{{ route('loans.repay') }}">
                @csrf
                <label class="label">Select Loan:</label>
                <select name="loan_id" class="select select-bordered w-full mb-4">
                    @foreach ($activeLoans as $loan)
                        <option value="{{ $loan->id }}">Loan #{{ $loan->id }} - Balance:
                            ${{ number_format($loan->remaining_balance, 2) }}</option>
                    @endforeach
                </select>

                <label class="label" for="account_id">Select Payment Account:</label>
                <select name="account_id" id="account_id" class="select select-bordered w-full mb-4">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }} (Balance:
                            ${{ number_format($account->money, 2) }})
                        </option>
                    @endforeach
                </select>

                <label class="label">Payment Amount:</label>
                <input type="number" name="amount" min="1" required placeholder="Enter amount"
                       class="input input-bordered w-full mb-4">

                <button type="submit" class="btn btn-primary w-full">Submit Payment</button>
            </form>
        </x-utils.card>

        <hr class="mt-4 mb-4">

        {{-- Active Loans Section --}}
        <x-utils.card title="Your Active Loans">
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Loan Amount</th>
                        <th>Remaining Balance</th>
                        <th>Interest Rate</th>
                        <th>Term (Weeks)</th>
                        <th>Next Payment Due</th>
                        <th>Next Minimum Payment</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($activeLoans as $loan)
                        <tr>
                            <td>{{ $loan->id }}</td>
                            <td>${{ number_format($loan->amount, 2) }}</td>
                            <td>${{ number_format($loan->remaining_balance, 2) }}</td>
                            <td>{{ number_format($loan->interest_rate, 2) }}%</td>
                            <td>{{ $loan->term_weeks }}</td>
                            <td>{{ $loan->next_due_date ? $loan->next_due_date->format('M d, Y') : 'N/A' }}</td>
                            <td>${{ number_format($loan->next_payment_due, 2) }}</td>
                        </tr>

                        {{-- Loan Payment History --}}
                        @if (!$loan->payments->isEmpty())
                            <tr>
                                <td colspan="7">
                                    <div class="mt-2">
                                        <strong>Payment History:</strong>
                                        <div class="overflow-x-auto">
                                            <table class="table table-sm w-full mt-2">
                                                <thead>
                                                <tr>
                                                    <th>Amount</th>
                                                    <th>Principal Paid</th>
                                                    <th>Interest Paid</th>
                                                    <th>Payment Date</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach ($loan->payments as $payment)
                                                    <tr>
                                                        <td>${{ number_format($payment->amount, 2) }}</td>
                                                        <td>${{ number_format($payment->principal_paid, 2) }}</td>
                                                        <td>${{ number_format($payment->interest_paid, 2) }}</td>
                                                        <td>{{ \Carbon\Carbon::create($payment->payment_date)->format('M d, Y') }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-utils.card>
    @endif

    {{-- Loan History --}}
    <div class="mt-4">
        <x-utils.card title="Your Loan History" extraClasses="shadow-lg">
            @if ($loanHistory->isEmpty())
                <p class="text-gray-500">No previous loan applications found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                        <tr>
                            <th>Amount</th>
                            <th>Term (Weeks)</th>
                            <th>Status</th>
                            <th>Requested At</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($loanHistory as $loan)
                            <tr>
                                <td>${{ number_format($loan->amount, 2) }}</td>
                                <td>{{ $loan->term_weeks }}</td>
                                <td>
                                    @if ($loan->status === 'pending')
                                        <span class="badge badge-warning">Pending</span>
                                    @elseif ($loan->status === 'approved')
                                        <span class="badge badge-success">Approved</span>
                                    @elseif ($loan->status === 'paid')
                                        <span class="badge badge-primary">Paid</span>
                                    @else
                                        <span class="badge badge-error">Denied</span>
                                    @endif
                                </td>
                                <td>{{ $loan->created_at->format('M d, Y') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-utils.card>
    </div>
@endsection