<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\UserErrorException;
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

                if ($depositRequest->expires_at?->isPast()) {
                    $depositRequest->status = 'expired';
                    $depositRequest->pending_key = null;
                    $depositRequest->save();

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
        self::expirePendingRequests();

        return DepositRequest::where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get();
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
        if ($account->frozen) {
            throw new UserErrorException('This account is frozen. Deposits are disabled.');
        }

        self::expirePendingRequests($account->id);

        // Reuse an unexpired pending request so members see the original code.
        $existing = DepositRequest::where('account_id', $account->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $deposit = new DepositRequest;
                $deposit->account_id = $account->id;
                $deposit->deposit_code = self::generate_code();
                $deposit->status = 'pending';
                $deposit->pending_key = 1;
                $deposit->expires_at = now()->addMinutes(60);
                $deposit->save();

                return $deposit;
            } catch (QueryException $exception) {
                if (! self::isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                // A concurrent request for the same account wins cleanly.
                $existing = DepositRequest::where('account_id', $account->id)
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                // Otherwise the randomly generated code collided; generate another.
            }
        }

        throw new UserErrorException('Unable to create a unique deposit code. Please try again.');
    }

    public static function generate_code(): string
    {
        return strtoupper(Str::random(8));
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }

    private static function expirePendingRequests(?int $accountId = null): void
    {
        DepositRequest::query()
            ->where('status', 'pending')
            ->whereNull('expires_at')
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->eachById(function (DepositRequest $request): void {
                $request->expires_at = $request->created_at->copy()->addMinutes(60);
                $request->save();
            });

        DepositRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->update([
                'status' => 'expired',
                'pending_key' => null,
            ]);
    }
}
