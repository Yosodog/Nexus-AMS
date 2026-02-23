<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(
                ['is_pending', 'transaction_type', 'to_account_id'],
                'transactions_pending_withdrawal_lookup_idx'
            );
            $table->index(
                ['transaction_type', 'requires_admin_approval', 'approved_at', 'denied_at', 'created_at'],
                'transactions_withdrawal_review_queue_idx'
            );
            $table->index(
                ['nation_id', 'transaction_type', 'requires_admin_approval', 'created_at'],
                'transactions_nation_withdrawal_window_idx'
            );
            $table->index(
                ['nation_id', 'is_pending'],
                'transactions_nation_pending_idx'
            );
            $table->index(
                ['from_account_id', 'created_at'],
                'transactions_from_created_at_idx'
            );
            $table->index(
                ['to_account_id', 'created_at'],
                'transactions_to_created_at_idx'
            );
            $table->index(
                ['transaction_type', 'created_at', 'nation_id'],
                'transactions_type_created_at_nation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_pending_withdrawal_lookup_idx');
            $table->dropIndex('transactions_withdrawal_review_queue_idx');
            $table->dropIndex('transactions_nation_withdrawal_window_idx');
            $table->dropIndex('transactions_nation_pending_idx');
            $table->dropIndex('transactions_from_created_at_idx');
            $table->dropIndex('transactions_to_created_at_idx');
            $table->dropIndex('transactions_type_created_at_nation_idx');
        });
    }
};
