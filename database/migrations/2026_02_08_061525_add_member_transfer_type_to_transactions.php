<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE `transactions` CHANGE `transaction_type` `transaction_type` ENUM('deposit','withdrawal','transfer','payroll','member_transfer') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE `transactions` CHANGE `transaction_type` `transaction_type` ENUM('deposit','withdrawal','transfer','payroll') NOT NULL");
    }
};
