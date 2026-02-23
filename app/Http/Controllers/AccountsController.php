<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\TransferRequest;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Services\AccountService;
use App\Services\AutoWithdrawService;
use App\Services\DirectDepositService;
use App\Services\LoanService;
use App\Services\MemberTransferService;
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

    public function __construct(
        LoanService $loanService,
        protected AutoWithdrawService $autoWithdrawService,
        protected MemberTransferService $memberTransferService
    ) {
        $this->loanService = $loanService;
    }

    public function index()
    {
        $context = $this->buildDashboardContext();

        if ($context instanceof RedirectResponse) {
            return $context;
        }

        $nationId = Auth::user()->nation_id;

        $context = array_merge($context, [
            'autoWithdrawSettings' => $this->autoWithdrawService->getNationSettings($nationId)->keyBy('resource'),
            'autoWithdrawResources' => PWHelperService::resources(false),
            'autoWithdrawEnabled' => SettingService::isAutoWithdrawEnabled(),
        ]);

        return view('accounts.index', $context);
    }

    /**
     * @return RedirectResponse
     */
    public function transfer(TransferRequest $request)
    {
        // Check if this is a loan repayment
        if (str_starts_with($request->input('to'), 'loan_')) {
            $loanId = (int) substr($request->input('to'), 5);

            // First validate basic requirements
            try {
                $loan = Loan::findOrFail($loanId);
                $account = Account::findOrFail($request->input('from'));

                // Validate loan ownership
                if ($loan->nation_id !== Auth::user()->nation_id) {
                    throw ValidationException::withMessages([
                        'to' => ['You do not own this loan.'],
                    ]);
                }

                // Validate account ownership
                if ($account->nation_id !== Auth::user()->nation_id) {
                    throw ValidationException::withMessages([
                        'from' => ['You do not own this account.'],
                    ]);
                }

                // Validate payment amount doesn't exceed remaining balance
                if ($request->input('money') > $loan->remaining_balance) {
                    throw ValidationException::withMessages([
                        'money' => [
                            'Payment amount cannot exceed the remaining loan balance of $'.number_format(
                                $loan->remaining_balance,
                                2
                            ),
                        ],
                    ]);
                }

                // Validate account has sufficient funds
                if ($request->input('money') > $account->money) {
                    throw ValidationException::withMessages([
                        'money' => ['Insufficient funds in the selected account.'],
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
                Log::error('Error processing loan payment: '.$e->getMessage());

                return redirect()->back()->with([
                    'alert-message' => 'An error occurred while processing your loan payment. Please try again.',
                    'alert-type' => 'error',
                ]);
            }
        }

        // Regular transfer logic
        $transfer = [];

        foreach (PWHelperService::resources() as $resource) {
            $transfer[$resource] = $request->input($resource) ?? 0;
        }

        try {
            // Get the source account and validate ownership
            $fromAccount = Account::findOrFail($request->input('from'));
            if ($fromAccount->nation_id !== Auth::user()->nation_id) {
                throw ValidationException::withMessages([
                    'from' => ['You do not own the source account.'],
                ]);
            }

            // Validate resource amounts don't exceed available balance
            foreach ($transfer as $resource => $amount) {
                if ($amount > $fromAccount->{$resource}) {
                    throw ValidationException::withMessages([
                        $resource => [
                            "Insufficient {$resource} in source account. Available: ".number_format(
                                $fromAccount->{$resource},
                                2
                            ),
                        ],
                    ]);
                }
            }

            // If transferring to another account or member
            if ($request->input('to') !== 'nation') {
                $toAccountId = (int) $request->input('to');
                $toAccount = Account::findOrFail($toAccountId);

                if ($fromAccount->id === $toAccount->id) {
                    throw ValidationException::withMessages([
                        'to' => ['Cannot transfer resources to the same account.'],
                    ]);
                }

                if ($toAccount->nation_id === Auth::user()->nation_id) {
                    AccountService::transferToAccount(
                        $request->input('from'),
                        $toAccountId,
                        $transfer
                    );
                } else {
                    $this->memberTransferService->requestTransfer(
                        Auth::user(),
                        (int) $request->input('from'),
                        $toAccountId,
                        $transfer
                    );

                    return redirect()->back()->with([
                        'alert-message' => 'Transfer request sent. Awaiting recipient approval.',
                        'alert-type' => 'info',
                    ]);
                }
            } else {
                $transaction = AccountService::transferToNation(
                    $request->input('from'),
                    Auth::user()->nation_id,
                    $transfer
                );

                if ($transaction->requires_admin_approval) {
                    return redirect()->back()->with([
                        'alert-message' => 'That withdrawal was over the limit. It will be processed once approved by an admin.',
                        'alert-type' => 'info',
                    ]);
                }
            }

            return redirect()->back()->with([
                'alert-message' => 'Transfer successful!',
                'alert-type' => 'success',
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->with('alert-type', 'error');
        } catch (UserErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error('Error when transferring. '.$e->getMessage());

            return redirect()->back()->withErrors(
                'There was an error with your transfer. Please try again'
            );
        }
    }

    /**
     * @return Closure|Container|mixed|object|null
     */
    public function viewAccount(Account $accounts)
    {
        if ($accounts->nation_id != Auth::user()->nation_id) {
            abort('403');
        }

        $accounts->load('nation');

        $transactions = AccountService::getRelatedTransactions($accounts);
        $manualTransactions = AccountService::getRelatedManualTransactions($accounts);

        $ddLogs = DirectDepositLog::where('account_id', $accounts->id)
            ->latest()
            ->limit(50)
            ->orderBy('created_at', 'DESC')
            ->get();

        return view('accounts.view', [
            'account' => $accounts,
            'transactions' => $transactions,
            'manualTransactions' => $manualTransactions,
            'ddLogs' => $ddLogs,
        ]);
    }

    /**
     * @return Closure|Container|mixed|object|null
     */
    public function createView()
    {
        return view('accounts.create');
    }

    /**
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
                }),
            ],
        ]);

        AccountService::createAccount(
            Auth::user()->nation_id,
            $request->input('name')
        );

        return redirect()
            ->route('accounts')
            ->with([
                'alert-message' => 'Account created successfully.',
                'alert-type' => 'success',
            ]);
    }

    /**
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
                ->with(['alert-type' => 'error']);
        }

        return redirect()
            ->route('accounts')
            ->with('success', 'Account deleted!');
    }

    public function updateAutoWithdraw(Request $request): RedirectResponse
    {
        $nationId = Auth::user()->nation_id;
        $resources = PWHelperService::resources(false);

        $rules = [
            'settings' => ['required', 'array'],
        ];

        foreach ($resources as $resource) {
            $rules["settings.{$resource}.account_id"] = [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('nation_id', $nationId),
            ];
            $rules["settings.{$resource}.threshold"] = ['required', 'integer', 'min:0'];
            $rules["settings.{$resource}.withdraw_amount"] = ['required', 'integer', 'min:0'];
            $rules["settings.{$resource}.enabled"] = ['nullable', 'boolean'];
        }

        $validated = $request->validate($rules);

        foreach ($resources as $resource) {
            $resourceSettings = $validated['settings'][$resource];

            $this->autoWithdrawService->updateSetting(
                $nationId,
                $resource,
                (int) $resourceSettings['account_id'],
                (int) $resourceSettings['threshold'],
                (int) $resourceSettings['withdraw_amount'],
                (bool) ($resourceSettings['enabled'] ?? false)
            );
        }

        return redirect()
            ->route('accounts')
            ->with([
                'alert-message' => 'Auto withdraw settings updated.',
                'alert-type' => 'success',
            ]);
    }

    protected function buildDashboardContext(): array|RedirectResponse
    {
        $nationId = Auth::user()->nation_id;
        $accounts = AccountService::getAccountsByNid($nationId);

        if ($accounts->count() === 0) {
            return redirect()->route('accounts.create');
        }

        $activeLoans = Loan::where('nation_id', $nationId)
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->get();

        $ddService = app(DirectDepositService::class);

        $config = MMRConfig::where('nation_id', $nationId)->first();
        $settings = MMRSetting::orderBy('resource')->get()->keyBy('resource');
        $resources = PWHelperService::resources(false);
        $mmrEnabled = SettingService::getMMRAssistantEnabled();

        $lastTaxRecord = DirectDepositLog::where('nation_id', $nationId)
            ->latest('created_at')
            ->first();

        $afterTaxIncome = $lastTaxRecord?->money ?? 0;

        $mmrAccountIds = $accounts->pluck('id');

        if ($config?->account_id) {
            $mmrAccountIds->push((int) $config->account_id);
        }

        $logsQuery = MMRAssistantPurchase::query()
            ->whereIn('account_id', $mmrAccountIds->unique()->values()->all());

        $logs = $logsQuery
            ->with([
                'account' => fn ($query) => $query->withTrashed()->select('id', 'name'),
            ])
            ->orderByDesc('created_at')
            ->simplePaginate(10)
            ->withQueryString();

        $priceService = app(TradePriceService::class);
        $mmrPrices = $priceService->get24hAverageWithSurcharge();

        $incomingMemberTransfers = MemberTransfer::query()
            ->where('status', MemberTransfer::STATUS_PENDING)
            ->whereIn('to_account_id', $accounts->pluck('id'))
            ->with(['fromAccount', 'toAccount', 'fromNation', 'toNation'])
            ->latest('created_at')
            ->get();

        $outgoingMemberTransfers = MemberTransfer::query()
            ->where('status', MemberTransfer::STATUS_PENDING)
            ->where('from_nation_id', $nationId)
            ->with(['fromAccount', 'toAccount', 'fromNation', 'toNation'])
            ->latest('created_at')
            ->get();

        return [
            'accounts' => $accounts,
            'activeLoans' => $activeLoans,
            'enrollment' => DirectDepositEnrollment::with('account')->where('nation_id', $nationId)->first(),
            'bracket' => $ddService->getApplicableBracket(Auth::user()->nation),
            'mmrConfig' => $config,
            'mmrSettings' => $settings,
            'mmrResources' => $resources,
            'mmrEnabled' => $mmrEnabled,
            'mmrLogs' => $logs,
            'mmrAfterTaxIncome' => $afterTaxIncome,
            'mmrPrices' => $mmrPrices,
            'incomingMemberTransfers' => $incomingMemberTransfers,
            'outgoingMemberTransfers' => $outgoingMemberTransfers,
        ];
    }
}
