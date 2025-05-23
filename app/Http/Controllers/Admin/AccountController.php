<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositTaxBracket;
use App\Services\AccountService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    use AuthorizesRequests;

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function dashboard()
    {
        $this->authorize('view-accounts');

        $accounts = Account::with('user')->orderBy('nation_id')->has('user')->get();
        $brackets = DirectDepositTaxBracket::orderBy('city_number')->get();
        $enrollments = DirectDepositEnrollment::with('account.user')->get();
        $ddTaxId = SettingService::getDirectDepositId();
        $fallbackTaxId = SettingService::getDirectDepositFallbackId();

        return view('admin.accounts.dashboard', [
            'accounts' => $accounts,
            'brackets' => $brackets,
            'enrollments' => $enrollments,
            'ddTaxId' => $ddTaxId,
            'fallbackTaxId' => $fallbackTaxId,
        ]);
    }

    /**
     * @param Account $accounts
     *
     * @return Closure|Container|mixed|object|null
     * @throws AuthorizationException
     */
    public function view(Account $accounts)
    {
        $this->authorize('view-accounts');

        $accounts->load("nation")
            ->load("user");

        $transactions = AccountService::getRelatedTransactions($accounts, 500);
        $manualTransactions = AccountService::getRelatedManualTransactions($accounts, 500);

        return view("admin.accounts.view", [
            "account" => $accounts,
            "transactions" => $transactions,
            "manualTransactions" => $manualTransactions
        ]);
    }

    /**
     * @param Account $accounts
     * @param Request $request
     *
     * @return mixed
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

        $account = AccountService::getAccountById($request->input("accountId"));

        $data = [];

        foreach (PWHelperService::resources() as $resource) {
            $data[$resource] = $request->input($resource);
        }

        $data["note"] = $request->input("note");

        AccountService::adjustAccountBalance($account, $data, Auth::id(), $request->ip());

        return redirect()
            ->back()
            ->with([
                'alert-message' => 'Account modified successfully.',
                "alert-type" => 'success',
            ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
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
            'alert-type' => 'success'
        ]);
    }

}
