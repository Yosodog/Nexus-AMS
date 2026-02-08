<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Loan;
use App\Services\LoanService;
use App\Services\SettingService;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoansController extends Controller
{
    public function __construct(private LoanService $loanService) {}

    public function apply(Request $request): RedirectResponse
    {
        if (! SettingService::isLoanApplicationsEnabled()) {
            return redirect()->back()->with('alert-message', 'Loan applications are currently closed.')->with(
                'alert-type',
                'error'
            );
        }

        $request->validate([
            'amount' => 'required|numeric|min:100000',
            'account_id' => 'required|exists:accounts,id',
            'term_weeks' => 'required|integer|min:1|max:52',
        ]);

        $nation = Auth::user()->nation;
        $account = Account::findOrFail($request->account_id);

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

    public function index(): View
    {
        $nation = Auth::user()->nation;
        $loanPaymentsEnabled = SettingService::isLoanPaymentsEnabled();
        $loanPaymentsPausedAt = SettingService::getLoanPaymentsPausedAt();
        $loanApplicationsEnabled = SettingService::isLoanApplicationsEnabled();

        // Get only active loans (approved and not fully paid)
        $activeLoans = Loan::where('nation_id', $nation->id)
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->with('payments')
            ->get()
            ->map(function ($loan) use ($loanPaymentsEnabled) {
                $loan->next_payment_due = $loanPaymentsEnabled ? $loan->getNextPaymentDue() : 0.0;

                return $loan;
            });

        // Fetch all loans for history (pending, denied, and paid)
        $loanHistory = Loan::where('nation_id', $nation->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Fetch all accounts
        $accounts = $nation->accounts;

        return view('loans.index', compact(
            'activeLoans',
            'loanHistory',
            'accounts',
            'loanPaymentsEnabled',
            'loanPaymentsPausedAt',
            'loanApplicationsEnabled'
        ));
    }

    /**
     * @throws ValidationException
     */
    public function repay(Request $request): RedirectResponse
    {
        $request->validate([
            'loan_id' => 'required|exists:loans,id',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $loan = Loan::findOrFail($request->loan_id);
        $account = Account::findOrFail($request->account_id);
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
