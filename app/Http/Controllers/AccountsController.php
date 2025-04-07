<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Models\Accounts;
use App\Models\Loans;
use App\Services\AccountService;
use App\Services\LoanService;
use Closure;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountsController extends Controller
{
    protected LoanService $loanService;

    public function __construct(LoanService $loanService)
    {
        $this->loanService = $loanService;
    }

    public function index()
    {
        $accounts = AccountService::getAccountsByNid(Auth::user()->nation_id);

        if ($accounts->count() === 0) {
            return redirect()->route("accounts.create");
        }

        // Get active loans for the transfer dropdown
        $activeLoans = Loans::where('nation_id', Auth::user()->nation_id)
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->get();

        return view("accounts.index", [
            "accounts" => $accounts,
            "activeLoans" => $activeLoans,
        ]);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function transfer(Request $request)
    {
        // Check if this is a loan repayment
        if (str_starts_with($request->input('to'), 'loan_')) {
            $loanId = (int)substr($request->input('to'), 5);

            // First validate basic requirements
            $request->validate([
                'from' => 'required|integer|exists:accounts,id',
                'money' => 'required|numeric|min:0.01',
            ]);

            try {
                $loan = Loans::findOrFail($loanId);
                $account = Accounts::findOrFail($request->input('from'));

                // Validate loan ownership
                if ($loan->nation_id !== Auth::user()->nation_id) {
                    throw ValidationException::withMessages([
                        'to' => ['You do not own this loan.']
                    ]);
                }

                // Validate account ownership
                if ($account->nation_id !== Auth::user()->nation_id) {
                    throw ValidationException::withMessages([
                        'from' => ['You do not own this account.']
                    ]);
                }

                // Validate payment amount doesn't exceed remaining balance
                if ($request->input('money') > $loan->remaining_balance) {
                    throw ValidationException::withMessages([
                        'money' => [
                            'Payment amount cannot exceed the remaining loan balance of $' . number_format(
                                $loan->remaining_balance,
                                2
                            )
                        ]
                    ]);
                }

                // Validate account has sufficient funds
                if ($request->input('money') > $account->money) {
                    throw ValidationException::withMessages([
                        'money' => ['Insufficient funds in the selected account.']
                    ]);
                }

                // Process the loan repayment
                $this->loanService->repayLoan($loan, $account, $request->input('money'));

                return redirect()->back()->with([
                    'alert-message' => 'Loan payment successful!',
                    'alert-type' => 'success',
                ]);
            } catch (ValidationException $e) {
                return redirect()->back()->withErrors($e->errors())->with('alert-type', 'error');
            } catch (Exception $e) {
                Log::error("Error processing loan payment: " . $e->getMessage());
                return redirect()->back()->with([
                    'alert-message' => 'An error occurred while processing your loan payment. Please try again.',
                    'alert-type' => 'error',
                ]);
            }
        }

        // Regular transfer logic
        if ($request->input("to") == "nation") {
            $request->validate([
                'from' => 'required|integer|exists:accounts,id',
            ]);
        } else {
            $request->validate([
                'from' => 'required|integer|exists:accounts,id',
                'to' => 'required|integer|exists:accounts,id',
            ]);
        }

        $transfer = [
            "money" => $request->input("money") ?? 0,
            "coal" => $request->input("coal") ?? 0,
            "oil" => $request->input("oil") ?? 0,
            "uranium" => $request->input("uranium") ?? 0,
            "iron" => $request->input("iron") ?? 0,
            "bauxite" => $request->input("bauxite") ?? 0,
            "lead" => $request->input("lead") ?? 0,
            "gasoline" => $request->input("gasoline") ?? 0,
            "munitions" => $request->input("munitions") ?? 0,
            "steel" => $request->input("steel") ?? 0,
            "aluminum" => $request->input("aluminum") ?? 0,
            "food" => $request->input("food") ?? 0,
        ];

        try {
            // Validate that at least one resource is being transferred
            $hasResources = false;
            foreach ($transfer as $amount) {
                if ($amount > 0) {
                    $hasResources = true;
                    break;
                }
            }

            if (!$hasResources) {
                throw ValidationException::withMessages([
                    'transfer' => ['You must transfer at least one resource with an amount greater than 0.']
                ]);
            }

            // Get the source account and validate ownership
            $fromAccount = Accounts::findOrFail($request->input("from"));
            if ($fromAccount->nation_id !== Auth::user()->nation_id) {
                throw ValidationException::withMessages([
                    'from' => ['You do not own the source account.']
                ]);
            }

            // Validate resource amounts don't exceed available balance
            foreach ($transfer as $resource => $amount) {
                if ($amount > $fromAccount->{$resource}) {
                    throw ValidationException::withMessages([
                        $resource => [
                            "Insufficient {$resource} in source account. Available: " . number_format(
                                $fromAccount->{$resource},
                                2
                            )
                        ]
                    ]);
                }
            }

            // If transferring to another account, validate ownership
            if ($request->input("to") !== "nation") {
                $toAccount = Accounts::findOrFail($request->input("to"));
                if ($toAccount->nation_id !== Auth::user()->nation_id) {
                    throw ValidationException::withMessages([
                        'to' => ['You do not own the destination account.']
                    ]);
                }

                // Validate not transferring to the same account
                if ($fromAccount->id === $toAccount->id) {
                    throw ValidationException::withMessages([
                        'to' => ['Cannot transfer resources to the same account.']
                    ]);
                }

                AccountService::transferToAccount(
                    $request->input("from"),
                    $request->input("to"),
                    $transfer
                );
            } else {
                AccountService::transferToNation(
                    $request->input("from"),
                    Auth::user()->nation_id,
                    $transfer
                );
            }

            return redirect()->back()->with([
                'alert-message' => 'Transfer successful!',
                "alert-type" => 'success',
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error("Error when transferring. " . $e->getMessage());

            return redirect()->back()->withErrors(
                "There was an error with your transfer. Please try again"
            );
        }
    }

    /**
     * @param Accounts $accounts
     *
     * @return Closure|Container|mixed|object|null
     */
    public function viewAccount(Accounts $accounts)
    {
        if ($accounts->nation_id != Auth::user()->nation_id) {
            abort("403");
        }

        $accounts->load("nation");

        // Get related transactions where they are to or from
        $transactions = AccountService::getRelatedTransactions($accounts);

        return view("accounts.view", [
            "account" => $accounts,
            "transactions" => $transactions
        ]);
    }

    /**
     * @return Closure|Container|mixed|object|null
     */
    public function createView()
    {
        return view("accounts.create");
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function create(Request $request)
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('accounts')->where(function ($query) {
                    return $query->where('nation_id', Auth::user()->nation_id)
                        ->whereNull('deleted_at');
                })
            ],
        ]);

        AccountService::createAccount(
            Auth::user()->nation_id,
            $request->input("name")
        );

        return redirect()
            ->route('accounts')
            ->with([
                'alert-message' => 'Account created successfully.',
                "alert-type" => 'success',
            ]);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function delete(Request $request)
    {
        $account = AccountService::getAccountById($request->account_id);

        try {
            // Ensure we own this account
            if (Auth::user()->nation_id !== $account->nation_id) {
                throw new UserErrorException("You don't own that account");
            }

            AccountService::deleteAccount($account);
        } catch (UserErrorException $e) {
            return redirect()
                ->back()
                ->withErrors([$e->getMessage()])
                ->with(["alert-type" => "error"]);
        }

        return redirect()
            ->route("accounts")
            ->with("success", "Account deleted!");
    }

}
