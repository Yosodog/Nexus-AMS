<?php

namespace App\Http\Controllers;

use App\Models\Accounts;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountsController extends Controller
{
    public function index()
    {
        $accounts = AccountService::getAccountsByNid(Auth::user()->nation_id);

        if ($accounts->count() === 0)
            return redirect()->route("accounts.create");

        return view("accounts.index", [
            "accounts" => $accounts
        ]);
    }

    public function transfer()
    {

    }

    public function viewAccount(Accounts $account)
    {

    }

    public function createView()
    {
        return view("accounts.create");
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        AccountService::createAccount(Auth::user()->nation_id, $request->input("name"));

        return redirect()
            ->route('accounts')
            ->with('success', 'Account created successfully.');
    }
}
