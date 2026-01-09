<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
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
    protected $description = 'Scans recent transfers/withdrawals for multiple transactions in the same second.';

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
            $user = User::query()->where('nation_id', $nationId)->first();

            if (! $user) {
                Log::warning('Rapid transactions detected for missing user record.', [
                    'nation_id' => $nationId,
                    'detected_seconds' => $groupedSeconds->keys()->values()->all(),
                    'cutoff' => $cutoff->toDateTimeString(),
                ]);

                continue;
            }

            $user->loadMissing('accounts');

            $frozenCount = 0;
            foreach ($user->accounts as $account) {
                if (AccountService::setFrozen($account, true)) {
                    $frozenCount++;
                }
            }

            $wasDisabled = $user->disabled;
            if (! $user->disabled) {
                $user->disabled = true;
                $user->save();
            }

            Log::warning('Rapid transactions detected; user disabled and accounts frozen.', [
                'user_id' => $user->id,
                'nation_id' => $nationId,
                'disabled_previously' => $wasDisabled,
                'frozen_accounts' => $frozenCount,
                'detected_seconds' => $groupedSeconds->keys()->values()->all(),
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
