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
