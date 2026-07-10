<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('bank_attempt_status', 32)
                ->nullable()
                ->after('bank_record_id')
                ->index('transactions_bank_attempt_status_index');
            $table->string('bank_correlation_id', 64)
                ->nullable()
                ->after('bank_attempt_status')
                ->unique('transactions_bank_correlation_id_unique');
            $table->unsignedInteger('bank_attempt_count')->default(0)->after('bank_correlation_id');
            $table->timestamp('bank_attempted_at')->nullable()->after('bank_attempt_count');
            $table->json('bank_reconciliation_details')->nullable()->after('bank_attempted_at');
        });

        DB::table('transactions')
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->where('is_pending', true)
            ->whereNotNull('bank_processing_at')
            ->whereNull('bank_record_id')
            ->whereNull('refunded_at')
            ->whereNull('denied_at')
            ->orderBy('id')
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $reason = 'This withdrawal was already in progress when outcome tracking was installed. Its external result must be verified before it can be resolved.';

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'bank_attempt_status' => 'needs_reconciliation',
                            'bank_correlation_id' => 'NXS-WD-LEGACY-'.$transaction->id,
                            'bank_attempt_count' => 1,
                            'bank_attempted_at' => $transaction->bank_processing_at,
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
        if (Schema::hasColumn('transactions', 'bank_attempt_status')
            && DB::table('transactions')->where('bank_attempt_status', 'needs_reconciliation')->exists()) {
            throw new RuntimeException(
                'Resolve all ambiguous withdrawals before rolling back withdrawal outcome tracking.'
            );
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_bank_correlation_id_unique');
            $table->dropIndex('transactions_bank_attempt_status_index');
            $table->dropColumn([
                'bank_attempt_status',
                'bank_correlation_id',
                'bank_attempt_count',
                'bank_attempted_at',
                'bank_reconciliation_details',
            ]);
        });
    }
};
