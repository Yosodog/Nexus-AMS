<?php

namespace App\Http\Controllers\Admin;

use App\Models\Loans;
use App\Services\LoanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoansController
{
    protected LoanService $loanService;

    public function __construct(LoanService $loanService)
    {
        $this->loanService = $loanService;
    }

    /**
     * Display the admin loan dashboard.
     */
    public function index()
    {
        $totalApproved = Loans::where('status', 'approved')->count();
        $totalDenied = Loans::where('status', 'denied')->count();
        $pendingCount = Loans::where('status', 'pending')->count();
        $totalLoanedFunds = Loans::where('status', 'approved')->sum('amount');

        $pendingLoans = Loans::where('status', 'pending')->with('nation')->get();
        $activeLoans = Loans::where('status', 'approved')->with('nation')->get();

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
     */
    public function approve(Request $request, Loans $loan)
    {
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
     */
    public function deny(Loans $loan)
    {
        $this->loanService->denyLoan($loan, Auth::user()->nation);

        return redirect()->route('admin.loans')->with('alert-message', 'Loan denied ❌')->with(
            'alert-type',
            'success'
        );
    }
}