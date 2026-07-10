@php
    use Carbon\Carbon;

    $statusClass = match($loan->status) {
        'approved' => 'nexus-status--success',
        'missed', 'pending' => 'nexus-status--warning',
        'denied' => 'nexus-status--error',
        default => 'nexus-status--neutral',
    };
    $loanIsImmutable = $loan->payments->isNotEmpty();
    $interestDueNow = (float) ($fullPayoffPreview['interest'] ?? 0);
    $totalPayoffNow = $interestDueNow + (float) $loan->remaining_balance;
@endphp

@extends('layouts.admin')

@section('title', 'Loan #'.$loan->id)

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <a href="{{ route('admin.loans') }}" class="mb-1 inline-flex w-fit items-center gap-1 text-sm font-semibold text-primary hover:underline">
                <x-icon name="o-arrow-left" class="size-4" aria-hidden="true" />
                Loans
            </a>
            <h1 class="nexus-page-title">Loan #{{ $loan->id }}</h1>
            <p class="nexus-page-summary">
                <a href="https://politicsandwar.com/nation/id={{ $loan->nation_id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline">
                    {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation_id) }}
                </a>
                · {{ $loan->nation->nation_name ?? 'Unknown nation name' }} · Account #{{ $loan->account_id }}
            </p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $statusClass }}">{{ ucfirst($loan->status) }}</span>
            @if($loanIsImmutable)
                <span class="nexus-status nexus-status--neutral">Terms locked</span>
            @endif
        </div>
    </header>

    @if (!$loanPaymentsEnabled)
        <div class="alert alert-warning" role="status">
            <x-icon name="o-pause-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Loan payments are paused globally</p>
                <p class="text-sm">Weekly accrual and required due are frozen until payments resume.</p>
            </div>
        </div>
    @endif

    <dl class="nexus-metrics">
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Remaining principal</dt>
            <dd class="nexus-stat-value">${{ number_format((float) $loan->remaining_balance, 2) }}</dd>
            <p class="nexus-stat-helper">Original ${{ number_format((float) $loan->amount, 2) }}</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Current required</dt>
            <dd class="nexus-stat-value">${{ number_format((float) $nextMinimumPayment, 2) }}</dd>
            <p class="nexus-stat-helper">Scheduled ${{ number_format((float) $scheduledWeeklyPayment, 2) }} weekly</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Past due</dt>
            <dd class="nexus-stat-value {{ $loan->past_due_amount > 0 ? 'text-error' : '' }}">${{ number_format((float) $loan->past_due_amount, 2) }}</dd>
            <p class="nexus-stat-helper">Cycle shortfall carried forward</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Payoff now</dt>
            <dd class="nexus-stat-value">${{ number_format($totalPayoffNow, 2) }}</dd>
            <p class="nexus-stat-helper">Includes ${{ number_format($interestDueNow, 2) }} interest due</p>
        </div>
    </dl>

    <section class="nexus-panel" aria-labelledby="loan-servicing-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="loan-servicing-title" class="nexus-section-title">Servicing context</h2>
                <p class="nexus-body-muted mt-1">Contract timing, current-cycle progress, next allocation, and lifetime payment totals.</p>
            </div>
        </div>
        <div class="grid divide-y divide-base-300 md:grid-cols-2 md:divide-x md:divide-y-0 xl:grid-cols-4">
            <div class="p-5">
                <h3 class="font-semibold">Contract</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Approved</dt><dd class="text-right font-medium">{{ $loan->approved_at ? Carbon::parse($loan->approved_at)->format('M d, Y') : 'Not approved' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Weekly rate</dt><dd class="font-medium">{{ number_format((float) $loan->interest_rate, 2) }}%</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Term progress</dt><dd class="font-medium">{{ min($weeksElapsed, (int) $loan->term_weeks) }} / {{ (int) $loan->term_weeks }} weeks</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Next due</dt><dd class="text-right font-medium">{{ $loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('M d, Y') : 'Not scheduled' }}</dd></div>
                </dl>
            </div>
            <div class="p-5">
                <h3 class="font-semibold">Current cycle</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Window</dt><dd class="text-right font-medium">{{ $cycleProgress['cycle_start'] ? Carbon::parse($cycleProgress['cycle_start'])->format('M d') : 'N/A' }} – {{ $cycleProgress['cycle_end'] ? Carbon::parse($cycleProgress['cycle_end'])->format('M d') : 'N/A' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Paid</dt><dd class="font-medium">${{ number_format((float) $cycleProgress['paid_this_cycle'], 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">To target</dt><dd class="font-medium">${{ number_format((float) $cycleProgress['remaining_to_scheduled'], 2) }}</dd></div>
                </dl>
            </div>
            <div class="p-5">
                <h3 class="font-semibold">Next payment</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Required now</dt><dd class="font-medium">${{ number_format((float) $nextMinimumPayment, 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Interest</dt><dd class="font-medium">${{ number_format((float) ($nextPaymentPreview['interest'] ?? 0), 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Principal</dt><dd class="font-medium">${{ number_format((float) ($nextPaymentPreview['principal'] ?? 0), 2) }}</dd></div>
                </dl>
            </div>
            <div class="p-5">
                <h3 class="font-semibold">Payment history</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Total paid</dt><dd class="font-medium">${{ number_format((float) $totals['paid_total'], 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Principal</dt><dd class="font-medium">${{ number_format((float) $totals['paid_principal'], 2) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-base-content/60">Interest</dt><dd class="font-medium">${{ number_format((float) $totals['paid_interest'], 2) }}</dd></div>
                </dl>
            </div>
        </div>
    </section>

    <details class="nexus-panel">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
            <span>
                <span class="block font-semibold">Accounting rules in effect</span>
                <span class="mt-0.5 block text-sm text-base-content/60">How weekly interest, payments, shortfalls, and missed cycles are calculated.</span>
            </span>
            <span class="flex items-center gap-2 text-sm font-semibold text-base-content/60">Open <x-icon name="o-chevron-down" class="size-4" aria-hidden="true" /></span>
        </summary>
        <ul class="list-disc space-y-1 border-t border-base-300 px-10 py-5 text-sm text-base-content/70">
            <li>Interest accrues weekly at cycle close, not daily.</li>
            <li>Cycle interest is locked to cycle-opening principal.</li>
            <li>Payments apply to accrued interest first, then principal.</li>
            <li>Only cycle shortfall rolls into past due.</li>
            <li>No penalty fees are currently added on missed cycles.</li>
        </ul>
    </details>

    @can('manage-loans')
        <section class="nexus-panel" aria-labelledby="modify-loan-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="modify-loan-title" class="nexus-section-title">Loan administration</h2>
                    <p class="nexus-body-muted mt-1">Direct edits are locked after the first payment to preserve accounting integrity.</p>
                </div>
                <span class="nexus-status {{ $loanIsImmutable ? 'nexus-status--warning' : 'nexus-status--neutral' }}">
                    {{ $loanIsImmutable ? 'Terms locked' : 'Terms editable' }}
                </span>
            </div>

            @if ($loanIsImmutable)
                <div class="border-b border-base-300 bg-warning/10 px-5 py-4 text-sm">
                    <p class="font-semibold">Payments already exist, so contract terms and balances cannot be edited.</p>
                    <p class="mt-1 text-base-content/70">If a correction is required, mark this loan paid and create a replacement through manual disbursement.</p>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.loans.update', $loan) }}" class="p-5">
                @csrf
                <div class="nexus-form-grid lg:grid-cols-3">
                    <x-input label="Loan amount" type="number" name="amount" id="amount" min="1"
                             :value="old('amount', $loan->amount)" :disabled="$loanIsImmutable"
                             :hint="$loanIsImmutable ? 'Locked after first payment.' : null" />
                    <x-input label="Weekly interest rate (%)" type="number" name="interest_rate" id="interest_rate"
                             min="0" max="100" step="0.01" :value="old('interest_rate', $loan->interest_rate)" :disabled="$loanIsImmutable" />
                    <x-input label="Loan term (weeks)" type="number" name="term_weeks" id="term_weeks"
                             min="1" max="52" :value="old('term_weeks', $loan->term_weeks)" :disabled="$loanIsImmutable" />
                    <x-input label="Next payment due date" type="date" name="next_due_date" id="next_due_date"
                             :value="old('next_due_date', $loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('Y-m-d') : '')"
                             :disabled="$loanIsImmutable" />
                    <x-input label="Remaining principal balance" type="number" name="remaining_balance" id="remaining_balance"
                             min="0" step="0.01" :max="$loan->amount" :value="old('remaining_balance', $loan->remaining_balance)" :disabled="$loanIsImmutable" />
                </div>
                <div class="nexus-form-actions mt-5">
                    <button type="submit" class="btn btn-primary" @disabled($loanIsImmutable)>Update loan terms</button>
                </div>
            </form>

            <div class="border-t border-base-300 px-5 py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-error">Close contract as paid</h3>
                        <p class="mt-1 text-sm text-base-content/60">Marks the loan fully paid. This cannot be undone and does not create a replacement contract.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.loans.markPaid', $loan) }}" data-confirm="Mark loan #{{ $loan->id }} as fully paid? This action cannot be undone." data-confirm-title="Mark loan fully paid?" data-confirm-label="Mark as paid" data-confirm-tone="error">
                        @csrf
                        <button type="submit" class="btn btn-error btn-outline">Mark loan as paid</button>
                    </form>
                </div>
            </div>
        </section>
    @endcan

    <section class="nexus-panel" aria-labelledby="amortization-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="amortization-title" class="nexus-section-title">Contract amortization</h2>
                <p class="nexus-body-muted mt-1">Scheduled weekly allocation across interest and principal.</p>
            </div>
        </div>
        @if (empty($amortizationSchedule))
            <div class="nexus-empty-state"><p class="text-sm text-base-content/60">No amortization schedule is available for this loan.</p></div>
        @else
            <div class="nexus-table-shell rounded-none border-0">
                <table class="nexus-table" data-sortable="false">
                    <thead>
                        <tr>
                            <th scope="col">Week</th>
                            <th scope="col">Due date</th>
                            <th scope="col" class="text-right">Opening</th>
                            <th scope="col" class="text-right">Payment</th>
                            <th scope="col" class="text-right">Interest</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($amortizationSchedule as $row)
                            <tr>
                                <td>{{ $row['week'] }}</td>
                                <td>{{ !empty($row['due_date']) ? Carbon::parse($row['due_date'])->format('M d, Y') : 'N/A' }}</td>
                                <td class="text-right">${{ number_format((float) $row['opening_balance'], 2) }}</td>
                                <td class="text-right">${{ number_format((float) $row['payment'], 2) }}</td>
                                <td class="text-right">${{ number_format((float) $row['interest'], 2) }}</td>
                                <td class="text-right">${{ number_format((float) $row['principal'], 2) }}</td>
                                <td class="text-right font-medium">${{ number_format((float) $row['closing_balance'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="nexus-panel" aria-labelledby="payment-history-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="payment-history-title" class="nexus-section-title">Payment history</h2>
                <p class="nexus-body-muted mt-1">Posted payments and their interest/principal allocation.</p>
            </div>
            <span class="text-sm tabular-nums text-base-content/60">{{ number_format($loan->payments->count()) }} payments</span>
        </div>
        @if ($loan->payments->isEmpty())
            <div class="nexus-empty-state"><p class="text-sm text-base-content/60">No payments have been posted for this loan.</p></div>
        @else
            <div class="nexus-table-shell rounded-none border-0">
                <table class="nexus-table" data-sortable="false">
                    <thead>
                        <tr>
                            <th scope="col">Payment</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col" class="text-right">Interest</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col">Source account</th>
                            <th scope="col">Posted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($loan->payments as $payment)
                            <tr>
                                <td>#{{ $payment->id }}</td>
                                <td class="text-right font-medium">${{ number_format((float) $payment->amount, 2) }}</td>
                                <td class="text-right">${{ number_format((float) $payment->interest_paid, 2) }}</td>
                                <td class="text-right">${{ number_format((float) $payment->principal_paid, 2) }}</td>
                                <td>{{ $payment->account->name ?? 'N/A' }}</td>
                                <td>{{ Carbon::parse($payment->payment_date)->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
