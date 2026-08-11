<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DepositImportCheckpoint;
use App\Models\DepositRequest;
use App\Notifications\DepositCompletedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DepositService
{
    /**
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function processDeposits(int $allianceId, ?QueryService $client = null): int
    {
        $pendingDeposits = self::getPendingDeposits();
        $lastScannedId = DepositImportCheckpoint::lastScannedId($allianceId);

        if ($pendingDeposits->isEmpty()) {
            return $lastScannedId;
        }

        DepositImportCheckpoint::recordAttempt($allianceId);
        $updatedLastId = $lastScannedId;
        $importedDeposit = false;

        try {
            $bankRecords = app(BankRecordQueryService::class)->getAllianceDeposits(
                $allianceId,
                options: [
                    'minId' => $lastScannedId + 1,
                    'orderByColumn' => 'ID',
                    'orderByDirection' => 'ASC',
                ],
                client: $client,
            );

            $records = collect(iterator_to_array($bankRecords))
                ->sortBy(fn ($record): int => $record->id)
                ->values();

            foreach ($records as $record) {
                if ($record->id <= $updatedLastId) {
                    continue;
                }

                if ($record->receiver_type !== 2 || $record->receiver_id !== $allianceId) {
                    Log::error('Deposit import received a bank record outside the requested alliance.', [
                        'alliance_id' => $allianceId,
                        'bank_record_id' => $record->id,
                        'receiver_id' => $record->receiver_id,
                        'receiver_type' => $record->receiver_type,
                    ]);

                    throw new RuntimeException('Received a bank record outside the requested alliance.');
                }

                $note = trim((string) $record->note);

                /** @var array{nation_id: int, account_name: string}|null $confirmation */
                $confirmation = DB::transaction(function () use ($note, $record): ?array {
                    $depositRequest = DepositRequest::query()
                        ->where('deposit_code', $note)
                        ->lockForUpdate()
                        ->first();

                    if (! $depositRequest || $depositRequest->status !== 'pending') {
                        return null;
                    }

                    if ($depositRequest->expires_at?->isPast()) {
                        $depositRequest->status = 'expired';
                        $depositRequest->pending_key = null;
                        $depositRequest->save();

                        return null;
                    }

                    $account = Account::query()
                        ->whereKey($depositRequest->account_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $account) {
                        self::setDepositCompleted($depositRequest);

                        return null;
                    }

                    AccountService::updateAccountBalanceFromBankRec($account, $record);

                    $depositRequest->fulfilled_bank_record_id = $record->id;
                    self::setDepositCompleted($depositRequest);

                    TransactionService::createTransactionForDeposit($account, $record);

                    return [
                        'nation_id' => (int) $account->nation_id,
                        'account_name' => (string) $account->name,
                    ];
                });

                if ($confirmation !== null) {
                    $resourcePayload = [];
                    foreach (PWHelperService::resources() as $resource) {
                        $resourcePayload[$resource] = (float) $record->{$resource};
                    }

                    Notification::route('pnw', 'pnw')
                        ->notify(new DepositCompletedNotification(
                            nationId: $confirmation['nation_id'],
                            accountName: $confirmation['account_name'],
                            resources: $resourcePayload,
                        ));

                    $importedDeposit = true;
                }

                DepositImportCheckpoint::advance($allianceId, $record->id);
                $updatedLastId = $record->id;
            }

            if ($importedDeposit) {
                DepositImportCheckpoint::recordImport($allianceId);
            }

            DepositImportCheckpoint::recordSuccess($allianceId);
        } catch (Throwable $exception) {
            DepositImportCheckpoint::recordFailure($allianceId, $exception->getMessage());

            throw $exception;
        }

        return $updatedLastId;
    }

    /**
     * @return Collection<int, DepositRequest>
     */
    public static function getPendingDeposits(): Collection
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
