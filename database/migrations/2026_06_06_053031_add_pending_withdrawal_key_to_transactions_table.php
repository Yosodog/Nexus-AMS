<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'transactions_pending_withdrawal_unique';

    private const KEY_VALUE = 1;

    /**
     * @var array<int, string>
     */
    private const RESOURCES = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'pending_withdrawal_key')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->unsignedTinyInteger('pending_withdrawal_key')->nullable()->after('is_pending');
            });
        }

        $this->refundDuplicatePendingWithdrawals();

        DB::table('transactions')
            ->where('is_pending', true)
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->whereNotNull('nation_id')
            ->update(['pending_withdrawal_key' => self::KEY_VALUE]);

        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique(['nation_id', 'pending_withdrawal_key'], self::INDEX_NAME);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
            $table->dropColumn('pending_withdrawal_key');
        });
    }

    private function refundDuplicatePendingWithdrawals(): void
    {
        $pendingWithdrawals = DB::table('transactions')
            ->where('is_pending', true)
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->whereNotNull('nation_id')
            ->orderBy('nation_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('nation_id');

        $now = now();

        foreach ($pendingWithdrawals as $withdrawals) {
            $duplicates = $withdrawals->slice(1);

            foreach ($duplicates as $duplicate) {
                $this->refundDuplicateWithdrawal($duplicate, $now);
            }
        }
    }

    private function refundDuplicateWithdrawal(object $transaction, DateTimeInterface $refundedAt): void
    {
        if ($transaction->from_account_id) {
            $accountUpdates = ['updated_at' => $refundedAt];

            foreach (self::RESOURCES as $resource) {
                $amount = (float) ($transaction->{$resource} ?? 0);

                if ($amount > 0) {
                    $accountUpdates[$resource] = DB::raw($resource.' + '.number_format($amount, 2, '.', ''));
                }
            }

            DB::table('accounts')
                ->where('id', $transaction->from_account_id)
                ->update($accountUpdates);
        }

        DB::table('transactions')
            ->where('id', $transaction->id)
            ->update([
                'is_pending' => false,
                'requires_admin_approval' => false,
                'refunded_at' => $refundedAt,
                'bank_processing_at' => null,
                'pending_withdrawal_key' => null,
                'pending_reason' => 'Auto-refunded during pending withdrawal guard migration because another pending withdrawal already existed for this nation.',
                'updated_at' => $refundedAt,
            ]);
    }
};
