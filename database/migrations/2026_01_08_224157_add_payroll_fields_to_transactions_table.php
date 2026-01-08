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
        DB::statement("ALTER TABLE `transactions` CHANGE `transaction_type` `transaction_type` ENUM('deposit','withdrawal','transfer','payroll') NOT NULL");

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payroll_grade_id')->nullable()->after('transaction_type')
                ->constrained('payroll_grades')
                ->nullOnDelete();
            $table->foreignId('payroll_member_id')->nullable()->after('payroll_grade_id')
                ->constrained('payroll_members')
                ->nullOnDelete();
            $table->date('payroll_run_date')->nullable()->after('payroll_member_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['payroll_grade_id']);
            $table->dropForeign(['payroll_member_id']);
            $table->dropColumn(['payroll_grade_id', 'payroll_member_id', 'payroll_run_date']);
        });

        DB::statement("ALTER TABLE `transactions` CHANGE `transaction_type` `transaction_type` ENUM('deposit','withdrawal','transfer') NOT NULL");
    }
};
