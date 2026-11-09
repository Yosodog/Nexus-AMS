<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite stores enum columns as a CHECK constraint, and the MySQL-only
 * 2025_03_12_233033_update_loans_status_enum migration never widened it there,
 * so paid and missed loans could not be saved. MySQL already has these values.
 */
return new class extends Migration
{
    private const STATUSES = ['pending', 'approved', 'denied', 'paid', 'missed'];

    private const ORIGINAL_STATUSES = ['pending', 'approved', 'denied'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->enum('status', self::STATUSES)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        if (DB::table('loans')->whereNotIn('status', self::ORIGINAL_STATUSES)->exists()) {
            throw new RuntimeException('Cannot narrow loans.status while paid or missed loans exist.');
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->enum('status', self::ORIGINAL_STATUSES)->default('pending')->change();
        });
    }
};
