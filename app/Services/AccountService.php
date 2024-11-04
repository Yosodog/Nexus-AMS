<?php

namespace App\Services;

use App\Exceptions\UserErrorException;
use App\Models\Accounts;

class AccountService
{
    public static array $resources = [
        "money", "coal", "oil", "uranium", "iron", "bauxite", "lead",
        "gasoline", "munitions", "steel", "aluminum", "food"
    ];

    /**
     * @param int $nID
     * @return mixed
     */
    public static function getAccountsByNid(int $nation_id)
    {
        return Accounts::where("nation_id", $nation_id)
            ->get();
    }

    /**
     * @param int $nation_id
     * @param string $name
     * @return Accounts
     */
    public static function createAccount(int $nation_id, string $name): Accounts
    {
        $account = new Accounts();
        $account->name = $name;
        $account->nation_id = $nation_id;
        $account->save();

        return $account;
    }

    /**
     * @param Accounts $account
     * @return void
     * @throws UserErrorException
     */
    public static function deleteAccount(Accounts $account): void
    {
        // Check to ensure the account is empty
        if (!$account->isEmpty())
            throw new UserErrorException("The account is not empty.");

        $account->delete();
    }

    /**
     * @param int $id
     * @return Accounts
     */
    public static function getAccountById(int $id): Accounts
    {
        return Accounts::where("id", $id)
            ->firstOrFail();
    }
}
