<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Account;
use App\Models\DepositRequest;
use App\Notifications\DepositCompletedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class DepositService
{
    /**
     * @return void
     *
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
        $bankRecords = BankRecordQueryService::getAllianceDeposits($allianceId, options: [
            'minId' => $lastScannedId + 1,
            'orderByColumn' => 'ID',
            'orderByDirection' => 'ASC',
        ]);

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
            $shouldSendConfirmation = false;
            $depositedAccountName = null;
            DB::transaction(function () use ($note, $record, $allianceId, &$shouldSendConfirmation, &$depositedAccountName) {
                $depositRequest = DepositRequest::where('deposit_code', $note)
                    ->lockForUpdate()
                    ->first();

                if (! $depositRequest || $depositRequest->status !== 'pending') {
                    return;
                }

                $account = Account::whereKey($depositRequest->account_id)->lockForUpdate()->first();
                if (! $account) {
                    self::setDepositCompleted($depositRequest);

                    return;
                }

                if ($record->receiver_id != $allianceId) {
                    self::setDepositCompleted($depositRequest);

                    return;
                }

                // Step 5: Update the member's account balance using AccountService
                AccountService::updateAccountBalanceFromBankRec($account, $record);

                // Step 6: Mark deposit request as completed
                $depositRequest->fulfilled_bank_record_id = $record->id;
                self::setDepositCompleted($depositRequest);

                // Step 8: Log the transaction using TransactionService
                TransactionService::createTransactionForDeposit($account, $record);

                $shouldSendConfirmation = true;
                $depositedAccountName = $account->name;
            });

            if ($shouldSendConfirmation) {
                $resourcePayload = [];
                foreach (PWHelperService::resources() as $resource) {
                    $resourcePayload[$resource] = (float) $record->{$resource};
                }

                Notification::route('pnw', 'pnw')
                    ->notify(new DepositCompletedNotification(
                        nationId: (int) $record->sender_id,
                        accountName: $depositedAccountName,
                        resources: $resourcePayload
                    ));
            }
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

    public static function setDepositCompleted(DepositRequest $request): void
    {
        $request->status = 'completed';
        $request->pending_key = null;
        $request->save();
    }

    /**
     * Creates a deposit request
     */
    public static function createRequest(Account $account): DepositRequest
    {
        // Reuse existing pending request so members see the original code
        $existing = DepositRequest::where('account_id', $account->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $deposit = new DepositRequest;
            $deposit->account_id = $account->id;
            $deposit->deposit_code = self::generate_code();
            $deposit->status = 'pending';
            $deposit->pending_key = 1;
            $deposit->save();

            return $deposit;
        } catch (QueryException $exception) {
            if (! self::isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return DepositRequest::where('account_id', $account->id)
                ->where('status', 'pending')
                ->latest()
                ->firstOrFail();
        }
    }

    public static function generate_code(): string
    {
        return strtoupper(Str::random(8));
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
