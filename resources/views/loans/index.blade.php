@php use Carbon\Carbon; @endphp
@extends('layouts.main')

@section("content")
    <div class="mx-auto space-y-8">
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow-md">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Funding desk</p>
                    <h1 class="text-3xl font-bold leading-tight">Loans</h1>
                    <p class="text-sm text-base-content/70">Apply, repay early, and monitor your history with one modern view.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <div class="rounded-xl bg-primary/10 border border-primary/30 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/70">Active loans</p>
                        <p class="text-xl font-bold">{{ $activeLoans->count() }}</p>
                    </div>
                    <div class="rounded-xl bg-base-200 border border-base-300 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/70">Requests on file</p>
                        <p class="text-xl font-bold">{{ $loanHistory->count() }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1.1fr,0.9fr]">
            <x-utils.card title="Apply for a loan" extraClasses="shadow-lg">
                <form method="POST" action="{{ route('loans.apply') }}" class="space-y-4">
                    @csrf

                    <div class="form-control">
                        <label class="label" for="amount">
                            <span class="label-text font-semibold">Loan amount</span>
                            <span class="label-text-alt text-base-content/60">USD</span>
                        </label>
                        <input type="number"
                               name="amount"
                               id="amount"
                               min="100000"
                               step="0.01"
                               required
                               placeholder="Enter loan amount"
                               class="input input-bordered w-full">
                    </div>

                    <div class="form-control">
                        <label class="label" for="account_id">
                            <span class="label-text font-semibold">Deposit into account</span>
                            <span class="label-text-alt text-base-content/60">Balance shown</span>
                        </label>
                        <select name="account_id" id="account_id" class="select select-bordered w-full">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" data-balance="{{ $account->money }}">
                                    {{ $account->name }} (Balance: ${{ number_format($account->money, 2) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label" for="term_weeks">
                            <span class="label-text font-semibold">Loan term (weeks)</span>
                            <span class="label-text-alt text-base-content/60">1-52</span>
                        </label>
                        <input type="number"
                               id="term_weeks"
                               name="term_weeks"
                               min="1"
                               max="52"
                               step="1"
                               required
                               placeholder="Enter term length (1-52 weeks)"
                               class="input input-bordered w-full">
                    </div>

                    <button type="submit" class="btn btn-primary w-full">
                        Apply for loan
                    </button>
                </form>
            </x-utils.card>

            @if (!$activeLoans->isEmpty())
                <x-utils.card title="Make an early payment" extraClasses="shadow-lg">
                    <form method="POST" action="{{ route('loans.repay') }}" class="space-y-3">
                        @csrf
                        <div class="form-control">
                            <label class="label">Select loan</label>
                            <select name="loan_id" id="repayment_loan_id" class="select select-bordered w-full">
                                @foreach ($activeLoans as $loan)
                                    <option value="{{ $loan->id }}" data-balance="{{ $loan->remaining_balance }}">Loan
                                        #{{ $loan->id }} - Balance:
                                        ${{ number_format($loan->remaining_balance, 2) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label" for="repayment_account_id">Payment account</label>
                            <select name="account_id" id="repayment_account_id" class="select select-bordered w-full">
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" data-balance="{{ $account->money }}">{{ $account->name }}
                                        (Balance:
                                        ${{ number_format($account->money, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label" for="repayment_amount">Payment amount</label>
                            <input type="number"
                                   name="amount"
                                   id="repayment_amount"
                                   min="0.01"
                                   step="0.01"
                                   required
                                   placeholder="Enter repayment amount"
                                   class="input input-bordered w-full">
                        </div>

                        <button type="submit" class="btn btn-success w-full">Submit payment</button>
                    </form>
                </x-utils.card>
            @else
                <x-utils.card title="No active loans" extraClasses="h-full">
                    <p class="text-sm text-base-content/70">Submit a new application to see repayment options here.</p>
                </x-utils.card>
            @endif
        </div>

        @if (!$activeLoans->isEmpty())
            <x-utils.card title="Your Active Loans" extraClasses="shadow-lg">
                <div class="overflow-x-auto rounded-xl border border-base-300">
                    <table class="table table-zebra w-full">
                        <thead class="bg-base-200">
                        <tr>
                            <th>ID</th>
                            <th>Loan amount</th>
                            <th>Remaining balance</th>
                            <th>Interest rate</th>
                            <th>Term (weeks)</th>
                            <th>Account</th>
                            <th>Next payment due</th>
                            <th>Next minimum payment</th>
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
                                <td>
                                    <a href="{{ route('accounts.view', $loan->account->id) }}"
                                       class="link link-primary">{{ $loan->account->name }}</a>
                                </td>
                                <td>{{ $loan->next_due_date ? $loan->next_due_date->format('M d, Y') : 'N/A' }}</td>
                                <td>${{ number_format($loan->next_payment_due, 2) }}</td>
                            </tr>

                            @if (!$loan->payments->isEmpty())
                                <tr>
                                    <td colspan="8" class="bg-base-200/60">
                                        <div class="mt-2 space-y-2">
                                            <div class="font-semibold text-sm">Payment history</div>
                                            <div class="overflow-x-auto rounded-lg border border-base-300 bg-base-100">
                                                <table class="table table-sm">
                                                    <thead class="bg-base-200">
                                                    <tr>
                                                        <th>Amount</th>
                                                        <th>Principal paid</th>
                                                        <th>Interest paid</th>
                                                        <th>Payment date</th>
                                                        <th>Account</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    @foreach ($loan->payments as $payment)
                                                        <tr>
                                                            <td>${{ number_format($payment->amount, 2) }}</td>
                                                            <td>${{ number_format($payment->principal_paid, 2) }}</td>
                                                            <td>${{ number_format($payment->interest_paid, 2) }}</td>
                                                            <td>{{ Carbon::create($payment->payment_date)->format('M d, Y') }}</td>
                                                            <td>
                                                                <a href="{{ route('accounts.view', $payment->account->id) }}"
                                                                   class="link link-primary">{{ $payment->account->name }}</a>
                                                            </td>
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

        <script>
            function switchTab(tab, element) {
                document.querySelectorAll(".tab").forEach(t => t.classList.remove("tab-active"));
                document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));

                element.classList.add("tab-active");
                document.getElementById(`content-${tab}`).classList.remove("hidden");
            }

            document.addEventListener("DOMContentLoaded", function () {
                document.querySelector(".tab-active")?.click();
            });

            document.addEventListener('DOMContentLoaded', function () {
                const amountInput = document.getElementById('amount');
                if (amountInput) {
                    amountInput.addEventListener('change', function () {
                        let value = parseFloat(this.value);
                        if (!isNaN(value) && value < 0.01) {
                            this.value = 0.01;
                        }
                    });
                }

                const termInput = document.getElementById('term_weeks');
                if (termInput) {
                    termInput.addEventListener('change', function () {
                        if (this.value !== '') {
                            let value = parseInt(this.value);
                            if (!isNaN(value)) {
                                value = Math.round(value);
                                if (value < 1) value = 1;
                                if (value > 52) value = 52;
                                this.value = value;
                            }
                        }
                    });
                }
            });
        </script>

        <x-utils.card title="Your loan history" extraClasses="shadow-lg">
            @if ($loanHistory->isEmpty())
                <p class="text-base-content/70">No previous loan applications found.</p>
            @else
                <div class="overflow-x-auto rounded-xl border border-base-300">
                    <table class="table table-zebra w-full">
                        <thead class="bg-base-200">
                        <tr>
                            <th>Amount</th>
                            <th>Term (Weeks)</th>
                            <th>Account</th>
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
                                    @if($loan->account)
                                        <a href="{{ route('accounts.view', $loan->account->id) }}"
                                           class="link link-primary">{{ $loan->account->name }}</a>
                                    @else
                                        N/A
                                    @endif
                                </td>
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

        <x-utils.card title="How loans work">
            <div class="steps w-full mb-6">
                <div class="step step-primary">Apply</div>
                <div class="step step-primary">Approval</div>
                <div class="step step-primary">Repayment</div>
                <div class="step step-primary">Completion</div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="bg-base-200 p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-bold mb-2">📌 Step 1: Applying for a Loan</h3>
                    <p class="text-sm">To apply for a loan, follow these steps:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>Choose the amount you want to borrow.</li>
                        <li>Select the account where the funds will be deposited.</li>
                        <li>Pick a repayment term (between 1 and 52 weeks).</li>
                        <li>Submit your request and wait for admin approval.</li>
                    </ul>
                </div>

                <div class="bg-base-200 p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-bold mb-2">📌 Step 2: Loan Approval</h3>
                    <p class="text-sm">Once submitted, your loan request will be reviewed by an admin:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>Admins evaluate applications based on eligibility.</li>
                        <li>Approval will specify the final interest rate and terms.</li>
                        <li>If approved, the loan funds will be deposited into your selected account.</li>
                    </ul>
                </div>

                <div class="bg-base-200 p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-bold mb-2">📌 Step 3: Repayment</h3>
                    <p class="text-sm">Your loan repayments will occur as follows:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>Payments are automatically deducted from your bank account weekly.</li>
                        <li>You can make early payments to reduce interest costs.</li>
                        <li>Missed payments will result in penalties and potential loan restrictions.</li>
                    </ul>
                </div>

                <div class="bg-base-200 p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-bold mb-2">📌 Step 4: Completion</h3>
                    <p class="text-sm">Once your loan is fully repaid:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>Your status will be updated to <span class="badge badge-primary">Paid</span></li>
                    </ul>
                </div>
            </div>

            <div class="bg-base-200 p-6 rounded-lg shadow-md mt-4">
                <h3 class="text-lg font-bold mb-2">📊 How Interest is Calculated</h3>
                <p class="text-sm">Loan interest is applied weekly based on the outstanding balance. The scheduled payment uses:</p>
                <div class="mockup-code mt-3">
                    <pre data-prefix="$"><code>Weekly Payment = (Interest Rate × Principal) / (1 - (1 + Interest Rate)⁻ⁿ)</code></pre>
                </div>
                <p class="mt-3 text-sm">
                    Where:
                </p>
                <ul class="list-disc ml-5 text-sm space-y-1">
                    <li><strong>Principal</strong> = The original amount borrowed.</li>
                    <li><strong>Interest Rate</strong> = The admin-approved weekly rate.</li>
                    <li><strong>n</strong> = Loan term in weeks.</li>
                </ul>
                <p class="mt-3 text-sm">
                    Interest is charged weekly on the remaining balance, so early payments reduce how much interest is paid over time.
                </p>
            </div>

            <div class="bg-base-200 p-6 rounded-lg shadow-md mt-4">
                <h3 class="text-lg font-bold mb-2">📆 How Due Dates Work</h3>
                <p class="text-sm">Loan payments are scheduled weekly and follow this structure:</p>
                <ul class="list-disc ml-5 text-sm space-y-1">
                    <li><strong>First payment is due 7 days after approval.</strong></li>
                    <li>Subsequent minimum payments are automatically deducted from the account you selected every 7 days.
                    </li>
                    <li>If an early payment is made, the next minimum payment is reduced (future weeks stay on schedule).</li>
                    <li>Missed payments may result in late fees and increased interest.</li>
                </ul>
            </div>
        </x-utils.card>
    </div>
@endsection
