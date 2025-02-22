<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accounts;
use App\Services\AccountService;

class AccountController extends Controller
{

    public function dashboard()
    {
        $accounts = Accounts::with("user")
            ->orderBy("nation_id")
            ->has("user")
            ->get();

        return view("admin.accounts.dashboard", [
            "accounts" => $accounts,
        ]);
    }

    /**
     * @param  \App\Models\Accounts  $accounts
     *
     * @return \Closure|\Illuminate\Container\Container|mixed|object|null
     */
    public function view(Accounts $accounts)
    {
        $accounts->load("nation")
            ->load("user");

        $transactions = AccountService::getRelatedTransactions($accounts, 500);

        return view("admin.accounts.view", [
            "account" => $accounts,
            "transactions" => $transactions,
        ]);
    }

}
