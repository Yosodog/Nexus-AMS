<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_PENDING_REASON = 'Auto-refunded during pending withdrawal guard migration because another pending withdrawal already existed for this nation.';

    private const RECONCILIATION_PENDING_REASON = 'Quarantined for evidence-based reconciliation: the pending-withdrawal guard migration recorded an automatic refund without verifying the external bank outcome.';

    private const SOURCE_MIGRATION = '2026_06_06_053031_add_pending_withdrawal_key_to_transactions_table';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->where('is_pending', false)
            ->whereNotNull('refunded_at')
            ->whereNull('bank_attempt_status')
            ->where('pending_reason', self::LEGACY_PENDING_REASON)
            ->orderBy('id')
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $details = json_decode((string) ($transaction->bank_reconciliation_details ?? ''), true);

                    if (! is_array($details)) {
                        $details = [];
                    }

                    $details['legacy_pending_guard_auto_refund'] = [
                        'identified' => true,
                        'identified_at' => now()->toISOString(),
                        'source_migration' => self::SOURCE_MIGRATION,
                        'recorded_refunded_at' => (string) $transaction->refunded_at,
                        'external_bank_outcome' => 'unknown',
                        'local_credit_outcome' => 'requires_verification',
                    ];

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->whereNull('bank_attempt_status')
                        ->where('pending_reason', self::LEGACY_PENDING_REASON)
                        ->update([
                            'requires_admin_approval' => true,
                            'pending_withdrawal_key' => null,
                            'pending_reason' => self::RECONCILIATION_PENDING_REASON,
                            'bank_attempt_status' => 'needs_reconciliation',
                            'bank_correlation_id' => $transaction->bank_correlation_id
                                ?: 'NXS-WD-LEGACY-'.$transaction->id,
                            'bank_reconciliation_details' => json_encode($details, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('transactions')
            ->where('bank_attempt_status', 'needs_reconciliation')
            ->where('bank_reconciliation_details', 'like', '%"legacy_pending_guard_auto_refund":{"identified":true%')
            ->exists()) {
            throw new RuntimeException(
                'Resolve all legacy pending-guard auto-refunds before rolling back their reconciliation quarantine.'
            );
        }
    }
};
