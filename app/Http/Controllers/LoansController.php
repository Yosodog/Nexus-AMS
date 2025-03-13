<?php

namespace App\Http\Controllers;

use App\Models\Accounts;
use App\Models\Loans;
use App\Services\LoanService;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoansController extends Controller
{
    protected LoanService $loanService;

    public function __construct(LoanService $loanService)
    {
        $this->loanService = $loanService;
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function apply(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'account_id' => 'required|exists:accounts,id',
            'term_weeks' => 'required|integer|min:1|max:52'
        ]);

        $nation = Auth::user()->nation;
        $account = Accounts::findOrFail($request->account_id);

        try {
            // Validate nation eligibility
            $this->loanService->validateLoanEligibility($nation, $account);

            $this->loanService->applyForLoan($nation, $account, $request->amount, $request->term_weeks);
            return redirect()->back()->with('alert-message', 'Loan application submitted! ✅')->with(
                'alert-type',
                'success'
            );
        } catch (Exception $e) {
            return redirect()->back()->with('alert-message', $e->getMessage())->with('alert-type', 'error');
        }
    }

    /**
     * @return Factory|View|Application|object
     */
    public function index()
    {
        $nation = Auth::user()->nation;

        // Get only active loans (approved and not fully paid)
        $activeLoans = Loans::where('nation_id', $nation->id)
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->with('payments')
            ->get()
            ->map(function ($loan) {
                $loan->next_payment_due = $loan->getNextPaymentDue(); // Add next minimum payment
                return $loan;
            });

        // Fetch all loans for history (pending, denied, and paid)
        $loanHistory = Loans::where('nation_id', $nation->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Fetch all accounts
        $accounts = $nation->accounts;

        return view('loans.index', compact('activeLoans', 'loanHistory', 'accounts'));
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws ValidationException
     */
    public function repay(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:loans,id',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:1',
        ]);

        $loan = Loans::findOrFail($request->loan_id);
        $account = Accounts::findOrFail($request->account_id);
        $userNation = Auth::user()->nation;

        // Ensure the loan belongs to the nation
        if ($loan->nation_id !== $userNation->id) {
            throw ValidationException::withMessages(['loan_id' => 'You do not own this loan.']);
        }

        // Ensure the account belongs to the nation
        if ($account->nation_id !== $userNation->id) {
            throw ValidationException::withMessages(['account_id' => 'You do not own this account.']);
        }

        // Process the loan repayment
        try {
            $this->loanService->repayLoan($loan, $account, $request->amount);
            return redirect()->back()->with('alert-message', 'Loan payment successful! ✅')->with(
                'alert-type',
                'success'
            );
        } catch (Exception $e) {
            return redirect()->back()->with('alert-message', $e->getMessage())->with('alert-type', 'error');
        }
    }
}
