@php use Carbon\Carbon; @endphp
@extends('layouts.admin')

@section('content')
    <x-header separator>
        <x-slot:title>
            Loan #{{ $loan->id }} &mdash;
            <a href="https://politicsandwar.com/nation/id={{ $loan->nation_id }}" target="_blank" class="link link-primary">
                {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation_id) }}
            </a>
        </x-slot:title>
        <x-slot:actions>
            @php
                $statusMap = ['missed' => 'badge-warning', 'approved' => 'badge-success', 'paid' => 'badge-primary'];
            @endphp
            <x-badge :label="ucfirst($loan->status)" :class="($statusMap[$loan->status] ?? 'badge-ghost') . ' badge-lg'" />
        </x-slot:actions>
    </x-header>

    @if (!$loanPaymentsEnabled)
        <x-alert class="alert-warning mb-4" icon="o-pause-circle">
            Loan payments are paused globally. Weekly accrual and required due are frozen until resumed.
        </x-alert>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-stat title="Original Principal" :value="'$' . number_format((float) $loan->amount, 2)" icon="o-banknotes" color="text-success" />
        <x-stat title="Remaining Principal" :value="'$' . number_format((float) $loan->remaining_balance, 2)" icon="o-building-library" color="text-primary" />
        <x-stat title="Scheduled Weekly" :value="'$' . number_format((float) $scheduledWeeklyPayment, 2)" icon="o-arrow-path" color="text-info" />
        <x-stat title="Current Required" :value="'$' . number_format((float) $nextMinimumPayment, 2)" icon="o-credit-card" color="text-error" />
        <x-stat title="Past Due" :value="'$' . number_format((float) $loan->past_due_amount, 2)" icon="o-exclamation-triangle" color="text-warning" />
        <x-stat title="Interest Due Now" :value="'$' . number_format((float) ($fullPayoffPreview['interest'] ?? 0), 2)" icon="o-percent-badge" color="text-neutral" />
        <x-stat title="Total Payoff Now" :value="'$' . number_format((float) (($fullPayoffPreview['interest'] ?? 0) + $loan->remaining_balance), 2)" icon="o-wallet" color="text-base-content" />
        <x-stat title="Next Due Date" :value="$loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('M d, Y') : 'N/A'" icon="o-calendar" color="text-warning" />
    </div>

    {{-- Loan Operations Summary --}}
    <x-card title="Loan Operations Summary" class="mb-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Cycle Progress</div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-base-content/60">Cycle window</dt>
                    <dd class="font-semibold">
                        {{ $cycleProgress['cycle_start'] ? Carbon::parse($cycleProgress['cycle_start'])->format('M d') : 'N/A' }}
                        – {{ $cycleProgress['cycle_end'] ? Carbon::parse($cycleProgress['cycle_end'])->format('M d') : 'N/A' }}
                    </dd>
                    <dt class="text-base-content/60">Paid this cycle</dt>
                    <dd class="font-semibold">${{ number_format((float) $cycleProgress['paid_this_cycle'], 2) }}</dd>
                    <dt class="text-base-content/60">Remaining to target</dt>
                    <dd class="font-semibold">${{ number_format((float) $cycleProgress['remaining_to_scheduled'], 2) }}</dd>
                </dl>
            </div>
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Next Payment Split</div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-base-content/60">Required now</dt>
                    <dd class="font-semibold">${{ number_format((float) $nextMinimumPayment, 2) }}</dd>
                    <dt class="text-base-content/60">Interest portion</dt>
                    <dd class="font-semibold">${{ number_format((float) ($nextPaymentPreview['interest'] ?? 0), 2) }}</dd>
                    <dt class="text-base-content/60">Principal portion</dt>
                    <dd class="font-semibold">${{ number_format((float) ($nextPaymentPreview['principal'] ?? 0), 2) }}</dd>
                </dl>
            </div>
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Payment History Totals</div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-base-content/60">Total paid</dt>
                    <dd class="font-semibold">${{ number_format((float) $totals['paid_total'], 2) }}</dd>
                    <dt class="text-base-content/60">Principal paid</dt>
                    <dd class="font-semibold">${{ number_format((float) $totals['paid_principal'], 2) }}</dd>
                    <dt class="text-base-content/60">Interest paid</dt>
                    <dd class="font-semibold">${{ number_format((float) $totals['paid_interest'], 2) }}</dd>
                </dl>
            </div>
        </div>

        <div class="divider"></div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Contract Context</div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-base-content/60">Approved at</dt>
                    <dd class="font-semibold">{{ $loan->approved_at ? Carbon::parse($loan->approved_at)->format('M d, Y') : 'N/A' }}</dd>
                    <dt class="text-base-content/60">Weeks elapsed</dt>
                    <dd class="font-semibold">{{ min($weeksElapsed, (int) $loan->term_weeks) }} / {{ (int) $loan->term_weeks }}</dd>
                    <dt class="text-base-content/60">Weekly rate</dt>
                    <dd class="font-semibold">{{ number_format((float) $loan->interest_rate, 2) }}%</dd>
                </dl>
            </div>
            <div class="lg:col-span-2 border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Accounting Rules in Effect</div>
                <ul class="text-sm text-base-content/70 space-y-1 list-disc list-inside">
                    <li>Interest accrues weekly at cycle close (not daily).</li>
                    <li>Cycle interest is locked to cycle-opening principal.</li>
                    <li>Payments apply to accrued interest first, then principal.</li>
                    <li>Only cycle shortfall rolls into past due.</li>
                    <li>No penalty fees are currently added on missed cycles.</li>
                </ul>
            </div>
        </div>
    </x-card>

    {{-- Modify Loan Details --}}
    <x-card title="Modify Loan Details" class="mb-6">
        @php $loanIsImmutable = $loan->payments->isNotEmpty(); @endphp

        @if ($loanIsImmutable)
            <x-alert class="alert-warning mb-4" icon="o-lock-closed">
                <div class="font-semibold">This loan is locked because payments already exist.</div>
                <div class="text-sm mt-1">To preserve accounting integrity, terms and balances can no longer be edited directly.
                    If a correction is required: 1) mark this loan as paid, and 2) create a replacement loan via manual disbursement.</div>
            </x-alert>
        @endif

        <form method="POST" action="{{ route('admin.loans.update', $loan) }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                <x-input label="Loan Amount" type="number" name="amount" id="amount" min="1"
                         :value="$loan->amount" :disabled="$loanIsImmutable"
                         :hint="$loanIsImmutable ? 'Locked after first payment.' : null" />
                <x-input label="Weekly Interest Rate (%)" type="number" name="interest_rate" id="interest_rate"
                         min="0" max="100" step="0.01" :value="$loan->interest_rate" :disabled="$loanIsImmutable" />
                <x-input label="Loan Term (Weeks)" type="number" name="term_weeks" id="term_weeks"
                         min="1" max="52" :value="$loan->term_weeks" :disabled="$loanIsImmutable" />
                <x-input label="Next Payment Due Date" type="date" name="next_due_date" id="next_due_date"
                         :value="$loan->next_due_date ? Carbon::parse($loan->next_due_date)->format('Y-m-d') : ''"
                         :disabled="$loanIsImmutable" />
                <x-input label="Remaining Principal Balance" type="number" name="remaining_balance" id="remaining_balance"
                         min="0" step="0.01" :max="$loan->amount" :value="$loan->remaining_balance" :disabled="$loanIsImmutable" />
            </div>
            <div class="flex justify-end gap-3">
                <x-button label="Update Loan" type="submit" icon="o-check" class="btn-primary" :disabled="$loanIsImmutable" />
            </div>
        </form>

        <div class="divider"></div>

        <form method="POST" action="{{ route('admin.loans.markPaid', $loan) }}"
              onsubmit="return confirm('Are you sure you want to mark this loan as fully paid? This action cannot be undone.')">
            @csrf
            <div class="flex justify-end">
                <x-button label="Mark Loan as Paid" type="submit" icon="o-check-badge" class="btn-error btn-outline" />
            </div>
        </form>
    </x-card>

    {{-- Amortization Schedule --}}
    <x-card title="Contract Amortization Schedule" class="mb-6">
        @if (empty($amortizationSchedule))
            <p class="text-base-content/50">No schedule available for this loan.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr class="text-base-content/60">
                            <th>Week</th>
                            <th>Due Date</th>
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
                                <td>{{ !empty($row['due_date']) ? Carbon::parse($row['due_date'])->format('M d, Y') : 'N/A' }}</td>
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
    </x-card>

    {{-- Payment History --}}
    <x-card title="Loan Payment History" class="mb-6">
        @if ($loan->payments->isEmpty())
            <p class="text-base-content/50">No payments have been made for this loan.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr class="text-base-content/60">
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
    </x-card>
@endsection
