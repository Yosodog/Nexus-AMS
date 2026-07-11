<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_results', function (Blueprint $table): void {
            $table->timestamp('acknowledged_at')->nullable()->after('last_evaluated_at');
            $table->foreignId('acknowledged_by_user_id')->nullable()->after('acknowledged_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('snoozed_until')->nullable()->after('acknowledged_by_user_id');
            $table->foreignId('snoozed_by_user_id')->nullable()->after('snoozed_until')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('waived_until')->nullable()->after('snoozed_by_user_id');
            $table->foreignId('waived_by_user_id')->nullable()->after('waived_until')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('waived_by_user_id');
            $table->string('remediation_note', 500)->nullable()->after('due_at');

            $table->index(['nation_id', 'snoozed_until'], 'audit_results_nation_snooze_idx');
            $table->index(['due_at', 'waived_until'], 'audit_results_due_waiver_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_results', function (Blueprint $table): void {
            $table->dropIndex('audit_results_nation_snooze_idx');
            $table->dropIndex('audit_results_due_waiver_idx');
            $table->dropConstrainedForeignId('acknowledged_by_user_id');
            $table->dropConstrainedForeignId('snoozed_by_user_id');
            $table->dropConstrainedForeignId('waived_by_user_id');
            $table->dropColumn([
                'acknowledged_at',
                'snoozed_until',
                'waived_until',
                'due_at',
                'remediation_note',
            ]);
        });
    }
};
