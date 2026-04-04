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
        Schema::table('war_counters', function (Blueprint $table) {
            $table->index('aggressor_nation_id', 'war_counter_aggressor_idx');
            $table->dropUnique('war_counter_active_unique');
            $table->index(['aggressor_nation_id', 'status'], 'war_counter_aggressor_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_counters', function (Blueprint $table) {
            $table->dropIndex('war_counter_aggressor_status_idx');
            $table->unique(['aggressor_nation_id', 'status'], 'war_counter_active_unique');
            $table->dropIndex('war_counter_aggressor_idx');
        });
    }
};
