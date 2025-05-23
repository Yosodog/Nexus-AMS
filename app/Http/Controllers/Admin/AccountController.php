<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\PWHelperService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $accounts = Account::with("user")
            ->orderBy("nation_id")
            ->has("user")
            ->get();

        return view("admin.accounts.dashboard", [
            "accounts" => $accounts,
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

}
