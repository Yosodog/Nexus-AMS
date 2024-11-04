<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
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
            ->with([
                'alert-message' => 'Account created successfully.',
                "alert-type" => 'success'
            ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete(Request $request)
    {
        $account = AccountService::getAccountById($request->account_id);

        try {
            // Ensure we own this account
            if (Auth::user()->nation_id !== $account->nation_id)
                throw new UserErrorException("You don't own that account");

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
