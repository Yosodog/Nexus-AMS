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

    {{-- Loan Information Section --}}
    <x-utils.card title="How Loans Work">
        <div class="steps w-full mb-6">
            <div class="step step-primary">Apply</div>
            <div class="step step-primary">Approval</div>
            <div class="step step-primary">Repayment</div>
            <div class="step step-primary">Completion</div>
        </div>

        <div class="bg-base-200 p-6 rounded-lg shadow-md">
            <h3 class="text-lg font-bold mb-2">📌 Step 1: Applying for a Loan</h3>
            <p>To apply for a loan, follow these steps:</p>
            <ul class="list-disc ml-5">
                <li>Choose the amount you want to borrow (minimum $100,000).</li>
                <li>Select the account where the funds will be deposited.</li>
                <li>Pick a repayment term (between 4 and 52 weeks).</li>
                <li>Submit your request and wait for admin approval.</li>
            </ul>
        </div>

        <div class="bg-base-200 p-6 rounded-lg shadow-md mt-4">
            <h3 class="text-lg font-bold mb-2">📌 Step 2: Loan Approval</h3>
            <p>Once submitted, your loan request will be reviewed by an admin:</p>
            <ul class="list-disc ml-5">
                <li>Admins evaluate applications based on eligibility.</li>
                <li>Approval will specify the final interest rate and terms.</li>
                <li>If approved, the loan funds will be deposited into your selected account.</li>
            </ul>
        </div>

        <div class="bg-base-200 p-6 rounded-lg shadow-md mt-4">
            <h3 class="text-lg font-bold mb-2">📌 Step 3: Repayment</h3>
            <p>Your loan repayments will occur as follows:</p>
            <ul class="list-disc ml-5">
                <li>Payments are automatically deducted from your bank account weekly.</li>
                <li>You can make early payments to reduce interest costs.</li>
                <li>Missed payments will result in penalties and potential loan restrictions.</li>
            </ul>
        </div>

        <div class="bg-base-200 p-6 rounded-lg shadow-md mt-4">
            <h3 class="text-lg font-bold mb-2">📌 Step 4: Completion</h3>
            <p>Once your loan is fully repaid:</p>
            <ul class="list-disc ml-5">
                <li>Your status will be updated to **Paid**.</li>
                <li>You will regain full borrowing privileges.</li>
                <li>Proper financial management ensures better rates in the future.</li>
            </ul>
        </div>
    </x-utils.card>

    <hr class="mt-4 mb-4">

    <script>
        function switchTab(tab, element) {
            // Remove active class from all tabs
            document.querySelectorAll(".tab").forEach(t => t.classList.remove("tab-active"));
            // Hide all content sections
            document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));

            // Activate the clicked tab
            element.classList.add("tab-active");
            // Show the relevant content
            document.getElementById(`content-${tab}`).classList.remove("hidden");
        }

        // Ensure the first tab is active on page load
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelector(".tab-active").click();
        });
    </script>

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