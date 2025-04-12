<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Account;
use App\Models\DepositRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

class DepositService
{

    /**
     * @param int $allianceId
     *
     * @return void
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function processDeposits(int $allianceId)
    {
        // Step 1: Check if there are any pending deposits
        $pendingDeposits = DepositService::getPendingDeposits();
        if ($pendingDeposits->isEmpty()) {
            return; // Exit early if no pending deposits
        }

        // Step 2: Get last scanned bank record ID
        $lastScannedId = SettingService::getLastScannedBankRecordId();

        // Step 3: Fetch all deposits since last scanned ID
        $bankRecords = BankRecordQueryService::getAllianceDeposits(
            $allianceId
        );

        $updatedLastId = $lastScannedId; // This will be set to the highest next value for saving

        foreach ($bankRecords as $record) {
            if ($record->id <= $updatedLastId) {
                continue; // This BankRecord has already been scanned
            }

            $updatedLastId = $record->id;

            // Ensure it's a deposit into the alliance bank
            if ($record->receiver_type != 2) {
                continue;
            }

            // Step 4: Match deposit with a pending request
            $note = trim($record->note);
            $depositRequest = DepositRequest::where('deposit_code', $note)
                ->where('status', 'pending')
                ->first();

            if (!$depositRequest) {
                continue; // No matching request found
            }

            $account = $depositRequest->account;
            if (!$account) {
                self::setDepositCompleted($depositRequest);
                continue; // Shouldn't happen, but just in case
            }

            if ($record->receiver_id != $allianceId) {
                self::setDepositCompleted($depositRequest);
                continue; // Also just in case
            }

            // Step 5: Update the member's account balance using AccountService
            AccountService::updateAccountBalanceFromBankRec($account, $record);

            // Step 6: Mark deposit request as completed
            self::setDepositCompleted($depositRequest);

            // Step 8: Log the transaction using TransactionService
            TransactionService::createTransactionForDeposit($account, $record);
            // TODO send in-game message
        }

        // Now persist the data
        SettingService::setLastScannedBankRecordId($updatedLastId);
    }

    /**
     * @return mixed
     */
    public static function getPendingDeposits()
    {
        return DepositRequest::where('status', 'pending')->get();
    }

    /**
     * @param DepositRequest $request
     *
     * @return void
     */
    public static function setDepositCompleted(DepositRequest $request): void
    {
        $request->status = "completed";
        $request->save();
    }

    /**
     * Creates a deposit request
     *
     * @param Account $account
     *
     * @return DepositRequest
     */
    public static function createRequest(Account $account): DepositRequest
    {
        $depositCode = self::generate_code();

        $deposit = new DepositRequest();
        $deposit->account_id = $account->id;
        $deposit->deposit_code = $depositCode;

        return $deposit;
    }

    /**
     * @return string
     */
    public static function generate_code(): string
    {
        return strtoupper(Str::random(8));
    }

}
