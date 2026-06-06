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
        Schema::table('war_counter_reimbursements', function (Blueprint $table) {
            $table->string('idempotency_key', 64)
                ->nullable()
                ->unique('war_counter_reimbursements_idempotency_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_counter_reimbursements', function (Blueprint $table) {
            $table->dropUnique('war_counter_reimbursements_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
