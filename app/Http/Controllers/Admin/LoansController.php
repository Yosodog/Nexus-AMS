<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdateLoanDefaultInterestRateRequest;
use App\Models\Loan;
use App\Services\AuditLogger;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LoansController
{
    use AuthorizesRequests;

    protected LoanService $loanService;

    protected AuditLogger $auditLogger;

    public function __construct(LoanService $loanService, AuditLogger $auditLogger)
    {
        $this->loanService = $loanService;
        $this->auditLogger = $auditLogger;
    }

    /**
     * @return Factory|View|Application|object
     *
     * @throws AuthorizationException
     */
    public function index()
    {
        $this->authorize('view-loans');

        $totalApproved = Loan::whereIn('status', ['approved', 'missed'])->count();
        $totalDenied = Loan::where('status', 'denied')->count();
        $pendingCount = Loan::where('status', 'pending')->count();
        $totalLoanedFunds = Loan::whereIn('status', ['approved', 'missed'])->sum('amount');

        $pendingLoans = Loan::where('status', 'pending')->with('nation')->get();
        $activeLoans = Loan::whereIn('status', ['approved', 'missed'])
            ->with(['nation', 'payments'])
            ->get()
            ->map(function (Loan $loan) {
                $loan->current_amount_due = $this->loanService->calculateCurrentAmountDue($loan);
                $cycleProgress = $this->loanService->getCurrentCycleProgress($loan);
                $loan->cycle_paid = $cycleProgress['paid_this_cycle'];
                $loan->cycle_remaining = $cycleProgress['remaining_to_scheduled'];
                $loan->cycle_start = $cycleProgress['cycle_start'];
                $loan->cycle_end = $cycleProgress['cycle_end'];
                $fullPreview = $this->loanService->previewPaymentBreakdown(
                    $loan,
                    (float) $loan->remaining_balance + (float) $loan->accrued_interest_due + 999999999
                );
                $loan->effective_interest_due_now = (float) $fullPreview['interest'];
                $loan->total_owed_now = round((float) $loan->remaining_balance + (float) $loan->effective_interest_due_now, 2);
                $loan->days_to_due = $loan->next_due_date
                    ? now()->startOfDay()->diffInDays($loan->next_due_date->copy()->startOfDay(), false)
                    : null;

                return $loan;
            });

        $portfolioStats = [
            'active_count' => $activeLoans->count(),
            'missed_count' => $activeLoans->where('status', 'missed')->count(),
            'outstanding_principal' => (float) $activeLoans->sum('remaining_balance'),
            'past_due_total' => (float) $activeLoans->sum('past_due_amount'),
            'accrued_interest_total' => (float) $activeLoans->sum('effective_interest_due_now'),
            'current_due_total' => (float) $activeLoans->sum('current_amount_due'),
            'total_payoff_now' => (float) $activeLoans->sum('total_owed_now'),
        ];

        $defaultLoanInterestRate = SettingService::getDefaultLoanInterestRate();
        $loanApplicationsEnabled = SettingService::isLoanApplicationsEnabled();
        $loanPaymentsEnabled = SettingService::isLoanPaymentsEnabled();

        return view('admin.loans.index', compact(
            'totalApproved',
            'totalDenied',
            'pendingCount',
            'totalLoanedFunds',
            'portfolioStats',
            'pendingLoans',
            'activeLoans',
            'defaultLoanInterestRate',
            'loanApplicationsEnabled',
            'loanPaymentsEnabled'
        ));
    }

    /**
     * Approve a loan with modifications (amount, interest rate, term weeks).
     *
     * @throws AuthorizationException
     */
    public function approve(Request $request, Loan $loan): RedirectResponse
    {
        $this->authorize('manage-loans');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.$loan->amount],
            'interest_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'term_weeks' => ['required', 'integer', 'between:1,52'],
        ]);

        $this->loanService->approveLoan(
            $loan,
            (float) $validated['amount'],
            (float) $validated['interest_rate'],
            (int) $validated['term_weeks']
        );

        return redirect()->route('admin.loans')->with('alert-message', 'Loan approved successfully! ✅')->with(
            'alert-type',
            'success'
        );
    }

    public function updateDefaultInterestRate(UpdateLoanDefaultInterestRateRequest $request): RedirectResponse
    {
        $previous = SettingService::getDefaultLoanInterestRate();
        $rate = (float) $request->validated()['default_interest_rate'];

        SettingService::setDefaultLoanInterestRate($rate);

        $this->auditLogger->success(
            category: 'settings',
            action: 'loan_default_interest_rate_updated',
            context: [
                'changes' => [
                    'loan_default_interest_rate' => [
                        'from' => $previous,
                        'to' => $rate,
                    ],
                ],
            ],
            message: 'Default loan interest rate updated.'
        );

        return redirect()->route('admin.loans')->with([
            'alert-message' => 'Default loan interest rate updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateLoanApplications(Request $request): RedirectResponse
    {
        $this->authorize('manage-loans');

        $request->validate([
            'loan_applications_enabled' => 'required|boolean',
        ]);

        $previous = SettingService::isLoanApplicationsEnabled();
        $enabled = $request->boolean('loan_applications_enabled');

        SettingService::setLoanApplicationsEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'loan_applications_updated',
            context: [
                'changes' => [
                    'loan_applications_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Loan application availability updated.'
        );

        return redirect()->route('admin.loans')->with([
            'alert-message' => 'Loan application availability updated.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * Deny a loan application.
     *
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function deny(Loan $loan)
    {
        $this->authorize('manage-loans');

        $this->loanService->denyLoan($loan);

        return redirect()->route('admin.loans')->with('alert-message', 'Loan denied ❌')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * Display the loan view/edit form.
     *
     * @return Factory|View|Application|object
     *
     * @throws AuthorizationException
     */
    public function view(Loan $loan)
    {
        $this->authorize('view-loans');

        $loan->load(['payments.account', 'nation']);
        $loanService = app(LoanService::class);
        $cycleProgress = $loanService->getCurrentCycleProgress($loan);
        $nextMinimumPayment = $loanService->calculateCurrentAmountDue($loan);
        $nextPaymentPreview = $loanService->previewPaymentBreakdown(
            $loan,
            $nextMinimumPayment > 0
                ? $nextMinimumPayment
                : min(
                    $loanService->calculateWeeklyPayment($loan),
                    (float) $loan->remaining_balance + (float) $loan->accrued_interest_due
                )
        );
        $fullPayoffPreview = $loanService->previewPaymentBreakdown(
            $loan,
            (float) $loan->remaining_balance + (float) $loan->accrued_interest_due + 999999999
        );
        $approvedAt = $loan->approved_at ? Carbon::parse($loan->approved_at) : null;
        $weeksElapsed = $approvedAt
            ? max(0, (int) floor($approvedAt->diffInDays(now()) / 7))
            : 0;
        $payments = $loan->payments;
        $totals = [
            'paid_total' => (float) $payments->sum('amount'),
            'paid_principal' => (float) $payments->sum('principal_paid'),
            'paid_interest' => (float) $payments->sum('interest_paid'),
        ];
        $amortizationSchedule = $loanService->buildAmortizationSchedule($loan);

        return view('admin.loans.view', [
            'loan' => $loan,
            'nextMinimumPayment' => $nextMinimumPayment,
            'scheduledWeeklyPayment' => $loanService->calculateWeeklyPayment($loan),
            'nextPaymentPreview' => $nextPaymentPreview,
            'fullPayoffPreview' => $fullPayoffPreview,
            'cycleProgress' => $cycleProgress,
            'weeksElapsed' => $weeksElapsed,
            'totals' => $totals,
            'loanPaymentsEnabled' => SettingService::isLoanPaymentsEnabled(),
            'amortizationSchedule' => $amortizationSchedule,
        ]);
    }

    /**
     * Handle the loan update request.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(Request $request, Loan $loan): RedirectResponse
    {
        $this->authorize('manage-loans');

        $this->loanService->updateLoan($loan, $request->only([
            'amount',
            'interest_rate',
            'term_weeks',
            'next_due_date',
            'remaining_balance',
        ]));

        return redirect()->route('admin.loans')->with('alert-message', 'Loan updated successfully! ✅')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function markAsPaid(Loan $loan): RedirectResponse
    {
        $this->authorize('manage-loans');

        // Ensure the loan is not already marked as paid
        if ($loan->status === 'paid') {
            return redirect()->route('admin.loans')->with(
                'alert-message',
                'This loan is already marked as paid.'
            )->with(
                'alert-type',
                'error'
            );
        }

        $this->loanService->markLoanAsPaid($loan);

        return redirect()->route('admin.loans')->with('alert-message', 'Loan successfully marked as paid! ✅')->with(
            'alert-type',
            'success'
        );
    }
}
