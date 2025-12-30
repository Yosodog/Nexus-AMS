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
        Schema::table('direct_deposit_logs', function (Blueprint $table) {
            $table->unique('bank_record_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direct_deposit_logs', function (Blueprint $table) {
            $table->dropUnique('direct_deposit_logs_bank_record_id_unique');
        });
    }
};
