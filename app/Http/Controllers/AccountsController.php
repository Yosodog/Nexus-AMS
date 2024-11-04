<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Models\Accounts;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'from' => 'required|integer|exists:accounts,id',
            'to' => 'required|integer|exists:accounts,id',
        ]);

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
            if ($request->input("to") == "nation") {
                // Transfer to nation logic
            } else {
                AccountService::transferToAccount($request->input("from"), $request->input("to"), $transfer);
            }

            return redirect()->back()->with([
                'alert-message' => 'Transfer successful!',
                "alert-type" => 'success'
            ]);
        } catch (UserErrorException $e) {
            return redirect()->back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => "error"
            ]);
        } catch (\Exception $e) {
            Log::error("Error when transferring. ".$e->getMessage());

            return redirect()->back()->withErrors("There was an error with your transfer. Please try again");
        }
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
