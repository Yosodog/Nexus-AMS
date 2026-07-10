@php
    $canManageLoans = auth()->user()?->can('manage-loans') ?? false;
    $canBypassSelfRestrictions = auth()->user()?->can('bypass-self-restrictions') ?? false;
@endphp

@extends('layouts.admin')

@section('title', 'Loans')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Loans</h1>
            <p class="nexus-page-summary">Review borrower requests, monitor current exposure and delinquency, and keep servicing controls explicit.</p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $loanPaymentsEnabled ? 'nexus-status--success' : 'nexus-status--warning' }}">
                Payments {{ $loanPaymentsEnabled ? 'running' : 'paused' }}
            </span>
            <span class="nexus-status {{ $loanApplicationsEnabled ? 'nexus-status--success' : 'nexus-status--warning' }}">
                Intake {{ $loanApplicationsEnabled ? 'open' : 'closed' }}
            </span>
            <span class="nexus-status {{ $pendingCount > 0 ? 'nexus-status--warning' : 'nexus-status--success' }}">
                {{ number_format($pendingCount) }} pending
            </span>
            @can('manage-loans')
                <a href="#manual-loan-disbursement" class="btn btn-primary btn-sm">
                    <x-icon name="o-plus" class="size-4" aria-hidden="true" />
                    Manual loan
                </a>
            @endcan
        </div>
    </header>

    @if (! $loanPaymentsEnabled)
        <div class="alert alert-warning" role="status">
            <x-icon name="o-pause-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Loan payments are paused</p>
                <p class="text-sm">Required due and weekly accrual are frozen until payments resume.</p>
            </div>
        </div>
    @endif

    <section class="nexus-panel nexus-panel--raised" aria-labelledby="pending-loans-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="pending-loans-title" class="nexus-section-title">Pending loan requests</h2>
                <p class="nexus-body-muted mt-1">Approval deposits the final amount into the borrower account and starts the weekly repayment schedule.</p>
            </div>
            @unless($canManageLoans)
                <span class="nexus-status nexus-status--neutral">View only</span>
            @endunless
        </div>

        @forelse ($pendingLoans as $loan)
            @php
                $isOwnRequest = ! $canBypassSelfRestrictions
                    && auth()->user()?->nation_id !== null
                    && (int) auth()->user()->nation_id === (int) $loan->nation_id;
            @endphp
            <article class="grid gap-4 border-b border-base-300 px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,0.75fr)_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($loan->nation)
                            <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline">
                                {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                            </a>
                        @else
                            <span class="font-semibold">Unknown nation</span>
                        @endif
                        <span class="nexus-status nexus-status--warning">Pending</span>
                    </div>
                    <p class="mt-1 text-sm text-base-content/60">
                        {{ $loan->nation?->nation_name ?? 'Unknown nation name' }} · Nation #{{ $loan->nation_id }} · Account #{{ $loan->account_id }}
                    </p>
                    <p class="mt-1 text-xs text-base-content/55">
                        Requested <time datetime="{{ $loan->created_at->toIso8601String() }}" title="{{ $loan->created_at->toDayDateTimeString() }}">{{ $loan->created_at->diffForHumans() }}</time>
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-x-5 gap-y-1 text-sm">
                    <div>
                        <dt class="text-xs text-base-content/55">Requested amount</dt>
                        <dd class="text-lg font-bold tabular-nums">${{ number_format((float) $loan->amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-base-content/55">Requested term</dt>
                        <dd class="text-lg font-bold tabular-nums">{{ number_format((int) $loan->term_weeks) }} weeks</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @if($canManageLoans && ! $isOwnRequest)
                        <button
                            type="button"
                            class="btn btn-success btn-sm"
                            x-data
                            @click="$dispatch('open-approve-loan', @json($loan))"
                        >
                            Review and approve
                        </button>
                        <form action="{{ route('admin.loans.deny', $loan) }}" method="POST" data-confirm="Deny this loan request? The borrower will be notified and no funds will be deposited." data-confirm-title="Deny loan request?" data-confirm-label="Deny request" data-confirm-tone="error">
                            @csrf
                            <button type="submit" class="btn btn-error btn-outline btn-sm">Deny request</button>
                        </form>
                    @elseif($isOwnRequest)
                        <span class="text-sm">
                            <span class="nexus-status nexus-status--error">Self-decision blocked</span>
                            <span class="mt-1 block text-base-content/60">Another reviewer must decide.</span>
                        </span>
                    @else
                        <span class="nexus-status nexus-status--neutral">Decision unavailable</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="nexus-empty-state">
                <x-icon name="o-check-circle" class="size-8 text-success" aria-hidden="true" />
                <div>
                    <h3 class="font-semibold">Loan queue is clear</h3>
                    <p class="mt-1 text-sm text-base-content/60">There are no pending loan requests.</p>
                </div>
            </div>
        @endforelse
    </section>

    <section class="nexus-panel" aria-labelledby="portfolio-position-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="portfolio-position-title" class="nexus-section-title">Portfolio position</h2>
                <p class="nexus-body-muted mt-1">
                    {{ number_format($portfolioStats['active_count']) }} active · {{ number_format($portfolioStats['missed_count']) }} missed ·
                    {{ number_format($totalApproved) }} approved contracts · {{ number_format($totalDenied) }} denied requests ·
                    ${{ number_format((float) $totalLoanedFunds, 2) }} originally loaned
                </p>
            </div>
        </div>
        <dl class="nexus-metrics rounded-none border-0">
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Outstanding principal</dt>
                <dd class="nexus-stat-value">${{ number_format((float) $portfolioStats['outstanding_principal'], 2) }}</dd>
                <p class="nexus-stat-helper">Principal still owed</p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Current required</dt>
                <dd class="nexus-stat-value">${{ number_format((float) $portfolioStats['current_due_total'], 2) }}</dd>
                <p class="nexus-stat-helper">Due across active loans</p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Past due</dt>
                <dd class="nexus-stat-value {{ $portfolioStats['past_due_total'] > 0 ? 'text-error' : '' }}">${{ number_format((float) $portfolioStats['past_due_total'], 2) }}</dd>
                <p class="nexus-stat-helper">Rolled cycle shortfall</p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Payoff exposure</dt>
                <dd class="nexus-stat-value">${{ number_format((float) $portfolioStats['total_payoff_now'], 2) }}</dd>
                <p class="nexus-stat-helper">Includes ${{ number_format((float) $portfolioStats['accrued_interest_total'], 2) }} interest</p>
            </div>
        </dl>
    </section>

    <section class="nexus-panel" aria-labelledby="active-loans-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="active-loans-title" class="nexus-section-title">Active servicing</h2>
                <p class="nexus-body-muted mt-1">Prioritize missed status, current required amounts, and approaching due dates.</p>
            </div>
        </div>

        @if($activeLoans->isEmpty())
            <div class="nexus-empty-state">
                <p class="text-sm text-base-content/60">No active loans are being serviced.</p>
            </div>
        @else
            <div class="nexus-table-shell rounded-none border-0">
                <table class="nexus-table" data-sortable="true">
                    <thead>
                        <tr>
                            <th scope="col">Borrower</th>
                            <th scope="col">Contract</th>
                            <th scope="col">Exposure</th>
                            <th scope="col">Required now</th>
                            <th scope="col">Next due</th>
                            <th scope="col" data-sortable="false"><span class="sr-only">Open loan</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activeLoans as $loan)
                            <tr>
                                <td data-order="{{ $loan->nation?->leader_name ?? $loan->nation?->nation_name ?? $loan->nation_id }}">
                                    <span class="nexus-status {{ $loan->status === 'missed' ? 'nexus-status--warning' : 'nexus-status--success' }}">
                                        {{ $loan->status === 'missed' ? 'Missed' : 'Active' }}
                                    </span>
                                    @if ($loan->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}" target="_blank" rel="noopener" class="mt-2 block font-semibold text-primary hover:underline">
                                            {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                                        </a>
                                        <span class="block text-xs text-base-content/55">{{ $loan->nation->nation_name ?? '' }}</span>
                                    @else
                                        <span class="mt-2 block text-base-content/60">Unknown nation</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap" data-order="{{ (float) $loan->amount }}">
                                    <span class="block font-medium">${{ number_format((float) $loan->amount, 2) }} principal</span>
                                    <span class="block text-xs text-base-content/55">{{ number_format((float) $loan->interest_rate, 2) }}% weekly · {{ (int) $loan->term_weeks }} weeks</span>
                                    <span class="block text-xs text-base-content/55">${{ number_format((float) $loan->scheduled_weekly_payment, 2) }} scheduled weekly</span>
                                </td>
                                <td class="whitespace-nowrap" data-order="{{ (float) $loan->total_owed_now }}">
                                    <span class="block font-medium">${{ number_format((float) $loan->total_owed_now, 2) }} payoff</span>
                                    <span class="block text-xs text-base-content/55">${{ number_format((float) $loan->remaining_balance, 2) }} principal</span>
                                    <span class="block text-xs text-base-content/55">${{ number_format((float) $loan->effective_interest_due_now, 2) }} interest due</span>
                                </td>
                                <td class="whitespace-nowrap" data-order="{{ (float) $loan->current_amount_due }}">
                                    <span class="block font-semibold {{ $loan->past_due_amount > 0 ? 'text-error' : '' }}">${{ number_format((float) $loan->current_amount_due, 2) }}</span>
                                    <span class="block text-xs text-base-content/55">${{ number_format((float) $loan->cycle_paid, 2) }} paid this cycle</span>
                                    <span class="block text-xs text-base-content/55">${{ number_format((float) $loan->cycle_remaining, 2) }} cycle remaining</span>
                                    <span class="block text-xs {{ $loan->past_due_amount > 0 ? 'text-error' : 'text-base-content/55' }}">${{ number_format((float) $loan->past_due_amount, 2) }} past due</span>
                                </td>
                                <td class="whitespace-nowrap" data-order="{{ $loan->next_due_date?->timestamp ?? PHP_INT_MAX }}">
                                    {{ optional($loan->next_due_date)->format('M d, Y') ?? 'Not scheduled' }}
                                    @if (!is_null($loan->days_to_due))
                                        <span class="block text-xs {{ $loan->days_to_due < 0 ? 'font-semibold text-error' : 'text-base-content/55' }}">
                                            @if ($loan->days_to_due > 0)
                                                In {{ $loan->days_to_due }} days
                                            @elseif ($loan->days_to_due === 0)
                                                Due today
                                            @else
                                                {{ abs($loan->days_to_due) }} days overdue
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('admin.loans.view', $loan) }}" class="btn btn-primary btn-sm">Review loan</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <details class="nexus-panel">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
            <span>
                <span class="block font-semibold">Servicing rules</span>
                <span class="mt-0.5 block text-sm text-base-content/60">Weekly accrual, payment allocation, cycle shortfall, and early repayment behavior.</span>
            </span>
            <span class="flex items-center gap-2 text-sm font-semibold text-base-content/60">Open <x-icon name="o-chevron-down" class="size-4" aria-hidden="true" /></span>
        </summary>
        <div class="grid gap-5 border-t border-base-300 p-5 md:grid-cols-2">
            <div>
                <h3 class="font-semibold">Accounting</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-base-content/70">
                    <li>Interest accrues weekly at cycle close, not daily.</li>
                    <li>Cycle interest is locked to opening principal for that cycle.</li>
                    <li>Payments apply to interest first, then principal.</li>
                    <li>No penalty fees are added for missed cycles.</li>
                </ul>
            </div>
            <div>
                <h3 class="font-semibold">Cycle behavior</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-base-content/70">
                    <li>Partial in-cycle payments reduce that cycle shortfall.</li>
                    <li>Only unpaid shortfall rolls to past due at closeout.</li>
                    <li>Early overpayment accelerates principal reduction.</li>
                    <li>Manual disbursement bypasses borrower eligibility checks.</li>
                </ul>
            </div>
        </div>
    </details>

    @can('manage-loans')
        <details id="manual-loan-disbursement" class="nexus-panel" @if(old('nation_id') || old('account_id')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
                <span>
                    <span class="block font-semibold">Manual loan disbursement</span>
                    <span class="mt-0.5 block text-sm text-base-content/60">Creates, approves, and deposits a loan immediately while bypassing eligibility and duplicate checks.</span>
                </span>
                <span class="flex items-center gap-2">
                    <span class="nexus-status nexus-status--warning">Elevated action</span>
                    <x-icon name="o-chevron-down" class="size-4 text-base-content/50" aria-hidden="true" />
                </span>
            </summary>
            <form method="POST" action="{{ route('admin.manual-disbursements.loans') }}" class="border-t border-base-300 p-5" data-confirm="Create and deposit this loan immediately? This bypasses borrower eligibility and duplicate checks." data-confirm-title="Create manual loan?" data-confirm-label="Create and deposit" data-confirm-tone="error">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <x-input label="Nation ID" type="number" name="nation_id" required min="1" :value="old('nation_id')" />
                    <x-input label="Account ID" type="number" name="account_id" required min="1" :value="old('account_id')" hint="Must belong to the nation above." />
                    <x-input label="Amount" type="number" name="amount" required step="0.01" min="1" :value="old('amount')" />
                    <x-input label="Weekly interest %" type="number" name="interest_rate" required step="0.01" min="0" max="100" :value="old('interest_rate')" />
                    <x-input label="Term (weeks)" type="number" name="term_weeks" required min="1" max="52" :value="old('term_weeks')" />
                </div>
                <div class="nexus-form-actions mt-5">
                    <button type="submit" class="btn btn-primary">Create and deposit loan</button>
                </div>
            </form>
        </details>

        <section class="nexus-panel" aria-labelledby="loan-settings-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="loan-settings-title" class="nexus-section-title">Loan intake settings</h2>
                    <p class="nexus-body-muted mt-1">Default approval terms and whether members may submit new requests.</p>
                </div>
            </div>
            <div class="grid divide-y divide-base-300 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                <form method="POST" action="{{ route('admin.loans.default-interest-rate') }}" class="p-5">
                    @csrf
                    <h3 class="font-semibold">Default interest rate</h3>
                    <p class="mt-1 text-sm text-base-content/60">Prefills the approval dialog; each pending loan can still be adjusted.</p>
                    <div class="mt-4 flex items-end gap-2">
                        <x-input type="number" name="default_interest_rate" step="0.01" min="0" max="100" required
                                 :value="old('default_interest_rate', number_format($defaultLoanInterestRate, 2, '.', ''))"
                                 label="Weekly interest %" class="grow" />
                        <button type="submit" class="btn btn-primary">Update rate</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.loans.applications') }}" class="p-5">
                    @csrf
                    <input type="hidden" name="loan_applications_enabled" value="0">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">Member applications</h3>
                            <p class="mt-1 text-sm text-base-content/60">Control whether members can submit new loan requests.</p>
                        </div>
                        <span class="nexus-status {{ $loanApplicationsEnabled ? 'nexus-status--success' : 'nexus-status--warning' }}">
                            {{ $loanApplicationsEnabled ? 'Open' : 'Closed' }}
                        </span>
                    </div>
                    <label class="mt-4 flex cursor-pointer items-center gap-3">
                        <input type="checkbox" id="loanApplicationsEnabled" class="toggle toggle-primary" name="loan_applications_enabled" value="1" @checked($loanApplicationsEnabled)>
                        <span>Accept new loan applications</span>
                    </label>
                    <div class="nexus-form-actions mt-5">
                        <button type="submit" class="btn btn-primary">Save intake setting</button>
                    </div>
                </form>
            </div>
        </section>

        <dialog id="approveLoanModal" class="modal" x-data="{ loanData: {} }" @open-approve-loan.window="loanData = $event.detail; $el.showModal(); setApproveLoanData(loanData)" aria-labelledby="approve-loan-title">
            <div class="modal-box max-w-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="approve-loan-title" class="text-lg font-bold">Approve loan request</h2>
                        <p class="mt-1 text-sm text-base-content/60">Confirm the final contract. Submission deposits the amount and starts weekly servicing.</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('approveLoanModal').close()">Close</button>
                </div>
                <div class="mt-4 rounded-md border border-base-300 bg-base-200/50 px-4 py-3 text-sm">
                    <span class="font-semibold" x-text="loanData.nation?.leader_name || `Nation #${loanData.nation_id || ''}`"></span>
                    <span class="block text-base-content/60" x-text="`Account #${loanData.account_id || ''}`"></span>
                </div>
                <form id="approveLoanForm" method="POST" class="mt-5">
                    @csrf
                    <input type="hidden" name="loan_id" id="loan_id">
                    <div class="space-y-4">
                        <x-input label="Loan amount" type="number" step="0.01" min="0.01" name="amount" id="approve_amount" required />
                        <x-input label="Weekly interest rate (%)" type="number" step="0.01" min="0" max="100" name="interest_rate" id="approve_interest_rate" required />
                        <x-input label="Term (weeks)" type="number" min="1" max="52" name="term_weeks" id="approve_term_weeks" required />
                    </div>
                    <div class="modal-action">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('approveLoanModal').close()">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve and deposit</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>
    @endcan
@endsection

@push('scripts')
    <script>
        const defaultLoanInterestRate = {{ json_encode($defaultLoanInterestRate) }};

        function setApproveLoanData(loan) {
            document.getElementById('approveLoanForm').action = `{{ url('admin/loans') }}/${loan.id}/approve`;
            document.getElementById('loan_id').value = loan.id;
            document.getElementById('approve_amount').value = loan.amount;
            document.getElementById('approve_amount').max = loan.amount;
            const interestRate = loan.interest_rate ?? defaultLoanInterestRate;
            document.getElementById('approve_interest_rate').value = interestRate ?? '';
            document.getElementById('approve_term_weeks').value = loan.term_weeks;
        }
    </script>
@endpush
