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
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'pending_withdrawal_key')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->unsignedTinyInteger('pending_withdrawal_key')->nullable()->after('is_pending');
            });
        }

        $this->quarantineDuplicatePendingWithdrawals();

        DB::table('transactions')
            ->where('is_pending', true)
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->whereNotNull('nation_id')
            ->where(function ($query): void {
                $query->whereNull('bank_attempt_status')
                    ->orWhere('bank_attempt_status', '!=', 'needs_reconciliation');
            })
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

    private function quarantineDuplicatePendingWithdrawals(): void
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

        foreach ($pendingWithdrawals as $withdrawals) {
            $duplicates = $withdrawals->slice(1);

            foreach ($duplicates as $duplicate) {
                DB::table('transactions')
                    ->where('id', $duplicate->id)
                    ->update([
                        'requires_admin_approval' => true,
                        'bank_attempt_status' => 'needs_reconciliation',
                        'pending_withdrawal_key' => null,
                        'pending_reason' => 'Quarantined during pending withdrawal guard migration because another pending withdrawal already existed for this nation. Verify external bank state before refunding or completing it.',
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
