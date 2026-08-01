<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckRapidTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:check-rapid-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reports recent transfers/withdrawals that require rapid-transaction review.';

    protected int $scanWindowMinutes = 2;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $cutoff = now()->subMinutes($this->scanWindowMinutes);

        $transactions = Transaction::query()
            ->where('created_at', '>=', $cutoff)
            ->whereIn('transaction_type', ['withdrawal', 'transfer'])
            ->whereNotNull('nation_id')
            ->get(['id', 'nation_id', 'created_at', 'from_account_id', 'to_account_id', 'transaction_type']);

        if ($transactions->isEmpty()) {
            return;
        }

        $violations = $transactions
            ->groupBy('nation_id')
            ->map(function ($nationTransactions) {
                $bySecond = $nationTransactions->groupBy(fn ($transaction) => $transaction->created_at->format('Y-m-d H:i:s'));

                return $bySecond->filter(fn ($group) => $group->count() > 1);
            })
            ->filter(fn ($groupedSeconds) => $groupedSeconds->isNotEmpty());

        if ($violations->isEmpty()) {
            return;
        }

        foreach ($violations as $nationId => $groupedSeconds) {
            Log::warning('Rapid transactions detected; manual review required.', [
                'nation_id' => $nationId,
                'detected_seconds' => $groupedSeconds->keys()->values()->all(),
                'cutoff' => $cutoff->toDateTimeString(),
                'transactions' => $groupedSeconds
                    ->flatten(1)
                    ->map(fn ($transaction) => [
                        'id' => $transaction->id,
                        'type' => $transaction->transaction_type,
                        'created_at' => $transaction->created_at->toDateTimeString(),
                        'from_account_id' => $transaction->from_account_id,
                        'to_account_id' => $transaction->to_account_id,
                    ])
                    ->values()
                    ->all(),
            ]);
        }
    }
}
