@extends('layouts.main')

@section('content')
    <div class="mx-auto space-y-8">
        <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow-md">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Funding Desk</p>
                    <h1 class="text-3xl font-bold leading-tight">Loans</h1>
                    <p class="text-sm text-base-content/70">Weekly amortized loans with transparent payment math.</p>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-base-300 bg-base-200 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/60">Active Loans</p>
                        <p class="text-xl font-bold">{{ $activeLoans->count() }}</p>
                    </div>
                    <div class="rounded-xl border border-base-300 bg-base-200 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/60">Outstanding Principal</p>
                        <p class="text-xl font-bold">${{ number_format((float) $activeLoans->sum('remaining_balance'), 2) }}</p>
                    </div>
                    <div class="rounded-xl border border-base-300 bg-base-200 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/60">Total Currently Due</p>
                        <p class="text-xl font-bold">${{ number_format((float) $activeLoans->sum('next_payment_due'), 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <x-utils.card title="Loan In 30 Seconds" extraClasses="shadow-lg">
            <div class="grid gap-4 text-sm text-base-content/85 md:grid-cols-2">
                <div class="rounded-xl border border-base-300 bg-base-200 p-4">
                    <p class="font-semibold">What happens</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>You receive money up front.</li>
                        <li>You owe a minimum payment every 7 days.</li>
                        <li>Each payment pays interest first, then principal.</li>
                        <li>As principal goes down, future interest gets smaller.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 bg-base-200 p-4">
                    <p class="font-semibold">If a payment is missed</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>The missed weekly amount moves into past due.</li>
                        <li>Weekly cycle interest continues to accrue.</li>
                        <li>No penalty fee is added right now.</li>
                        <li>Paying catches up interest first, then principal.</li>
                    </ul>
                </div>
            </div>
        </x-utils.card>

        @if (! $loanPaymentsEnabled)
            <div class="alert alert-warning shadow-md">
                <div>
                    <p class="font-semibold">Loan payments are paused.</p>
                    <p class="text-sm">
                        Scheduled deductions and weekly interest accrual are frozen. Due dates shift forward when payments resume.
                        @if ($loanPaymentsPausedAt)
                            Paused since {{ $loanPaymentsPausedAt->format('M d, Y H:i') }}.
                        @endif
                    </p>
                </div>
            </div>
        @endif

        @if (! $loanApplicationsEnabled)
            <div class="alert alert-warning shadow-md">
                <div>
                    <p class="font-semibold">Loan applications are currently closed.</p>
                    <p class="text-sm">You can still make payments on existing loans.</p>
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <x-utils.card title="Apply for a Loan" extraClasses="shadow-lg">
                @if ($loanApplicationsEnabled)
                    <form method="POST" action="{{ route('loans.apply') }}" class="space-y-4">
                        @csrf
                        <div class="form-control">
                            <label class="label" for="amount">
                                <span class="label-text font-semibold">Loan Amount</span>
                            </label>
                            <input type="number" name="amount" id="amount" min="100000" step="0.01" required
                                   class="input input-bordered w-full" placeholder="Enter amount">
                        </div>

                        <div class="form-control">
                            <label class="label" for="account_id">
                                <span class="label-text font-semibold">Deposit Account</span>
                            </label>
                            <select name="account_id" id="account_id" class="select select-bordered w-full" required>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->name }} (Balance: ${{ number_format((float) $account->money, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label" for="term_weeks">
                                <span class="label-text font-semibold">Term (Weeks)</span>
                            </label>
                            <input type="number" id="term_weeks" name="term_weeks" min="1" max="52" step="1" required
                                   class="input input-bordered w-full" placeholder="1-52">
                        </div>

                        <button type="submit" class="btn btn-primary w-full">Submit Application</button>
                    </form>
                @else
                    <p class="text-sm text-base-content/70">Applications are closed right now.</p>
                @endif
            </x-utils.card>

            <x-utils.card title="Make a Payment" extraClasses="shadow-lg">
                @if ($activeLoans->isEmpty())
                    <p class="text-sm text-base-content/70">No active loans available for repayment.</p>
                @else
                    <form method="POST" action="{{ route('loans.repay') }}" class="space-y-4" id="repayForm">
                        @csrf
                        <div class="form-control">
                            <label class="label" for="repayment_loan_id">
                                <span class="label-text font-semibold">Loan</span>
                            </label>
                            <select name="loan_id" id="repayment_loan_id" class="select select-bordered w-full" required>
                                @foreach ($activeLoans as $loan)
                                    <option value="{{ $loan->id }}">Loan #{{ $loan->id }} ({{ strtoupper($loan->status) }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label" for="repayment_account_id">
                                <span class="label-text font-semibold">Payment Account</span>
                            </label>
                            <select name="account_id" id="repayment_account_id" class="select select-bordered w-full" required>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->name }} (Balance: ${{ number_format((float) $account->money, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label" for="repayment_amount">
                                <span class="label-text font-semibold">Payment Amount</span>
                            </label>
                            <input type="number" name="amount" id="repayment_amount" min="0.01" step="0.01" required
                                   class="input input-bordered w-full" placeholder="Enter amount">
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <button type="button" id="use_min_due" class="btn btn-outline btn-sm">
                                    Use Minimum Due
                                </button>
                                <span id="use_min_due_hint" class="text-xs text-base-content/60">Current due: $0.00</span>
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="label" for="repayment_slider">
                                <span class="label-text font-semibold">What Happens If I Pay This Amount?</span>
                            </label>
                            <input type="range" id="repayment_slider" min="0" max="100" value="0" class="range range-primary">
                            <div class="mt-1 flex justify-between text-xs text-base-content/60">
                                <span>$0</span>
                                <span id="slider_max_label">$0</span>
                            </div>
                        </div>

                        <div class="rounded-xl border border-base-300 bg-base-200 p-4 text-sm">
                            <p class="font-semibold">Payment Preview</p>
                            <div class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                                <p>Current Amount Due: <span id="preview_due" class="font-semibold">$0.00</span></p>
                                <p>Total Owed Right Now: <span id="preview_total" class="font-semibold">$0.00</span></p>
                                <p>Estimated Interest: <span id="preview_interest" class="font-semibold">$0.00</span></p>
                                <p>Estimated Principal: <span id="preview_principal" class="font-semibold">$0.00</span></p>
                                <p>Estimated Remaining Principal: <span id="preview_remaining" class="font-semibold">$0.00</span></p>
                                <p>Estimated Weeks Saved: <span id="preview_weeks_saved" class="font-semibold">0</span></p>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success w-full">Submit Payment</button>
                    </form>
                @endif
            </x-utils.card>
        </div>

        <x-utils.card title="Loan Formula and Rules" extraClasses="shadow-lg">
            <div class="space-y-4 text-sm text-base-content/80">
                <p>
                    The scheduled weekly payment uses standard amortization with a weekly rate:
                </p>
                <div class="mockup-code">
                    <pre data-prefix="$"><code>Payment = (r * P) / (1 - (1 + r)^(-n))</code></pre>
                </div>
                <ul class="list-disc space-y-1 pl-5">
                    <li><strong>P</strong> = original principal</li>
                    <li><strong>r</strong> = weekly interest rate (decimal form, e.g. 2% = 0.02)</li>
                    <li><strong>n</strong> = total number of weekly payments</li>
                    <li>Each payment is applied interest first, then principal.</li>
                    <li>Interest accrues on a weekly cycle, not daily.</li>
                    <li>If a payment is missed, due amounts roll forward and continue to accumulate.</li>
                    <li>Early repayments are allowed and reduce principal immediately.</li>
                </ul>
            </div>
        </x-utils.card>

        <x-utils.card title="How Loan Payments Work" extraClasses="shadow-lg">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-base-300 bg-base-200 p-4 text-sm">
                    <h3 class="text-base font-semibold">Payment Cycle</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-base-content/80">
                        <li>First payment is due 7 days after approval.</li>
                        <li>Then payments are due every 7 days.</li>
                        <li>Current required payment can be $0 before the first due date.</li>
                        <li>Weekly interest is posted when the cycle reaches its due date.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 bg-base-200 p-4 text-sm">
                    <h3 class="text-base font-semibold">If Payments Are Frozen</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-base-content/80">
                        <li>Automatic deductions are paused.</li>
                        <li>Weekly accrual and required dues are frozen while paused.</li>
                        <li>On resume, due dates are shifted forward by the paused duration.</li>
                        <li>You may still make manual early repayments while frozen.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 bg-base-200 p-4 text-sm">
                    <h3 class="text-base font-semibold">Missed Payments (No Penalties)</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-base-content/80">
                        <li>Missed installments are moved into past due.</li>
                        <li>Interest for each missed weekly cycle is added to accrued interest due.</li>
                        <li>No extra penalty fee is added right now.</li>
                        <li>Next payment first clears accrued interest, then principal.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 bg-base-200 p-4 text-sm">
                    <h3 class="text-base font-semibold">Early Repayment</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-base-content/80">
                        <li>You can pay early at any time.</li>
                        <li>Early payments immediately reduce principal (after any accrued interest due).</li>
                        <li>Lower principal reduces future weekly interest.</li>
                        <li>Payoff is allowed up to total currently owed.</li>
                    </ul>
                </div>
            </div>
        </x-utils.card>

        <x-utils.card title="Glossary" extraClasses="shadow-lg">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Principal</p><p>The remaining borrowed amount.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Interest</p><p>The borrowing cost based on weekly rate.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Scheduled Weekly Payment</p><p>The formula-based weekly target payment.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Current Required Payment</p><p>The minimum needed to stay current right now.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Past Due</p><p>Missed installments rolled forward.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Accrued Interest Due</p><p>Interest earned but not yet paid.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Amortization</p><p>Repayment where each payment splits into interest and principal.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Payoff</p><p>Total required to close the loan now.</p></div>
                <div class="rounded-lg border border-base-300 bg-base-200 p-3"><p class="font-semibold">Weeks Saved</p><p>Estimated reduction in remaining term from extra payment.</p></div>
            </div>
        </x-utils.card>

        @if (! $activeLoans->isEmpty())
            <x-utils.card title="Active Loans and Amortization" extraClasses="shadow-lg">
                <div class="space-y-8">
                    @foreach ($activeLoans as $loan)
                        @php
                            $schedule = $loan->amortization_schedule;
                            $chartLabels = collect($schedule)->map(function ($row) {
                                if (! empty($row['due_date'])) {
                                    return 'W'.$row['week'].' ('.\Carbon\Carbon::parse($row['due_date'])->format('M d').')';
                                }

                                return 'W'.$row['week'];
                            });
                            $chartInterest = collect($schedule)->pluck('interest');
                            $chartPrincipal = collect($schedule)->pluck('principal');
                            $contractInterest = (float) collect($schedule)->sum('interest');
                            $interestPaidToDate = (float) $loan->payments->sum('interest_paid');
                            $remainingContractInterest = max(0, $contractInterest - $interestPaidToDate);
                            $projectedExtraInterest4Weeks = round((float) $loan->remaining_balance * ((float) $loan->interest_rate / 100) * 4, 2);
                            $approvedAt = $loan->approved_at ? \Carbon\Carbon::parse($loan->approved_at) : null;
                            $firstDue = $approvedAt ? $approvedAt->copy()->addDays(7) : null;
                            $elapsedWeeks = $approvedAt ? max(0, (int) floor($approvedAt->diffInDays(now()) / 7)) : 0;
                        @endphp

                        <div class="rounded-xl border border-base-300 p-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h3 class="text-lg font-bold">Loan #{{ $loan->id }}</h3>
                                    <p class="text-sm text-base-content/70">
                                        @if ($loan->status === 'missed')
                                            <span class="badge badge-warning">Missed</span>
                                        @else
                                            <span class="badge badge-success">Approved</span>
                                        @endif
                                        Next due: {{ optional($loan->next_due_date)->format('M d, Y') ?? 'N/A' }}
                                    </p>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                                    <div>
                                        <p class="text-base-content/60">Remaining Principal</p>
                                        <p class="font-semibold">${{ number_format((float) $loan->remaining_balance, 2) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-base-content/60">Scheduled Weekly</p>
                                        <p class="font-semibold">${{ number_format((float) $loan->scheduled_weekly_payment, 2) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-base-content/60">Past Due</p>
                                        <p class="font-semibold text-error">${{ number_format((float) $loan->past_due_amount, 2) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-base-content/60">Accrued Interest Due</p>
                                        <p class="font-semibold text-warning">${{ number_format((float) $loan->effective_interest_due_now, 2) }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-4 text-sm">
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">Approved On</p>
                                    <p class="font-semibold">{{ $approvedAt ? $approvedAt->format('M d, Y') : 'N/A' }}</p>
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">First Due</p>
                                    <p class="font-semibold">{{ $firstDue ? $firstDue->format('M d, Y') : 'N/A' }}</p>
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">Term Progress</p>
                                    <p class="font-semibold">{{ min($elapsedWeeks, (int) $loan->term_weeks) }} / {{ (int) $loan->term_weeks }} weeks</p>
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">Weekly Interest Rate</p>
                                    <p class="font-semibold">{{ number_format((float) $loan->interest_rate, 2) }}%</p>
                                </div>
                            </div>

                            @if ((float) $loan->past_due_amount > 0 || (float) $loan->next_payment_due > 0)
                                <div class="mt-4 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
                                    @if ((float) $loan->past_due_amount > 0)
                                        <p class="font-semibold">You are behind on this loan.</p>
                                        <p>To get current now, pay at least <strong>${{ number_format((float) max($loan->past_due_amount, $loan->next_payment_due), 2) }}</strong>.</p>
                                    @else
                                        <p class="font-semibold">Next required payment is due on {{ optional($loan->next_due_date)->format('M d, Y') }}.</p>
                                    @endif
                                </div>
                            @endif

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3 text-sm">
                                    <p class="font-semibold">Current Required Payment</p>
                                    <p class="text-xl font-bold">${{ number_format((float) $loan->next_payment_due, 2) }}</p>
                                    <p class="mt-2">Interest portion: <strong>${{ number_format((float) ($loan->next_payment_preview['interest'] ?? 0), 2) }}</strong></p>
                                    <p>Principal portion: <strong>${{ number_format((float) ($loan->next_payment_preview['principal'] ?? 0), 2) }}</strong></p>
                                    <p class="mt-2 text-xs text-base-content/80">
                                        This cycle ({{ $loan->cycle_start ? \Carbon\Carbon::parse($loan->cycle_start)->format('M d') : 'N/A' }}
                                        to {{ $loan->cycle_end ? \Carbon\Carbon::parse($loan->cycle_end)->format('M d') : 'N/A' }}):
                                        paid <strong>${{ number_format((float) $loan->paid_this_cycle, 2) }}</strong>,
                                        remaining to hit scheduled target
                                        <strong>${{ number_format((float) $loan->remaining_to_scheduled, 2) }}</strong>.
                                    </p>
                                    @if ((float) $loan->next_payment_due <= 0.0)
                                        <p class="mt-2 text-xs text-base-content/70">
                                            No payment is required right now. Your scheduled weekly payment still starts at the next due date.
                                        </p>
                                    @endif
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3 text-sm">
                                    <p class="font-semibold">If You Pay Required Amount</p>
                                    <p>Remaining principal after payment:
                                        <strong>${{ number_format((float) ($loan->next_payment_preview['remaining_after'] ?? $loan->remaining_balance), 2) }}</strong>
                                    </p>
                                    <p>Accrued interest after payment:
                                        <strong>${{ number_format((float) ($loan->next_payment_preview['accrued_interest_after'] ?? 0), 2) }}</strong>
                                    </p>
                                    <p>Total payoff now:
                                        <strong>${{ number_format((float) $loan->total_owed_now, 2) }}</strong>
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">Total Contract Interest (On Schedule)</p>
                                    <p class="font-semibold">${{ number_format($contractInterest, 2) }}</p>
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">Estimated Remaining Contract Interest</p>
                                    <p class="font-semibold">${{ number_format($remainingContractInterest, 2) }}</p>
                                </div>
                                <div class="rounded-lg border border-base-300 bg-base-200 p-3">
                                    <p class="text-base-content/60">If Unpaid 4 More Weeks (Estimate)</p>
                                    <p class="font-semibold">+${{ number_format($projectedExtraInterest4Weeks, 2) }} interest</p>
                                </div>
                            </div>

                            @if (! empty($schedule))
                                <div class="mt-6 space-y-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <h4 class="text-md font-semibold">Contract Amortization Schedule</h4>
                                        <button class="btn btn-outline btn-sm" type="button"
                                                onclick="downloadScheduleCsv('loan-schedule-{{ $loan->id }}', 'loan-{{ $loan->id }}-amortization.csv')">
                                            Download CSV
                                        </button>
                                    </div>
                                    <div class="h-96 rounded-lg border border-base-300 bg-base-100 p-3">
                                        <canvas id="amortization-chart-{{ $loan->id }}"></canvas>
                                    </div>
                                    <div class="max-h-80 overflow-y-auto rounded-lg border border-base-300">
                                        <table class="table table-sm table-zebra w-full" id="loan-schedule-{{ $loan->id }}">
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
                                            @foreach ($schedule as $row)
                                                <tr>
                                                    <td>{{ $row['week'] }}</td>
                                                    <td>{{ ! empty($row['due_date']) ? \Carbon\Carbon::parse($row['due_date'])->format('M d, Y') : 'N/A' }}</td>
                                                    <td>${{ number_format((float) $row['opening_balance'], 2) }}</td>
                                                    <td>${{ number_format((float) $row['payment'], 2) }}</td>
                                                    <td class="text-warning">${{ number_format((float) $row['interest'], 2) }}</td>
                                                    <td class="text-success">${{ number_format((float) $row['principal'], 2) }}</td>
                                                    <td>${{ number_format((float) $row['closing_balance'], 2) }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <script>
                                    (() => {
                                        const ctx = document.getElementById('amortization-chart-{{ $loan->id }}');
                                        if (!ctx || typeof Chart === 'undefined') {
                                            return;
                                        }

                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: @json($chartLabels),
                                                datasets: [
                                                    {
                                                        label: 'Interest',
                                                        data: @json($chartInterest),
                                                        backgroundColor: 'rgba(245, 158, 11, 0.65)',
                                                        borderColor: 'rgba(245, 158, 11, 1)',
                                                        borderWidth: 1,
                                                    },
                                                    {
                                                        label: 'Principal',
                                                        data: @json($chartPrincipal),
                                                        backgroundColor: 'rgba(16, 185, 129, 0.65)',
                                                        borderColor: 'rgba(16, 185, 129, 1)',
                                                        borderWidth: 1,
                                                    }
                                                ]
                                            },
                                            options: {
                                                responsive: true,
                                                maintainAspectRatio: false,
                                                plugins: {
                                                    legend: {
                                                        position: 'top',
                                                    }
                                                },
                                                scales: {
                                                    x: {
                                                        stacked: true,
                                                        title: {
                                                            display: true,
                                                            text: 'Week',
                                                        }
                                                    },
                                                    y: {
                                                        stacked: true,
                                                        beginAtZero: true,
                                                        title: {
                                                            display: true,
                                                            text: 'Amount ($)',
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    })();
                                </script>
                            @endif

                            @if (! $loan->payments->isEmpty())
                                <div class="mt-6">
                                    <h4 class="text-md font-semibold">Payment History</h4>
                                    <div class="max-h-72 overflow-y-auto rounded-lg border border-base-300">
                                        <table class="table table-sm table-zebra w-full">
                                            <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Interest</th>
                                                <th>Principal</th>
                                                <th>Account</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($loan->payments as $payment)
                                                <tr>
                                                    <td>{{ \Carbon\Carbon::create($payment->payment_date)->format('M d, Y') }}</td>
                                                    <td>${{ number_format((float) $payment->amount, 2) }}</td>
                                                    <td>${{ number_format((float) $payment->interest_paid, 2) }}</td>
                                                    <td>${{ number_format((float) $payment->principal_paid, 2) }}</td>
                                                    <td>{{ $payment->account?->name ?? 'N/A' }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-utils.card>
        @endif

        <x-utils.card title="Loan FAQ" extraClasses="shadow-lg">
            <div class="space-y-2">
                @php
                    $faqs = [
                        ['q' => 'Why is my current required payment $0?', 'a' => 'Before the first due date, no weekly cycle has matured yet. Required due can be $0 until the first due date arrives.'],
                        ['q' => 'Why is the interest portion $0 right now?', 'a' => 'Interest is accrued weekly at due cycles, not continuously every day.'],
                        ['q' => 'Does interest increase daily?', 'a' => 'No. This system accrues at weekly cycle boundaries.'],
                        ['q' => 'Can I pay early?', 'a' => 'Yes. Early payments reduce principal immediately after any accrued interest is paid.'],
                        ['q' => 'Can I pay more than the required payment?', 'a' => 'Yes, up to total currently owed. During a cycle, payments count toward that cycle target first; any amount above target effectively accelerates principal reduction.'],
                        ['q' => 'What happens if I pay part of the week but not all of it?', 'a' => 'What you paid during that week is credited toward that cycle. At cycle close, only the remaining shortfall rolls into past due.'],
                        ['q' => 'What happens if I miss one week?', 'a' => 'The unpaid shortfall for that cycle moves into past due, and weekly cycle interest keeps accruing.'],
                        ['q' => 'Are there late fees right now?', 'a' => 'No penalty fees are currently configured.'],
                        ['q' => 'What does Past Due mean?', 'a' => 'Scheduled weekly installments that were not paid when due.'],
                        ['q' => 'What does Accrued Interest Due mean?', 'a' => 'Interest already earned by the lender but not yet paid by the borrower.'],
                        ['q' => 'What if payments are frozen by leadership?', 'a' => 'Auto-pay and weekly accrual freeze; due dates shift forward when resumed.'],
                        ['q' => 'Can I still pay manually while frozen?', 'a' => 'Yes, manual payments are still allowed.'],
                        ['q' => 'What is amortization?', 'a' => 'A fixed scheduled payment where interest share declines and principal share rises over time.'],
                        ['q' => 'Why does my payment split change each week?', 'a' => 'Because interest is calculated on remaining principal; as principal drops, interest drops.'],
                        ['q' => 'What does the chart show?', 'a' => 'Each bar stacks interest and principal by week for the contract schedule.'],
                        ['q' => 'What does the schedule table date mean?', 'a' => 'The planned due date for that weekly installment under the original schedule.'],
                        ['q' => 'Can my term end earlier than shown?', 'a' => 'Yes, if you pay extra principal, payoff can happen sooner.'],
                        ['q' => 'What is total payoff now?', 'a' => 'Remaining principal plus accrued interest due as of now.'],
                        ['q' => 'Why can’t I overpay beyond payoff?', 'a' => 'System caps payment at what is owed to avoid accidental overpayment.'],
                        ['q' => 'Does this follow US mortgage rules?', 'a' => 'It follows general amortization math but is simplified for weekly game cycles.'],
                        ['q' => 'Can admins change loan settings after approval?', 'a' => 'Admins can update loan settings in admin tools, which can affect schedule projections.'],
                    ];
                @endphp

                @foreach ($faqs as $item)
                    <details class="rounded-lg border border-base-300 bg-base-200 p-3">
                        <summary class="cursor-pointer font-semibold">{{ $item['q'] }}</summary>
                        <p class="mt-2 text-sm text-base-content/80">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </x-utils.card>

        <x-utils.card title="Loan History" extraClasses="shadow-lg">
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
                                <td>${{ number_format((float) $loan->amount, 2) }}</td>
                                <td>{{ $loan->term_weeks }}</td>
                                <td>
                                    @php
                                        $historyAccount = $loan->account;
                                    @endphp
                                    @if ($historyAccount?->id)
                                        <a href="{{ route('accounts.view', $historyAccount->id) }}"
                                           class="link link-primary">{{ $historyAccount->name }}</a>
                                    @elseif ($loan->account_id)
                                        Account #{{ $loan->account_id }}
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>
                                    @if ($loan->status === 'pending')
                                        <span class="badge badge-warning">Pending</span>
                                    @elseif ($loan->status === 'approved')
                                        <span class="badge badge-success">Approved</span>
                                    @elseif ($loan->status === 'missed')
                                        <span class="badge badge-warning">Missed</span>
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

        <div class="rounded-xl border border-base-300 bg-base-100 p-4 text-xs text-base-content/70">
            <p><strong>Calculation assumptions:</strong> weekly interest cycles, amortized scheduled payment, no penalty fees, interest paid before principal.</p>
            <p class="mt-1"><strong>Last updated:</strong> {{ now()->format('M d, Y H:i T') }}</p>
        </div>
    </div>

    @if (! $activeLoans->isEmpty())
        @php
            $loanDataForPreview = $activeLoans->mapWithKeys(function ($loan) {
                $weeklyRate = (float) $loan->interest_rate / 100;

                return [
                    $loan->id => [
                        'remaining_balance' => (float) $loan->remaining_balance,
                        'interest_due_now' => (float) $loan->effective_interest_due_now,
                        'current_due' => (float) $loan->next_payment_due,
                        'total_owed_now' => (float) $loan->total_owed_now,
                        'scheduled_weekly' => (float) $loan->scheduled_weekly_payment,
                        'weekly_rate' => $weeklyRate,
                    ],
                ];
            });
        @endphp
        <script>
            function downloadScheduleCsv(tableId, filename) {
                const table = document.getElementById(tableId);
                if (!table) {
                    return;
                }

                const rows = Array.from(table.querySelectorAll('tr'));
                const csv = rows.map((row) => {
                    const cols = Array.from(row.querySelectorAll('th,td')).map((col) => {
                        const text = (col.innerText || '').replace(/\"/g, '\"\"').trim();
                        return `\"${text}\"`;
                    });
                    return cols.join(',');
                }).join('\n');

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            }

            document.addEventListener('DOMContentLoaded', () => {
                const loanData = @json($loanDataForPreview);

                const loanSelect = document.getElementById('repayment_loan_id');
                const amountInput = document.getElementById('repayment_amount');
                const slider = document.getElementById('repayment_slider');
                const sliderMaxLabel = document.getElementById('slider_max_label');
                const useMinDueButton = document.getElementById('use_min_due');
                const useMinDueHint = document.getElementById('use_min_due_hint');
                const previewInterest = document.getElementById('preview_interest');
                const previewPrincipal = document.getElementById('preview_principal');
                const previewRemaining = document.getElementById('preview_remaining');
                const previewDue = document.getElementById('preview_due');
                const previewTotal = document.getElementById('preview_total');
                const previewWeeksSaved = document.getElementById('preview_weeks_saved');

                function formatMoney(value) {
                    return '$' + Number(value || 0).toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                }

                function estimateWeeksToPayoff(balance, weeklyRate, scheduledPayment) {
                    if (balance <= 0 || scheduledPayment <= 0) {
                        return 0;
                    }

                    if (weeklyRate <= 0) {
                        return Math.ceil(balance / scheduledPayment);
                    }

                    const ratio = 1 - ((weeklyRate * balance) / scheduledPayment);
                    if (ratio <= 0) {
                        return Number.POSITIVE_INFINITY;
                    }

                    const n = -Math.log(ratio) / Math.log(1 + weeklyRate);
                    return Math.ceil(n);
                }

                function updatePreview() {
                    if (!loanSelect || !amountInput || !slider || !sliderMaxLabel) {
                        return;
                    }

                    const selectedLoanId = loanSelect.value;
                    const selectedLoan = loanData[selectedLoanId];

                    if (!selectedLoan) {
                        previewInterest.textContent = formatMoney(0);
                        previewPrincipal.textContent = formatMoney(0);
                        previewRemaining.textContent = formatMoney(0);
                        previewDue.textContent = formatMoney(0);
                        previewTotal.textContent = formatMoney(0);
                        previewWeeksSaved.textContent = '0';
                        return;
                    }

                    const maxAmount = Math.max(0, Number(selectedLoan.total_owed_now || 0));
                    slider.max = Math.ceil(maxAmount * 100);
                    sliderMaxLabel.textContent = formatMoney(maxAmount);
                    amountInput.max = maxAmount;
                    if (useMinDueHint) {
                        useMinDueHint.textContent = `Current due: ${formatMoney(selectedLoan.current_due)}`;
                    }

                    const rawAmount = parseFloat(amountInput.value || '0');
                    const amount = Math.max(0, Math.min(rawAmount, selectedLoan.total_owed_now));
                    const interest = Math.min(amount, selectedLoan.interest_due_now);
                    const principal = Math.max(0, amount - interest);
                    const remaining = Math.max(0, selectedLoan.remaining_balance - principal);

                    const beforeWeeks = estimateWeeksToPayoff(
                        selectedLoan.remaining_balance,
                        selectedLoan.weekly_rate,
                        selectedLoan.scheduled_weekly
                    );
                    const afterWeeks = estimateWeeksToPayoff(
                        remaining,
                        selectedLoan.weekly_rate,
                        selectedLoan.scheduled_weekly
                    );
                    const weeksSaved = Number.isFinite(beforeWeeks) && Number.isFinite(afterWeeks)
                        ? Math.max(0, beforeWeeks - afterWeeks)
                        : 0;

                    previewInterest.textContent = formatMoney(interest);
                    previewPrincipal.textContent = formatMoney(principal);
                    previewRemaining.textContent = formatMoney(remaining);
                    previewDue.textContent = formatMoney(selectedLoan.current_due);
                    previewTotal.textContent = formatMoney(selectedLoan.total_owed_now);
                    previewWeeksSaved.textContent = String(weeksSaved);
                }

                function syncAmountFromSlider() {
                    const selectedLoan = loanData[loanSelect.value];
                    if (! selectedLoan) {
                        return;
                    }

                    const sliderValue = Number(slider.value || 0) / 100;
                    amountInput.value = sliderValue.toFixed(2);
                    updatePreview();
                }

                function syncSliderFromAmount() {
                    const selectedLoan = loanData[loanSelect.value];
                    if (! selectedLoan) {
                        return;
                    }

                    const rawAmount = parseFloat(amountInput.value || '0');
                    const amount = Math.max(0, Math.min(rawAmount, selectedLoan.total_owed_now));
                    slider.value = Math.round(amount * 100);
                    updatePreview();
                }

                loanSelect.addEventListener('change', () => {
                    amountInput.value = '';
                    slider.value = 0;
                    updatePreview();
                });

                if (useMinDueButton) {
                    useMinDueButton.addEventListener('click', () => {
                        const selectedLoan = loanData[loanSelect.value];
                        if (! selectedLoan) {
                            return;
                        }

                        const minimumDue = Math.max(0, Number(selectedLoan.current_due || 0));
                        amountInput.value = minimumDue.toFixed(2);
                        slider.value = Math.round(minimumDue * 100);
                        updatePreview();
                    });
                }
                slider.addEventListener('input', syncAmountFromSlider);
                amountInput.addEventListener('input', syncSliderFromAmount);

                updatePreview();
            });
        </script>
    @endif
@endsection
