<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAutoWithdrawSettingsRequest;
use App\Http\Requests\Admin\UpdateGrantApprovalSettingsRequest;
use App\Http\Requests\Admin\UpdateLoanPaymentSettingsRequest;
use App\Services\AuditLogger;
use App\Services\LoanService;
use App\Services\Settings\FinancePolicySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FinancePolicySettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly FinancePolicySettings $settings,
        private readonly LoanService $loanService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user?->canAny(['manage-accounts', 'manage-loans', 'manage-grants']), 403);

        $viewData = [];

        if ($user->can('manage-accounts')) {
            $viewData['autoWithdrawEnabled'] = $this->settings->isAutoWithdrawEnabled();
        }

        if ($user->can('manage-loans')) {
            $viewData['loanPaymentsEnabled'] = $this->settings->isLoanPaymentsEnabled();
            $viewData['loanPaymentsPausedAt'] = $this->settings->getLoanPaymentsPausedAt();
        }

        if ($user->can('manage-grants')) {
            $viewData['grantApprovalsEnabled'] = $this->settings->isGrantApprovalsEnabled();
        }

        return view('admin.settings.finance-policy', $viewData);
    }

    public function updateAutoWithdraw(UpdateAutoWithdrawSettingsRequest $request): RedirectResponse
    {
        $previous = $this->settings->isAutoWithdrawEnabled();
        $enabled = (bool) $request->validated('auto_withdraw_enabled');

        $this->settings->setAutoWithdrawEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'auto_withdraw_toggle',
            context: [
                'changes' => [
                    'auto_withdraw_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Auto withdraw setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Auto withdraw enabled.' : 'Auto withdraw disabled.',
            'alert-type' => 'success',
        ]);
    }

    public function updateLoanPayments(UpdateLoanPaymentSettingsRequest $request): RedirectResponse
    {
        $wasEnabled = $this->settings->isLoanPaymentsEnabled();
        $enabled = (bool) $request->validated('loan_payments_enabled');

        if ($enabled && ! $wasEnabled) {
            $pausedAt = $this->settings->getLoanPaymentsPausedAt();
            $resumedAt = now();

            $updatedCount = DB::transaction(function () use ($pausedAt, $resumedAt): int {
                $updatedCount = $pausedAt
                    ? $this->loanService->shiftLoanDueDatesForPausedPeriod($pausedAt, $resumedAt)
                    : 0;

                $this->settings->setLoanPaymentsPausedAt(null);
                $this->settings->setLoanPaymentsEnabled(true);

                return $updatedCount;
            });

            $this->auditLogger->success(
                category: 'settings',
                action: 'loan_payments_resumed',
                context: [
                    'changes' => [
                        'loan_payments_enabled' => [
                            'from' => $wasEnabled,
                            'to' => true,
                        ],
                        'loan_payments_paused_at' => [
                            'from' => $pausedAt?->toIso8601String(),
                            'to' => null,
                        ],
                    ],
                    'data' => [
                        'adjusted_due_dates' => $updatedCount,
                    ],
                ],
                message: 'Loan payments resumed.'
            );

            return redirect()->route('admin.settings')->with([
                'alert-message' => $updatedCount > 0
                    ? "Loan payments resumed. Adjusted due dates for {$updatedCount} active loans."
                    : 'Loan payments resumed.',
                'alert-type' => 'success',
            ]);
        }

        DB::transaction(function () use ($enabled, $wasEnabled): void {
            if (! $enabled && $wasEnabled) {
                $this->settings->setLoanPaymentsPausedAt(now());
            }

            $this->settings->setLoanPaymentsEnabled($enabled);
        });

        $this->auditLogger->success(
            category: 'settings',
            action: 'loan_payments_toggle',
            context: [
                'changes' => [
                    'loan_payments_enabled' => [
                        'from' => $wasEnabled,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Loan payment setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Loan payments enabled.' : 'Loan payments paused.',
            'alert-type' => 'success',
        ]);
    }

    public function updateGrantApprovals(UpdateGrantApprovalSettingsRequest $request): RedirectResponse
    {
        $previous = $this->settings->isGrantApprovalsEnabled();
        $enabled = (bool) $request->validated('grant_approvals_enabled');

        $this->settings->setGrantApprovalsEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'grant_approvals_toggle',
            context: [
                'changes' => [
                    'grant_approvals_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Grant approvals setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Grant approvals enabled.' : 'Grant approvals paused.',
            'alert-type' => 'success',
        ]);
    }
}
