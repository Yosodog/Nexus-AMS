<div class="mb-5">
    <h2 class="nexus-section-title">Finance & workflows</h2>
    <p class="mt-1 max-w-3xl text-sm text-base-content/70">
        Global availability controls for automated withdrawals, required loan payments, and grant approvals.
    </p>
</div>

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    @can('manage-accounts')
        <x-admin.settings-disclosure
            id="auto-withdraw"
            title="Auto Withdraw"
            description="Globally enable or pause the automatic withdrawal scheduler."
            :status="$autoWithdrawEnabled ? 'Enabled' : 'Disabled'"
            :status-class="$autoWithdrawEnabled ? 'badge-success' : 'badge-ghost'"
        >
            <form method="POST" action="{{ route('admin.settings.auto-withdraw') }}" class="max-w-2xl space-y-5">
                @csrf
                <input type="hidden" name="auto_withdraw_enabled" value="0">

                <label class="flex cursor-pointer items-start gap-3">
                    <input class="toggle toggle-primary mt-0.5" type="checkbox" id="autoWithdrawEnabled" name="auto_withdraw_enabled" value="1" @checked(old('auto_withdraw_enabled', $autoWithdrawEnabled))>
                    <span>
                        <span class="block font-semibold">Enable automatic withdrawals</span>
                        <span class="mt-1 block text-sm text-base-content/70">Disabling this pauses the scheduler for every account.</span>
                    </span>
                </label>

                <button class="btn btn-primary" type="submit">Save withdrawal setting</button>
            </form>
        </x-admin.settings-disclosure>
    @endcan

    @can('manage-loans')
        <x-admin.settings-disclosure
            id="loan-payments"
            title="Loan Payments"
            description="Pause scheduled payment requirements during war or other exceptional events."
            :status="$loanPaymentsEnabled ? 'Enabled' : 'Paused'"
            :status-class="$loanPaymentsEnabled ? 'badge-success' : 'badge-warning'"
        >
            <form method="POST" action="{{ route('admin.settings.loan-payments') }}" class="max-w-2xl space-y-5">
                @csrf
                <input type="hidden" name="loan_payments_enabled" value="0">

                @if (! $loanPaymentsEnabled && $loanPaymentsPausedAt)
                    <div class="alert alert-warning">
                        <span class="text-sm">Payments have been paused since {{ $loanPaymentsPausedAt->format('M d, Y H:i') }}.</span>
                    </div>
                @endif

                <label class="flex cursor-pointer items-start gap-3">
                    <input class="toggle toggle-primary mt-0.5" type="checkbox" id="loanPaymentsEnabled" name="loan_payments_enabled" value="1" @checked(old('loan_payments_enabled', $loanPaymentsEnabled))>
                    <span>
                        <span class="block font-semibold">Require loan payments</span>
                        <span class="mt-1 block text-sm text-base-content/70">Turn this off only when payments should be paused globally.</span>
                    </span>
                </label>

                <button class="btn btn-primary" type="submit">Save loan payment setting</button>
            </form>
        </x-admin.settings-disclosure>
    @endcan

    @can('manage-grants')
        <x-admin.settings-disclosure
            id="grant-approvals"
            title="Grant Approvals"
            description="Emergency availability switch for standard grant and city grant approvals."
            :status="$grantApprovalsEnabled ? 'Enabled' : 'Paused'"
            :status-class="$grantApprovalsEnabled ? 'badge-success' : 'badge-warning'"
        >
            <form method="POST" action="{{ route('admin.settings.grants.approvals') }}" class="max-w-2xl space-y-5">
                @csrf
                <input type="hidden" name="grant_approvals_enabled" value="0">

                <label class="flex cursor-pointer items-start gap-3">
                    <input class="toggle toggle-primary mt-0.5" type="checkbox" id="grantApprovalsEnabled" name="grant_approvals_enabled" value="1" @checked(old('grant_approvals_enabled', $grantApprovalsEnabled))>
                    <span>
                        <span class="block font-semibold">Allow grant approvals</span>
                        <span class="mt-1 block text-sm text-base-content/70">Turn this off to pause approvals without deleting grant programs or requests.</span>
                    </span>
                </label>

                <button class="btn btn-primary" type="submit">Save grant approval setting</button>
            </form>
        </x-admin.settings-disclosure>
    @endcan
</div>
