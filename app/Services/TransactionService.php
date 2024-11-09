<?php

namespace App\Services;

use App\Models\Transactions;

class TransactionService
{

    /**
     * @param  array  $resources
     * @param  int  $nation_id
     * @param  int  $fromAccountId
     * @param  string  $transactionType
     * @param  int|null  $toAccountId
     * @param  bool  $isPending
     *
     * @return Transactions
     */
    public static function createTransaction(
        array $resources,
        int $nation_id,
        int $fromAccountId,
        string $transactionType,
        int|null $toAccountId = null,
        bool $isPending = true
    ): Transactions {
        $transaction = new Transactions();
        $transaction->from_account_id = $fromAccountId;
        $transaction->to_account_id = $toAccountId ?? null;
        $transaction->nation_id = $nation_id;
        $transaction->transaction_type = $transactionType;

        foreach ($resources as $res => $value) {
            $transaction->$res = $value;
        }

        $transaction->is_pending = $isPending;

        $transaction->save();

        return $transaction;
    }

    /**
     * @param  int  $nation_id
     *
     * @return bool
     */
    public static function hasPendingTransaction(int $nation_id): bool
    {
        $transactions = Transactions::where('nation_id', $nation_id)
            ->where('is_pending', true)
            ->get();

        if ($transactions->count() > 0) {
            return true;
        }

        return false;
    }

}
