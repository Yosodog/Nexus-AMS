<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->where('is_pending', true)
            ->whereNotNull('bank_processing_at')
            ->whereNull('bank_record_id')
            ->whereNull('bank_attempt_status')
            ->whereNull('refunded_at')
            ->whereNull('denied_at')
            ->orderBy('id')
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $reason = 'This withdrawal was already in progress when outcome tracking was installed. Its external result must be verified before it can be resolved.';

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->whereNull('bank_attempt_status')
                        ->update([
                            'bank_attempt_status' => 'needs_reconciliation',
                            'bank_correlation_id' => $transaction->bank_correlation_id
                                ?: 'NXS-WD-LEGACY-'.$transaction->id,
                            'bank_attempt_count' => max(1, (int) $transaction->bank_attempt_count),
                            'bank_attempted_at' => $transaction->bank_attempted_at
                                ?: $transaction->bank_processing_at,
                            'bank_processing_at' => null,
                            'requires_admin_approval' => true,
                            'pending_reason' => filled($transaction->pending_reason)
                                ? $transaction->pending_reason.' | '.$reason
                                : $reason,
                            'bank_reconciliation_details' => json_encode([
                                'detected_at' => now()->toISOString(),
                                'legacy_processing_row' => true,
                                'correlation_present_in_bank_note' => false,
                            ], JSON_THROW_ON_ERROR),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'bank_reconciliation_details')
            && DB::table('transactions')
                ->where('bank_attempt_status', 'needs_reconciliation')
                ->where('bank_reconciliation_details', 'like', '%"legacy_processing_row":true%')
                ->exists()) {
            throw new RuntimeException(
                'Resolve all legacy ambiguous withdrawals before rolling back their reconciliation backfill.'
            );
        }
    }
};
