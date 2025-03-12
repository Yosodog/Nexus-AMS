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
        $loans = Loans::where('nation_id', Auth::user()->nation_id)->get();
        $accounts = Auth::user()->accounts;

        return view('loans.index', compact('loans', 'accounts'));
    }
}
