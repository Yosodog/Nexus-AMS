<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\Loan;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Services\AccountService;
use App\Services\DirectDepositService;
use App\Services\LoanService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TradePriceService;
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
        $nationId = Auth::user()->nation_id;
        $accounts = AccountService::getAccountsByNid($nationId);

        if ($accounts->count() === 0) {
            return redirect()->route("accounts.create");
        }

        $activeLoans = Loan::where('nation_id', $nationId)
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->get();

        $ddService = app(DirectDepositService::class);

        // MMR Assistant
        $config = MMRConfig::where('nation_id', $nationId)->first();
        $settings = MMRSetting::orderBy('resource')->get()->keyBy('resource');
        $resources = PWHelperService::resources(false);
        $mmrEnabled = SettingService::getMMRAssistantEnabled();

        $lastTaxRecord = DirectDepositLog::where('nation_id', $nationId)
            ->latest('created_at')
            ->first();

        $afterTaxIncome = $lastTaxRecord?->money ?? 0;

        $logs = MMRAssistantPurchase::query()
            ->when($config?->account_id, fn($q) => $q->where('account_id', $config->account_id))
            ->orWhereHas('account', fn($q) => $q->where('nation_id', $nationId))
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $priceService = app(TradePriceService::class);
        $mmrPrices = $priceService->get24hAverageWithSurcharge();

        return view("accounts.index", [
            "accounts" => $accounts,
            "activeLoans" => $activeLoans,
            "enrollment" => DirectDepositEnrollment::with('account')->where('nation_id', $nationId)->first(),
            "bracket" => $ddService->getApplicableBracket(Auth::user()->nation),
            // MMR data
            "mmrConfig" => $config,
            "mmrSettings" => $settings,
            "mmrResources" => $resources,
            "mmrEnabled" => $mmrEnabled,
            "mmrLogs" => $logs,
            "mmrAfterTaxIncome" => $afterTaxIncome,
            "mmrPrices" => $mmrPrices,
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
                $loan = Loan::findOrFail($loanId);
                $account = Account::findOrFail($request->input('from'));

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

        $transfer = [];

        foreach (PWHelperService::resources() as $resource) {
            $transfer[$resource] = $request->input($resource) ?? 0;
        }

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
            $fromAccount = Account::findOrFail($request->input("from"));
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
                $toAccount = Account::findOrFail($request->input("to"));
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
                $transaction = AccountService::transferToNation(
                    $request->input("from"),
                    Auth::user()->nation_id,
                    $transfer
                );

                if ($transaction->requires_admin_approval) {
                    return redirect()->back()->with([
                        'alert-message' => 'Withdrawal submitted for review. An admin will approve it soon.',
                        'alert-type' => 'info',
                    ]);
                }
            }

            return redirect()->back()->with([
                'alert-message' => 'Transfer successful!',
                "alert-type" => 'success',
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->with('alert-type', 'error');
        } catch (UserErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error("Error when transferring. " . $e->getMessage());

            return redirect()->back()->withErrors(
                "There was an error with your transfer. Please try again"
            );
        }
    }

    /**
     * @param Account $accounts
     *
     * @return Closure|Container|mixed|object|null
     */
    public function viewAccount(Account $accounts)
    {
        if ($accounts->nation_id != Auth::user()->nation_id) {
            abort("403");
        }

        $accounts->load("nation");

        $transactions = AccountService::getRelatedTransactions($accounts);

        $ddLogs = DirectDepositLog::where('account_id', $accounts->id)
            ->latest()
            ->limit(50)
            ->orderBy('created_at', 'DESC')
            ->get();

        return view("accounts.view", [
            "account" => $accounts,
            "transactions" => $transactions,
            "ddLogs" => $ddLogs,
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
