<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Accounts;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    protected AccountService $accountService;

    /**
     * Get the authenticated user's accounts.
     */
    public function getUserAccounts()
    {
        $user = Auth::user();
        $accounts = AccountService::getAccountsByUser($user->id); // Auth::user() doesn't return the user model, so fuck it

        return response()->json($accounts);
    }

    /**
     * Create a deposit request for an account.
     */
    public function createDepositRequest(Accounts $account)
    {
        $user = Auth::user();

        // Ensure the user owns this account
        if ($account->nation_id !== $user->nation_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $depositCode = AccountService::createDepositRequest($account);

        return response()->json([
            'message' => 'Deposit request created successfully.',
            'deposit_code' => $depositCode,
        ]);
    }
}
