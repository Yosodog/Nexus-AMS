<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\MMRAssistantPurchase;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\PWHelperService;
use App\Services\SelfApprovalGuard;
use App\Services\SettingService;
use App\Services\WithdrawalLimitService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private SelfApprovalGuard $selfApprovalGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function dashboard()
    {
        $this->authorize('view-accounts');

        $membership = app(AllianceMembershipService::class);
        $allianceIds = $membership->getAllianceIds();

        $accounts = Account::with('user')
            ->whereHas('nation', function ($q) use ($allianceIds) {
                $q->whereIn('alliance_id', $allianceIds);
            })
            ->orderBy('nation_id')
            ->get();

        $brackets = DirectDepositTaxBracket::orderBy('city_number')->get();
        $enrollments = DirectDepositEnrollment::with('account.user')->get();
        $ddTaxId = SettingService::getDirectDepositId();
        $fallbackTaxId = SettingService::getDirectDepositFallbackId();
        $recentTransactionsSample = Transaction::latest('created_at')
            ->take(50)
            ->get();
        $recentTransactions = Transaction::with(['fromAccount', 'toAccount', 'nation'])
            ->latest('created_at')
            ->paginate(15, ['*'], 'tx_page')
            ->withQueryString()
            ->fragment('recent-transactions');
        $directDepositLogs = DirectDepositLog::with(['nation', 'account'])
            ->latest('created_at')
            ->paginate(10, ['*'], 'dd_page')
            ->withQueryString()
            ->fragment('direct-deposit-logs');
        $mmrPurchases = MMRAssistantPurchase::with('account.nation')
            ->latest('created_at')
            ->paginate(10, ['*'], 'mmr_page')
            ->withQueryString()
            ->fragment('mmr-assistant');
        $pendingWithdrawals = Transaction::query()
            ->with(['fromAccount.nation', 'fromAccount.user', 'nation'])
            ->where('transaction_type', 'withdrawal')
            ->where('requires_admin_approval', true)
            ->whereNull('approved_at')
            ->whereNull('denied_at')
            ->orderBy('created_at')
            ->get();
        $withdrawalLimits = WithdrawalLimitService::limits();
        $maxDailyWithdrawals = SettingService::getWithdrawMaxDailyCount();

        $mainBankSnapshot = app(MainBankService::class)->getCachedSnapshot();
        $offshoreService = app(OffshoreService::class);
        $offshoreSnapshots = $offshoreService->all()
            ->mapWithKeys(fn ($offshore) => [
                $offshore->id => $offshoreService->getCachedSnapshot($offshore),
            ]);

        return view('admin.accounts.dashboard', [
            'accounts' => $accounts,
            'brackets' => $brackets,
            'enrollments' => $enrollments,
            'ddTaxId' => $ddTaxId,
            'fallbackTaxId' => $fallbackTaxId,
            'recentTransactionsSample' => $recentTransactionsSample,
            'recentTransactions' => $recentTransactions,
            'directDepositLogs' => $directDepositLogs,
            'mmrPurchases' => $mmrPurchases,
            'pendingWithdrawals' => $pendingWithdrawals,
            'withdrawalLimits' => $withdrawalLimits,
            'maxDailyWithdrawals' => $maxDailyWithdrawals,
            'mainBankSnapshot' => $mainBankSnapshot,
            'offshoreSnapshots' => $offshoreSnapshots,
        ]);
    }

    /**
     * @return Closure|Container|mixed|object|null
     *
     * @throws AuthorizationException
     */
    public function view(Account $accounts)
    {
        $this->authorize('view-accounts');

        $accounts->load('nation')
            ->load('user');

        $transactions = AccountService::getRelatedTransactions($accounts, 500);
        $manualTransactions = AccountService::getRelatedManualTransactions($accounts, 500);
        $directDepositLogs = DirectDepositLog::with('nation')
            ->where('account_id', $accounts->id)
            ->latest('created_at')
            ->paginate(10, ['*'], 'dd_page')
            ->withQueryString()
            ->fragment('direct-deposit-logs');
        $mmrPurchases = MMRAssistantPurchase::where('account_id', $accounts->id)
            ->latest('created_at')
            ->paginate(10, ['*'], 'mmr_page')
            ->withQueryString()
            ->fragment('mmr-assistant');

        return view('admin.accounts.view', [
            'account' => $accounts,
            'transactions' => $transactions,
            'manualTransactions' => $manualTransactions,
            'directDepositLogs' => $directDepositLogs,
            'mmrPurchases' => $mmrPurchases,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function freeze(Account $account): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $updated = AccountService::setFrozen($account, true);

        if ($updated) {
            $this->auditLogger->success(
                category: 'finance',
                action: 'account_frozen',
                subject: $account,
                context: [
                    'related' => [
                        ['type' => 'Nation', 'id' => (string) $account->nation_id, 'role' => 'owner'],
                    ],
                ],
                message: 'Account frozen.'
            );
        }

        return back()->with([
            'alert-message' => $updated ? 'Account frozen successfully.' : 'Account is already frozen.',
            'alert-type' => $updated ? 'success' : 'info',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function unfreeze(Account $account): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $updated = AccountService::setFrozen($account, false);

        if ($updated) {
            $this->auditLogger->success(
                category: 'finance',
                action: 'account_unfrozen',
                subject: $account,
                context: [
                    'related' => [
                        ['type' => 'Nation', 'id' => (string) $account->nation_id, 'role' => 'owner'],
                    ],
                ],
                message: 'Account unfrozen.'
            );
        }

        return back()->with([
            'alert-message' => $updated ? 'Account unfrozen successfully.' : 'Account was not frozen.',
            'alert-type' => $updated ? 'success' : 'info',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function refundTransaction(Transaction $transaction)
    {
        $this->authorize('manage-accounts');

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: $transaction->nation_id,
            context: 'refund your own withdrawal request'
        );

        if (! $transaction->isNationWithdrawal()) {
            abort(403, 'This transaction cannot be refunded.');
        }

        if ($transaction->denied_at) {
            abort(403, 'Denied withdrawals cannot be refunded.');
        }

        if ($transaction->isRefunded()) {
            return back()->with([
                'alert-message' => 'This transaction has already been refunded.',
                'alert-type' => 'error',
            ]);
        }

        $adjustment = [];
        foreach (PWHelperService::resources() as $resource) {
            $adjustment[$resource] = $transaction->$resource;
        }

        $adjustment['note'] = "Refund for Transaction #{$transaction->id}";

        $result = DB::transaction(function () use ($transaction, $adjustment) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction) {
                return 'missing';
            }

            if ($lockedTransaction->denied_at) {
                return 'denied';
            }

            if ($lockedTransaction->isRefunded()) {
                return 'refunded';
            }

            $fromAccount = $lockedTransaction->fromAccount;
            if (! $fromAccount) {
                return 'account-missing';
            }

            AccountService::adjustAccountBalance(
                $fromAccount,
                $adjustment,
                auth()->id(),
                RequestFacade::ip()
            );

            $lockedTransaction->refunded_at = now();
            $lockedTransaction->save();

            return 'ok';
        });

        if ($result === 'refunded') {
            return back()->with([
                'alert-message' => 'This transaction has already been refunded.',
                'alert-type' => 'error',
            ]);
        }

        if ($result === 'account-missing') {
            return back()->with([
                'alert-message' => 'Original sender account not found.',
                'alert-type' => 'error',
            ]);
        }

        if ($result === 'denied') {
            return back()->with([
                'alert-message' => 'Denied withdrawals cannot be refunded.',
                'alert-type' => 'error',
            ]);
        }

        if ($result === 'ok') {
            $this->auditLogger->recordAfterCommit(
                category: 'finance',
                action: 'withdrawal_refunded',
                outcome: 'success',
                severity: 'info',
                subject: $transaction,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $transaction->from_account_id, 'role' => 'from_account'],
                    ],
                    'data' => [
                        'nation_id' => $transaction->nation_id,
                        'resources' => collect(PWHelperService::resources())
                            ->mapWithKeys(fn ($resource) => [$resource => $transaction->{$resource}])
                            ->all(),
                    ],
                ],
                message: 'Withdrawal refunded.'
            );
        }

        return back()->with([
            'alert-message' => 'Refund successful.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param  Account  $accounts
     * @return mixed
     *
     * @throws AuthorizationException
     */
    public function adjustBalance(Request $request)
    {
        $this->authorize('manage-accounts');

        $request->validate([
            'money' => 'nullable|numeric',
            'coal' => 'nullable|numeric',
            'oil' => 'nullable|numeric',
            'uranium' => 'nullable|numeric',
            'lead' => 'nullable|numeric',
            'iron' => 'nullable|numeric',
            'bauxite' => 'nullable|numeric',
            'gasoline' => 'nullable|numeric',
            'munitions' => 'nullable|numeric',
            'steel' => 'nullable|numeric',
            'aluminum' => 'nullable|numeric',
            'food' => 'nullable|numeric',
            'note' => 'required|string|max:255|required',
        ]);

        $account = AccountService::getAccountById($request->input('accountId'));

        if ($account->nation_id === Auth::user()->nation_id) {
            abort(403, 'You cannot edit your own account');
        }

        $data = [];

        foreach (PWHelperService::resources() as $resource) {
            $data[$resource] = $request->input($resource);
        }

        $data['note'] = $request->input('note');

        AccountService::adjustAccountBalance($account, $data, Auth::id(), $request->ip());

        $this->auditLogger->recordAfterCommit(
            category: 'finance',
            action: 'account_adjusted',
            outcome: 'success',
            severity: 'warning',
            subject: $account,
            context: [
                'related' => [
                    ['type' => 'Nation', 'id' => (string) $account->nation_id, 'role' => 'owner'],
                ],
                'data' => [
                    'adjustment' => $data,
                ],
            ],
            message: 'Account balance adjusted.'
        );

        return redirect()
            ->back()
            ->with([
                'alert-message' => 'Account modified successfully.',
                'alert-type' => 'success',
            ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function saveDirectDepositSettings(Request $request)
    {
        $this->authorize('manage-dd');

        $validated = $request->validate([
            'direct_deposit_tax_id' => 'required|integer|min:1',
            'direct_deposit_fallback_tax_id' => 'required|integer|min:1',
        ]);

        $previous = [
            'direct_deposit_tax_id' => SettingService::getDirectDepositId(),
            'direct_deposit_fallback_tax_id' => SettingService::getDirectDepositFallbackId(),
        ];

        SettingService::setDirectDepositId($validated['direct_deposit_tax_id']);
        SettingService::setDirectDepositFallbackId($validated['direct_deposit_fallback_tax_id']);

        $this->auditLogger->success(
            category: 'settings',
            action: 'direct_deposit_settings_updated',
            context: [
                'changes' => [
                    'direct_deposit_tax_id' => [
                        'from' => $previous['direct_deposit_tax_id'],
                        'to' => (int) $validated['direct_deposit_tax_id'],
                    ],
                    'direct_deposit_fallback_tax_id' => [
                        'from' => $previous['direct_deposit_fallback_tax_id'],
                        'to' => (int) $validated['direct_deposit_fallback_tax_id'],
                    ],
                ],
            ],
            message: 'Direct deposit settings updated.'
        );

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Direct Deposit settings updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function createDirectDepositBracket(Request $request)
    {
        $this->authorize('manage-dd');

        $request->validate([
            'city_number' => 'required|integer|min:0|unique:direct_deposit_tax_brackets,city_number',
        ]);

        $defaults = array_fill_keys(PWHelperService::resources(), 10);
        $defaults['city_number'] = $request->input('city_number');

        $bracket = DirectDepositTaxBracket::create($defaults);

        $this->auditLogger->success(
            category: 'finance',
            action: 'direct_deposit_bracket_created',
            subject: $bracket,
            context: [
                'data' => $defaults,
            ],
            message: 'Direct deposit tax bracket created.'
        );

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Tax bracket created with default 10% rates.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function updateDirectDepositBrackets(Request $request)
    {
        $this->authorize('manage-dd');

        $request->validate([
            'selected' => 'required|array',
            'selected.*' => 'exists:direct_deposit_tax_brackets,id',
            'rates' => 'required|array',
        ]);

        $rates = collect(PWHelperService::resources())
            ->mapWithKeys(function ($resource) use ($request) {
                $value = $request->input("rates.$resource");

                return $value !== null ? [$resource => (float) $value] : [];
            })->toArray();

        if (empty($rates)) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'No rates provided to update.',
                'alert-type' => 'warning',
            ]);
        }

        DirectDepositTaxBracket::whereIn('id', $request->input('selected'))
            ->update($rates);

        $this->auditLogger->success(
            category: 'finance',
            action: 'direct_deposit_brackets_updated',
            context: [
                'data' => [
                    'selected' => $request->input('selected'),
                    'rates' => $rates,
                ],
            ],
            message: 'Direct deposit tax brackets updated.'
        );

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Selected brackets updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function deleteDirectDepositBrackets(Request $request)
    {
        $this->authorize('manage-dd');

        $request->validate([
            'selected' => 'required|array',
            'selected.*' => 'exists:direct_deposit_tax_brackets,id',
        ]);

        $deleted = DirectDepositTaxBracket::whereIn('id', $request->input('selected'))
            ->where('city_number', '!=', 0)
            ->delete();

        if ($deleted > 0) {
            $this->auditLogger->success(
                category: 'finance',
                action: 'direct_deposit_brackets_deleted',
                context: [
                    'data' => [
                        'selected' => $request->input('selected'),
                        'deleted' => $deleted,
                    ],
                ],
                message: 'Direct deposit tax brackets deleted.'
            );
        }

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => "$deleted bracket(s) deleted successfully.",
            'alert-type' => 'success',
        ]);
    }
}
