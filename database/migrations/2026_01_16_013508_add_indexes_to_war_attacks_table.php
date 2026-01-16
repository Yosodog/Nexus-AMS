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
        Schema::table('war_attacks', function (Blueprint $table) {
            $table->index('date', 'idx_war_attacks_date');
            $table->index(['att_id', 'date'], 'idx_war_attacks_att_date');
            $table->index(['type', 'date', 'victor'], 'idx_war_attacks_type_date_victor');
            $table->index(['type', 'date', 'att_id'], 'idx_war_attacks_type_date_att');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_attacks', function (Blueprint $table) {
            $table->dropIndex('idx_war_attacks_date');
            $table->dropIndex('idx_war_attacks_att_date');
            $table->dropIndex('idx_war_attacks_type_date_victor');
            $table->dropIndex('idx_war_attacks_type_date_att');
        });
    }
};
