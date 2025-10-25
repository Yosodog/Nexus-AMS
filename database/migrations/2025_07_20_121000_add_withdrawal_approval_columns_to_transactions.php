<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('note')->nullable()->after('transaction_type');
            $table->boolean('requires_admin_approval')->default(false)->after('refunded_at');
            $table->string('pending_reason')->nullable()->after('requires_admin_approval');
            $table->timestamp('approved_at')->nullable()->after('pending_reason');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('denied_at')->nullable()->after('approved_by');
            $table->foreignId('denied_by')->nullable()->after('denied_at')->constrained('users')->nullOnDelete();
            $table->text('denial_reason')->nullable()->after('denied_by');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['denied_by']);
            $table->dropColumn([
                'note',
                'requires_admin_approval',
                'pending_reason',
                'approved_at',
                'approved_by',
                'denied_at',
                'denied_by',
                'denial_reason',
            ]);
        });
    }
};
