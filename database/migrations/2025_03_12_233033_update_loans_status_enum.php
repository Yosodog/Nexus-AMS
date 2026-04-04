<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE `loans` CHANGE `status` `status` ENUM('pending','approved','denied','paid','missed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE `loans` CHANGE `status` `status` ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending'");
    }
};
