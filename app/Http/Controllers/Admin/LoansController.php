<?php

namespace App\Http\Controllers\Admin;

use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoansController
{
    use AuthorizesRequests;

    protected LoanService $loanService;

    public function __construct(LoanService $loanService)
    {
        $this->loanService = $loanService;
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

        return view(
            'admin.loans.index',
            compact(
                'totalApproved',
                'totalDenied',
                'pendingCount',
                'totalLoanedFunds',
                'pendingLoans',
                'activeLoans'
            )
        );
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
            'term_weeks' => 'required|integer|min:0|max:52',
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

        // Define base validation rules
        $rules = [
            'interest_rate' => 'required|numeric|min:0|max:100',
            'term_weeks' => 'required|integer|min:1|max:52',
            'next_due_date' => 'required|date|after:today',
            'remaining_balance' => 'required|numeric|min:0|max:'.$loan->amount,
            // Ensure balance is not higher than loan amount
        ];

        // Only require amount if no payments exist
        if (! $loan->payments()->exists()) {
            $rules['amount'] = 'required|numeric|min:1';
        }

        $request->validate($rules);

        // Prevent changing loan amount if payments exist
        if ($loan->payments()->exists() && $request->amount != $loan->amount) {
            throw ValidationException::withMessages([
                'amount' => 'Loan amount cannot be changed after payments have been made.',
            ]);
        }

        $loan->update([
            'amount' => $request->amount ?? $loan->amount, // Keep existing amount if not provided
            'interest_rate' => $request->interest_rate,
            'term_weeks' => $request->term_weeks,
            'next_due_date' => $request->next_due_date,
            'remaining_balance' => $request->remaining_balance,
        ]);

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
