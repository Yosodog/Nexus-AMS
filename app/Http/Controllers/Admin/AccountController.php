<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositTaxBracket;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\AllianceMembershipService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\WithdrawalLimitService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

class AccountController extends Controller
{
    use AuthorizesRequests;

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
        $recentTransactions = Transaction::with(['fromAccount', 'toAccount', 'nation'])
            ->latest('created_at')
            ->take(50)
            ->get();
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

        return view('admin.accounts.dashboard', [
            'accounts' => $accounts,
            'brackets' => $brackets,
            'enrollments' => $enrollments,
            'ddTaxId' => $ddTaxId,
            'fallbackTaxId' => $fallbackTaxId,
            'recentTransactions' => $recentTransactions,
            'pendingWithdrawals' => $pendingWithdrawals,
            'withdrawalLimits' => $withdrawalLimits,
            'maxDailyWithdrawals' => $maxDailyWithdrawals,
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

        return view('admin.accounts.view', [
            'account' => $accounts,
            'transactions' => $transactions,
            'manualTransactions' => $manualTransactions,
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

        if (! $transaction->isNationWithdrawal()) {
            abort(403, 'This transaction cannot be refunded.');
        }

        if ($transaction->isRefunded()) {
            return back()->with([
                'alert-message' => 'This transaction has already been refunded.',
                'alert-type' => 'error',
            ]);
        }

        $fromAccount = $transaction->fromAccount;
        if (! $fromAccount) {
            return back()->with([
                'alert-message' => 'Original sender account not found.',
                'alert-type' => 'error',
            ]);
        }

        $adjustment = [];
        foreach (PWHelperService::resources() as $resource) {
            $adjustment[$resource] = $transaction->$resource;
        }

        $adjustment['note'] = "Refund for Transaction #{$transaction->id}";

        AccountService::adjustAccountBalance(
            $fromAccount,
            $adjustment,
            auth()->id(),
            RequestFacade::ip()
        );

        $transaction->refunded_at = now();
        $transaction->save();

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
        $this->authorize('edit-accounts');

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

        $data = [];

        foreach (PWHelperService::resources() as $resource) {
            $data[$resource] = $request->input($resource);
        }

        $data['note'] = $request->input('note');

        AccountService::adjustAccountBalance($account, $data, Auth::id(), $request->ip());

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

        SettingService::setDirectDepositId($validated['direct_deposit_tax_id']);
        SettingService::setDirectDepositFallbackId($validated['direct_deposit_fallback_tax_id']);

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

        DirectDepositTaxBracket::create($defaults);

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

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => "$deleted bracket(s) deleted successfully.",
            'alert-type' => 'success',
        ]);
    }
}
