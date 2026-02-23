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
        Schema::table('mmr_assistant_purchases', function (Blueprint $table) {
            $table->index(['account_id', 'created_at'], 'mmr_assistant_purchases_account_created_idx');
            $table->index('created_at', 'mmr_assistant_purchases_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mmr_assistant_purchases', function (Blueprint $table) {
            $table->dropIndex('mmr_assistant_purchases_account_created_idx');
            $table->dropIndex('mmr_assistant_purchases_created_at_idx');
        });
    }
};
