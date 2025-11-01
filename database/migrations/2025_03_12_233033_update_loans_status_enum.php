<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `loans` CHANGE `status` `status` ENUM('pending','approved','denied','paid','missed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `loans` CHANGE `status` `status` ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending'");
    }
};
