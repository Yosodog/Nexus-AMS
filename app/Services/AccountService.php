<?php

namespace App\Services;

use App\Models\Accounts;

class AccountService
{
    /**
     * @param int $nID
     * @return mixed
     */
    public static function getAccountsByNid(int $nation_id)
    {
        return Accounts::where("nation_id", $nation_id)
            ->get();
    }

    public static function createAccount(int $nation_id, string $name): Accounts
    {
        $account = new Accounts();
        $account->name = $name;
        $account->nation_id = $nation_id;
        $account->save();

        return $account;
    }
}
