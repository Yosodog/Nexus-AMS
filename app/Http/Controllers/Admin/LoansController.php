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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index()
    {
        $this->authorize('view-loans');

        $totalApproved = Loan::where('status', 'approved')->count();
        $totalDenied = Loan::where('status', 'denied')->count();
        $pendingCount = Loan::where('status', 'pending')->count();
        $totalLoanedFunds = Loan::where('status', 'approved')->sum('amount');

        $pendingLoans = Loan::where('status', 'pending')->with('nation')->get();
        $activeLoans = Loan::where('status', 'approved')->with('nation')->get();

        $defaultLoanInterestRate = SettingService::getDefaultLoanInterestRate();

        return view('admin.loans.index', compact(
            'totalApproved',
            'totalDenied',
            'pendingCount',
            'totalLoanedFunds',
            'pendingLoans',
            'activeLoans',
            'defaultLoanInterestRate'
        ));
    }

    /**
     * Approve a loan with modifications (amount, interest rate, term weeks).
     *
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function approve(Request $request, Loan $loan)
    {
        $this->authorize('manage-loans');

        $request->validate([
            'amount' => 'required|numeric',
            'interest_rate' => 'required|numeric|min:0|max:100',
            'term_weeks' => 'required|integer|min:1|max:52',
        ]);

        $this->loanService->approveLoan(
            $loan,
            $request->amount,
            $request->interest_rate,
            $request->term_weeks,
            Auth::user()->nation
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

        $this->loanService->denyLoan($loan, Auth::user()->nation);

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

        $loan->load('payments'); // Load payments
        $loanService = app(LoanService::class);

        return view('admin.loans.view', [
            'loan' => $loan,
            'nextMinimumPayment' => $loanService->calculateWeeklyPayment($loan), // Calculate next min payment
        ]);
    }

    /**
     * Handle the loan update request.
     *
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(Request $request, Loan $loan)
    {
        $this->authorize('manage-loans');

        $changes = [];
        $updatedLoan = null;

        DB::transaction(function () use ($request, $loan, &$changes, &$updatedLoan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $before = $lockedLoan->only(['amount', 'interest_rate', 'term_weeks', 'next_due_date', 'remaining_balance']);

            $rules = [
                'interest_rate' => 'required|numeric|min:0|max:100',
                'term_weeks' => 'required|integer|min:1|max:52',
                'next_due_date' => 'required|date|after:today',
                'remaining_balance' => 'required|numeric|min:0|max:'.$lockedLoan->amount,
            ];

            if (! $lockedLoan->payments()->exists()) {
                $rules['amount'] = 'required|numeric|min:1';
            }

            $request->validate($rules);

            if ($lockedLoan->payments()->exists() && $request->amount != $lockedLoan->amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Loan amount cannot be changed after payments have been made.',
                ]);
            }

            $lockedLoan->update([
                'amount' => $request->amount ?? $lockedLoan->amount,
                'interest_rate' => $request->interest_rate,
                'term_weeks' => $request->term_weeks,
                'next_due_date' => $request->next_due_date,
                'remaining_balance' => $request->remaining_balance,
            ]);

            $updatedLoan = $lockedLoan->fresh();

            foreach (['amount', 'interest_rate', 'term_weeks', 'next_due_date', 'remaining_balance'] as $field) {
                $afterValue = $updatedLoan->{$field};
                $beforeValue = $before[$field] ?? null;

                if ((string) $beforeValue !== (string) $afterValue) {
                    $changes[$field] = [
                        'from' => $beforeValue,
                        'to' => $afterValue,
                    ];
                }
            }
        });

        if ($updatedLoan) {
            $this->auditLogger->recordAfterCommit(
                category: 'loans',
                action: 'loan_updated',
                outcome: 'success',
                severity: 'warning',
                subject: $updatedLoan,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $updatedLoan->account_id, 'role' => 'account'],
                    ],
                    'changes' => $changes,
                ],
                message: 'Loan updated.'
            );
        }

        return redirect()->route('admin.loans')->with('alert-message', 'Loan updated successfully! ✅')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function markAsPaid(Loan $loan)
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
