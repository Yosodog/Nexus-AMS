<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accounts;

class AccountController extends Controller
{
    public function dashboard()
    {
        $accounts = Accounts::with("user")
            ->orderBy("nation_id")
            ->has("user")
            ->get();

        return view("admin.accounts.dashboard", [
            "accounts" => $accounts
        ]);
    }
}
