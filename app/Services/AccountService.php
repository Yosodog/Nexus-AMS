<?php

namespace App\Services;

use App\Exceptions\UserErrorException;
use App\Models\Accounts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    /**
     * Transfer resources from one account to another.
     *
     * @param int $fromAccountId
     * @param int $toAccountId
     * @param array $resources
     * @return void
     * @throws UserErrorException
     */
    public static function transferToAccount(int $fromAccountId, int $toAccountId, array $resources): void
    {
        // Start transaction to ensure data integrity
        DB::beginTransaction();

        try {
            $fromAccount = self::getAccountById($fromAccountId);
            $toAccount = self::getAccountById($toAccountId);

            // Validate the transfer. If there are any errors, it'll throw an exception that is handled below
            self::validateTransfer($resources, Auth::user()->nation_id, $fromAccount, $toAccount);

            // Perform the transfer
            foreach (self::$resources as $res) {
                $fromAccount->$res -= $resources[$res];
                $toAccount->$res += $resources[$res];
            }

            // Save changes
            $fromAccount->save();
            $toAccount->save();

            DB::commit();
        } catch (\Exception $e) {
            // Rollback in case of error
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * One function to rule them all. Validates the transfer whether it is going to an account or a nation
     * If it is going to an account, make sure you set the toAccount variable. If it is going to a nation,
     * obviously do not set the toAccount variable.
     *
     * @param array $resources
     * @param int $nation_id
     * @param Accounts $fromAccount
     * @param Accounts|null $toAccount
     * @return void
     * @throws UserErrorException
     */
    protected static function validateTransfer(array $resources, int $nation_id, Accounts $fromAccount, Accounts|null $toAccount = null): void
    {
        // Verify that we own the from account
        if ($fromAccount->nation_id != $nation_id)
            throw new UserErrorException("You do not own the from account");

        // If the toAccount is set, then verify that we own it too. It will not be set if we are transferring to a nation
        if (!is_null($toAccount)) {
            if ($toAccount->nation_id != $nation_id)
                throw new UserErrorException("You do not own the to account");
        }

        $thereIsSomething = false;
        foreach (self::$resources as $res) {
            // Verify that the 'from' account has enough resources
            if ($fromAccount->$res < $resources[$res]) {
                throw new UserErrorException("Insufficient {$res} in the source account.");
            }

            // Verify that the 'from' account has enough resources
            if ($resources[$res] < 0) {
                throw new UserErrorException("$res is set to a negative number");
            }

            if ($resources[$res] > 0) {
                $thereIsSomething = true; // We don't want to do a transaction if there is nothing
            }
        }

        if (!$thereIsSomething) {
            throw new UserErrorException("You can't transfer nothing.");
        }
    }
}
