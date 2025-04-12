<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\UserErrorException;
use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DepositRequest;
use App\Models\ManualTransactions;
use App\Models\Transactions;
use App\Models\User;
use App\Notifications\DepositCreated;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * @param int|User $user
     *
     * @return mixed
     */
    public static function getAccountsByUser(int|User $user)
    {
        // Get the user so we can get their nation ID
        if ($user instanceof User) {
            return self::getAccountsByNid($user->nation_id);
        }

        $user = User::findOrFail($user);

        return self::getAccountsByNid($user->nation_id);
    }

    /**
     * @param int $nation_id
     *
     * @return mixed
     */
    public static function getAccountsByNid(int $nation_id)
    {
        return Account::where("nation_id", $nation_id)
            ->get();
    }

    /**
     * @param int $nation_id
     * @param string $name
     *
     * @return Account
     */
    public static function createAccount(int $nation_id, string $name): Account
    {
        $account = new Account();
        $account->name = $name;
        $account->nation_id = $nation_id;
        $account->save();

        return $account;
    }

    /**
     * @param Account $account
     *
     * @return void
     * @throws UserErrorException
     */
    /**
     * Deletes an account after performing necessary checks.
     *
     * @param Account $account
     *
     * @return void
     * @throws UserErrorException
     */
    public static function deleteAccount(Account $account): void
    {
        // Check if the account has pending city grants
        if ($account->cityGrants()->where('status', 'pending')->exists()) {
            throw new UserErrorException("The account has pending city grants.");
        }

        // Check if the account has pending or active loans
        if ($account->loans()->whereIn('status', ['pending', 'approved'])->exists()) {
            throw new UserErrorException("The account has pending or active loans.");
        }

        // Check to ensure the account is empty
        if (!$account->isEmpty()) {
            throw new UserErrorException("The account is not empty.");
        }

        // Proceed with deletion
        $account->delete();
    }

    /**
     * Transfer resources from one account to another.
     *
     * @param int $fromAccountId
     * @param int $toAccountId
     * @param array $resources
     *
     * @return void
     * @throws UserErrorException
     */
    public static function transferToAccount(
        int $fromAccountId,
        int $toAccountId,
        array $resources
    ): void {
        // Start transaction to ensure data integrity
        DB::beginTransaction();

        try {
            $fromAccount = self::getAccountById($fromAccountId);
            $toAccount = self::getAccountById($toAccountId);

            // Validate the transfer. If there are any errors, it'll throw an exception that is handled below
            self::validateTransfer(
                $resources,
                Auth::user()->nation_id,
                $fromAccount,
                $toAccount
            );

            // Perform the transfer
            foreach (PWHelperService::resources() as $res) {
                $fromAccount->$res -= $resources[$res];
                $toAccount->$res += $resources[$res];
            }

            // Save changes
            $fromAccount->save();
            $toAccount->save();

            TransactionService::createTransaction(
                $resources,
                Auth::user()->nation_id,
                $fromAccountId,
                "transfer",
                $toAccountId,
                false
            );

            DB::commit();
        } catch (Exception $e) {
            // Rollback in case of error
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @param int $id
     *
     * @return Account
     */
    public static function getAccountById(int $id): Account
    {
        return Account::where("id", $id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * One function to rule them all. Validates the transfer whether it is
     * going to an account or a nation If it is going to an account, make sure
     * you set the toAccount variable. If it is going to a nation, obviously do
     * not set the toAccount variable.
     *
     * @param array $resources
     * @param int $nation_id
     * @param Account $fromAccount
     * @param Account|null $toAccount
     *
     * @return void
     * @throws UserErrorException
     */
    protected static function validateTransfer(
        array $resources,
        int $nation_id,
        Account $fromAccount,
        Account|null $toAccount = null
    ): void {
        // Verify that we own the from account
        if ($fromAccount->nation_id != $nation_id) {
            throw new UserErrorException("You do not own the from account");
        }

        // Verify that we don't have a pending transaction
        if (TransactionService::hasPendingTransaction($nation_id)) {
            throw new UserErrorException(
                "You are only allowed one pending transaction at a time. Try again later"
            );
        }

        // If the toAccount is set, then verify that we own it too. It will not be set if we are transferring to a nation
        if (!is_null($toAccount)) {
            if ($toAccount->nation_id != $nation_id) {
                throw new UserErrorException("You do not own the to account");
            }
        }

        $thereIsSomething = false;
        foreach (PWHelperService::resources() as $res) {
            // Verify that the 'from' account has enough resources
            if ($fromAccount->$res < $resources[$res]) {
                throw new UserErrorException(
                    "Insufficient {$res} in the source account."
                );
            }

            // Verify that nothing is a negative resource
            if ($resources[$res] < 0) {
                throw new UserErrorException(
                    "$res is set to a negative number"
                );
            }

            if ($resources[$res] > 0) {
                $thereIsSomething =
                    true; // We don't want to do a transaction if there is nothing
            }
        }

        if (!$thereIsSomething) {
            throw new UserErrorException("You can't transfer nothing.");
        }
    }

    /**
     * @param int $fromAccountId
     * @param int $nation_id
     * @param array $resources
     *
     * @return void
     * @throws PWQueryFailedException
     * @throws UserErrorException
     * @throws ConnectionException
     */
    public static function transferToNation(
        int $fromAccountId,
        int $nation_id,
        array $resources
    ): void {
        // Start transaction to ensure data integrity
        DB::beginTransaction();

        try {
            $fromAccount = self::getAccountById($fromAccountId);

            // Validate the transfer. If there are any errors, it'll throw an exception that is handled below
            self::validateTransfer(
                $resources,
                Auth::user()->nation_id,
                $fromAccount
            );

            $bank = new BankService();
            $bank->receiver = $nation_id;
            $bank->note = "Withdraw from " . $fromAccount->name;

            // Perform the transfer
            foreach (PWHelperService::resources() as $res) {
                $fromAccount->$res -= $resources[$res];
                $bank->$res = $resources[$res];
            }

            // Save changes
            $fromAccount->save();

            $transaction = TransactionService::createTransaction(
                $resources,
                Auth::user()->nation_id,
                $fromAccountId,
                "withdrawal",
            );

            DB::commit(); // Commit before spawning the job. I'd rather it fail.

            // Send withdraw
            $bank->send($transaction);
        } catch (Exception $e) {
            // Rollback in case of error
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @param Account $account
     *
     * @return DepositRequest
     */
    public static function createDepositRequest(Account $account): DepositRequest
    {
        $deposit = DepositService::createRequest($account);

        // Send notification to the user that owns this account
        $user = User::getByNationId($account->nation_id);

        $user->notify(new DepositCreated($user->nation_id, $deposit));

        return $deposit;
    }

    /**
     * @param Account $account
     * @param BankRecord $bankRecord
     *
     * @return void
     */
    public static function updateAccountBalanceFromBankRec(
        Account $account,
        BankRecord $bankRecord
    ) {
        foreach (PWHelperService::resources() as $res) {
            $account->$res += $bankRecord->$res;
        }

        $account->save();
    }

    /**
     * @param Account $account
     *
     * @return mixed
     */
    public static function getRelatedTransactions(Account $account, int $perPage = 50)
    {
        return Transactions::where("to_account_id", $account->id)
            ->orWhere("from_account_id", $account->id)
            ->with("nation")
            ->orderBy("created_at", "DESC")
            ->paginate($perPage);
    }

    /**
     * @param Account $account
     * @param int $perPage
     *
     * @return mixed
     */
    public static function getRelatedManualTransactions(Account $account, int $perPage)
    {
        return ManualTransactions::where("account_id", $account->id)
            ->orderBy("created_at", "DESC")
            ->paginate($perPage);
    }

    /**
     * @param Account $account
     * @param array $adjustment
     * @param int|null $adminId
     * @param string|null $ipAddress
     *
     * @return ManualTransactions
     */
    public static function adjustAccountBalance(
        Account $account,
        array $adjustment,
        ?int $adminId,
        ?string $ipAddress
    ): ManualTransactions {
        // Apply changes to account balance
        foreach (PWHelperService::resources() as $resource) {
            if (isset($adjustment[$resource])) {
                $account->{$resource} += $adjustment[$resource];
            }
        }

        $account->save();

        // Log the manual transaction
        return ManualTransactions::create([
            'account_id' => $account->id,
            'admin_id' => $adminId,
            'money' => $adjustment['money'] ?? 0,
            'coal' => $adjustment['coal'] ?? 0,
            'oil' => $adjustment['oil'] ?? 0,
            'uranium' => $adjustment['uranium'] ?? 0,
            'lead' => $adjustment['lead'] ?? 0,
            'iron' => $adjustment['iron'] ?? 0,
            'bauxite' => $adjustment['bauxite'] ?? 0,
            'gasoline' => $adjustment['gasoline'] ?? 0,
            'munitions' => $adjustment['munitions'] ?? 0,
            'steel' => $adjustment['steel'] ?? 0,
            'aluminum' => $adjustment['aluminum'] ?? 0,
            'food' => $adjustment['food'] ?? 0,
            'note' => $adjustment['note'],
            'ip_address' => $ipAddress,
        ]);
    }

}
