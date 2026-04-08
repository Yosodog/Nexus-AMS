@extends('layouts.admin')

@section('content')
    <x-header title="Loan Management" separator>
        <x-slot:actions>
            @can('manage-loans')
                <x-button label="New Loan" icon="o-plus" @click="$refs.approveLoanModal.showModal()" class="btn-primary btn-sm" />
            @endcan
        </x-slot:actions>
    </x-header>

    @if (!$loanPaymentsEnabled)
        <x-alert class="alert-warning mb-4" icon="o-pause-circle">
            Loan payments are currently paused. Required due and weekly accrual are frozen until payments are resumed.
        </x-alert>
    @endif

    {{-- KPI Stats --}}
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
        <x-stat title="Total Approved" :value="$totalApproved" icon="o-check-circle" color="text-primary" />
        <x-stat title="Total Denied" :value="$totalDenied" icon="o-x-circle" color="text-error" />
        <x-stat title="Pending Loans" :value="$pendingCount" icon="o-clock" color="text-warning" />
        <x-stat title="Total Loaned Funds" :value="'$' . number_format($totalLoanedFunds, 2)" icon="o-banknotes" color="text-success" />
    </div>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <x-stat title="Outstanding Principal" :value="'$' . number_format((float) $portfolioStats['outstanding_principal'], 2)" icon="o-building-library" color="text-info" />
        <x-stat title="Current Due (All Active)" :value="'$' . number_format((float) $portfolioStats['current_due_total'], 2)" icon="o-credit-card" color="text-neutral" />
        <x-stat title="Total Past Due" :value="'$' . number_format((float) $portfolioStats['past_due_total'], 2)" icon="o-exclamation-triangle" color="text-warning" />
        <x-stat title="Accrued Interest Due" :value="'$' . number_format((float) $portfolioStats['accrued_interest_total'], 2)" icon="o-percent-badge" color="text-base-content" />
    </div>

    {{-- Operational Snapshot --}}
    <x-card title="Operational Snapshot" class="mb-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Portfolio Health</div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-base-content/60">Active loans</dt>
                    <dd class="font-semibold">{{ $portfolioStats['active_count'] }}</dd>
                    <dt class="text-base-content/60">In missed status</dt>
                    <dd class="font-semibold">{{ $portfolioStats['missed_count'] }}</dd>
                    <dt class="text-base-content/60">Total payoff now</dt>
                    <dd class="font-semibold">${{ number_format((float) $portfolioStats['total_payoff_now'], 2) }}</dd>
                </dl>
            </div>
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Servicing Rules</div>
                <ul class="text-sm text-base-content/70 space-y-1 list-disc list-inside">
                    <li>Interest accrues weekly at cycle close, not daily.</li>
                    <li>Cycle interest is locked to opening principal for that cycle.</li>
                    <li>Payments apply to interest first, then principal.</li>
                    <li>No penalty fees are added for missed cycles.</li>
                </ul>
            </div>
            <div class="border border-base-300 rounded-box p-4">
                <div class="font-semibold mb-2">Cycle Mechanics</div>
                <ul class="text-sm text-base-content/70 space-y-1 list-disc list-inside">
                    <li>Partial in-cycle payments reduce that cycle shortfall.</li>
                    <li>Only unpaid shortfall rolls to past due at closeout.</li>
                    <li>Early overpayment accelerates principal reduction.</li>
                    <li>Manual disbursement bypasses borrower eligibility checks.</li>
                </ul>
            </div>
        </div>
    </x-card>

    {{-- Pending Loan Applications --}}
    <x-card title="Pending Loan Applications" class="mb-6">
        @if($pendingLoans->isEmpty())
            <p class="text-base-content/50">No pending loan requests.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr class="text-base-content/60">
                            <th>Nation</th>
                            <th>Requested Amount</th>
                            <th>Term (Weeks)</th>
                            <th>Requested At</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingLoans as $loan)
                            <tr>
                                <td>
                                    @if ($loan->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}"
                                           target="_blank" class="link link-primary font-medium">
                                            {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                                        </a>
                                        <div class="text-xs text-base-content/50">{{ $loan->nation->nation_name ?? 'Unknown Nation' }}</div>
                                    @else
                                        <span class="text-base-content/50">Unknown Nation</span>
                                    @endif
                                </td>
                                <td>${{ number_format($loan->amount) }}</td>
                                <td>{{ $loan->term_weeks }}</td>
                                <td>{{ $loan->created_at->format('M d, Y') }}</td>
                                <td class="text-right">
                                    <x-button label="Approve" icon="o-check-circle" class="btn-success btn-sm"
                                              x-data
                                              @click="$dispatch('open-approve-loan', @json($loan))" />
                                    <form action="{{ route('admin.loans.deny', $loan) }}" method="POST" class="inline">
                                        @csrf
                                        <x-button label="Deny" type="submit" icon="o-x-circle" class="btn-error btn-outline btn-sm" />
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    {{-- Active Loans --}}
    <x-card title="Active Loans" class="mb-6">
        @if($activeLoans->isEmpty())
            <p class="text-base-content/50">No active loans.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra text-nowrap">
                    <thead>
                        <tr class="text-base-content/60">
                            <th>Status</th>
                            <th>Nation</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Term</th>
                            <th>Weekly Payment</th>
                            <th>Current Due</th>
                            <th>Cycle Paid</th>
                            <th>Past Due</th>
                            <th>Interest Due</th>
                            <th>Total Owed</th>
                            <th>Remaining</th>
                            <th>Next Due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activeLoans as $loan)
                            <tr>
                                <td>
                                    @if ($loan->status === 'missed')
                                        <x-badge label="Missed" class="badge-warning badge-sm" />
                                    @else
                                        <x-badge label="Active" class="badge-success badge-sm" />
                                    @endif
                                </td>
                                <td>
                                    @if ($loan->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $loan->nation->id }}"
                                           target="_blank" class="link link-primary font-medium">
                                            {{ $loan->nation->leader_name ?? ('Nation #'.$loan->nation->id) }}
                                        </a>
                                        <div class="text-xs text-base-content/50">{{ $loan->nation->nation_name ?? '' }}</div>
                                    @else
                                        <span class="text-base-content/50">Unknown</span>
                                    @endif
                                </td>
                                <td>${{ number_format((float) $loan->amount, 2) }}</td>
                                <td>{{ number_format((float) $loan->interest_rate, 2) }}%</td>
                                <td>{{ (int) $loan->term_weeks }}w</td>
                                <td>${{ number_format((float) $loan->scheduled_weekly_payment, 2) }}</td>
                                <td>${{ number_format((float) $loan->current_amount_due, 2) }}</td>
                                <td>
                                    ${{ number_format((float) $loan->cycle_paid, 2) }}
                                    <div class="text-xs text-base-content/50">rem ${{ number_format((float) $loan->cycle_remaining, 2) }}</div>
                                </td>
                                <td>${{ number_format((float) $loan->past_due_amount, 2) }}</td>
                                <td>${{ number_format((float) $loan->effective_interest_due_now, 2) }}</td>
                                <td class="font-semibold">${{ number_format((float) $loan->total_owed_now, 2) }}</td>
                                <td>${{ number_format((float) $loan->remaining_balance, 2) }}</td>
                                <td>
                                    {{ optional($loan->next_due_date)->format('M d, Y') ?? 'N/A' }}
                                    @if (!is_null($loan->days_to_due))
                                        <div class="text-xs {{ $loan->days_to_due < 0 ? 'text-error' : 'text-base-content/50' }}">
                                            @if ($loan->days_to_due > 0) in {{ $loan->days_to_due }}d
                                            @elseif ($loan->days_to_due === 0) due today
                                            @else {{ abs($loan->days_to_due) }}d overdue
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.loans.view', $loan) }}">
                                        <x-button label="View" icon="o-eye" class="btn-primary btn-xs" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    @can('manage-loans')
        {{-- Manual Disbursement --}}
        <x-card title="Manual Loan Disbursement" class="mb-6">
            <x-slot:subtitle>Creates and approves a loan immediately, bypassing borrower eligibility and duplicate checks.</x-slot:subtitle>
            <form method="POST" action="{{ route('admin.manual-disbursements.loans') }}">
                @csrf
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                    <x-input label="Nation ID" type="number" name="nation_id" required min="1" :value="old('nation_id')" />
                    <x-input label="Account ID" type="number" name="account_id" required min="1" :value="old('account_id')" hint="Must belong to the nation above." />
                    <x-input label="Amount" type="number" name="amount" required step="0.01" min="1" :value="old('amount')" />
                    <x-input label="Interest %" type="number" name="interest_rate" required step="0.01" min="0" max="100" :value="old('interest_rate')" />
                    <x-input label="Term (weeks)" type="number" name="term_weeks" required min="1" max="52" :value="old('term_weeks')" />
                </div>
                <div class="flex justify-end">
                    <x-button label="Send Loan" type="submit" icon="o-paper-airplane" class="btn-primary" />
                </div>
            </form>
        </x-card>

        {{-- Settings --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <x-card title="Default Loan Interest Rate">
                <form method="POST" action="{{ route('admin.loans.default-interest-rate') }}">
                    @csrf
                    <div class="flex gap-2">
                        <x-input type="number" name="default_interest_rate" step="0.01" min="0" max="100" required
                                 :value="old('default_interest_rate', number_format($defaultLoanInterestRate, 2, '.', ''))"
                                 hint="Used to prefill approvals; each loan can still be adjusted." class="grow" />
                        <x-button label="Update" type="submit" icon="o-check" class="btn-primary self-start mt-6" />
                    </div>
                </form>
            </x-card>

            <x-card>
                <x-slot:title>
                    <div class="flex items-center gap-2">
                        Loan Applications
                        <x-badge :label="$loanApplicationsEnabled ? 'Open' : 'Closed'"
                                 :class="$loanApplicationsEnabled ? 'badge-success badge-sm' : 'badge-warning badge-sm'" />
                    </div>
                </x-slot:title>
                <x-slot:subtitle>Control whether members can submit new loan applications.</x-slot:subtitle>
                <form method="POST" action="{{ route('admin.loans.applications') }}">
                    @csrf
                    <input type="hidden" name="loan_applications_enabled" value="0">
                    <div class="flex items-center gap-3 mb-4">
                        <input type="checkbox" id="loanApplicationsEnabled"
                               class="toggle toggle-primary"
                               name="loan_applications_enabled" value="1"
                               @checked($loanApplicationsEnabled)>
                        <label for="loanApplicationsEnabled" class="cursor-pointer">Accept New Loan Applications</label>
                    </div>
                    <x-button label="Save" type="submit" icon="o-check" class="btn-primary" />
                </form>
            </x-card>
        </div>
    @endcan

    {{-- Approve Loan Modal --}}
    <dialog id="approveLoanModal" class="modal" x-data="{ loanData: {} }" @open-approve-loan.window="loanData = $event.detail; $el.showModal(); setApproveLoanData(loanData)">
        <div class="modal-box">
            <h3 class="font-bold text-lg mb-4">Approve Loan Application</h3>
            <form id="approveLoanForm" method="POST">
                @csrf
                <input type="hidden" name="loan_id" id="loan_id">
                <div class="space-y-3 mb-4">
                    <x-input label="Loan Amount" type="number" step="0.01" name="amount" id="approve_amount" required />
                    <x-input label="Interest Rate (%)" type="number" step="0.01" min="0" max="100" name="interest_rate" id="approve_interest_rate" required />
                    <x-input label="Term (Weeks)" type="number" min="0" max="52" name="term_weeks" id="approve_term_weeks" required />
                </div>
                <div class="modal-action">
                    <x-button label="Approve Loan" type="submit" icon="o-check-circle" class="btn-success" />
                    <x-button label="Cancel" onclick="approveLoanModal.close()" class="btn-ghost" />
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
@endsection

@push('scripts')
    <script>
        const defaultLoanInterestRate = {{ json_encode($defaultLoanInterestRate) }};

        function setApproveLoanData(loan) {
            document.getElementById('approveLoanForm').action = `{{ url('admin/loans') }}/${loan.id}/approve`;
            document.getElementById('loan_id').value = loan.id;
            document.getElementById('approve_amount').value = loan.amount;
            const interestRate = loan.interest_rate ?? defaultLoanInterestRate;
            document.getElementById('approve_interest_rate').value = interestRate ?? '';
            document.getElementById('approve_term_weeks').value = loan.term_weeks;
        }
    </script>
@endpush
