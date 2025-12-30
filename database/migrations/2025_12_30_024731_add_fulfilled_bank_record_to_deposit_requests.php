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
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('fulfilled_bank_record_id')
                ->nullable()
                ->after('status');

            $table->unique('fulfilled_bank_record_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropUnique('deposit_requests_fulfilled_bank_record_id_unique');
            $table->dropColumn('fulfilled_bank_record_id');
        });
    }
};
