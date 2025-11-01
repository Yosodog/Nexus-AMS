<?php

namespace App\Services;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\Transaction;

class TransactionService
{
    public static function createTransaction(
        array $resources,
        int $nation_id,
        int $fromAccountId,
        string $transactionType,
        ?int $toAccountId = null,
        bool $isPending = true,
        ?string $note = null,
        bool $requiresAdminApproval = false,
        ?string $pendingReason = null
    ): Transaction {
        $transaction = new Transaction;
        $transaction->from_account_id = $fromAccountId;
        $transaction->to_account_id = $toAccountId ?? null;
        $transaction->nation_id = $nation_id;
        $transaction->transaction_type = $transactionType;
        $transaction->note = $note;

        foreach ($resources as $res => $value) {
            $transaction->$res = $value;
        }

        $transaction->is_pending = $isPending;
        $transaction->requires_admin_approval = $requiresAdminApproval;
        $transaction->pending_reason = $pendingReason;

        $transaction->save();

        return $transaction;
    }

    public static function createTransactionForDeposit(
        Account $account,
        BankRecord $record
    ): Transaction {
        $transaction = new Transaction;

        $transaction->from_account_id = null;
        $transaction->to_account_id = $account->id;
        $transaction->nation_id = $record->sender_id;
        $transaction->transaction_type = 'deposit';

        foreach (PWHelperService::resources() as $res) {
            $transaction->$res = $record->$res;
        }

        $transaction->is_pending = false;

        $transaction->save();

        return $transaction;
    }

    public static function hasPendingTransaction(int $nation_id): bool
    {
        $transactions = Transaction::where('nation_id', $nation_id)
            ->where('is_pending', true)
            ->get();

        if ($transactions->count() > 0) {
            return true;
        }

        return false;
    }
}
