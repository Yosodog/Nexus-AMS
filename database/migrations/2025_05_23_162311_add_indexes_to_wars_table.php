<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wars', function (Blueprint $table) {
            $table->index(['date', 'att_alliance_id', 'def_alliance_id'], 'idx_wars_recent_active_alliance');
            $table->index('end_date', 'idx_wars_end_date_null');
            $table->index(['att_alliance_id', 'att_alliance_position'], 'idx_att_alliance_position');
            $table->index(['def_alliance_id', 'def_alliance_position'], 'idx_def_alliance_position');
            $table->index('date', 'idx_wars_date_only');
        });
    }

    public function down(): void
    {
        Schema::table('wars', function (Blueprint $table) {
            $table->dropIndex('idx_wars_recent_active_alliance');
            $table->dropIndex('idx_wars_end_date_null');
            $table->dropIndex('idx_att_alliance_position');
            $table->dropIndex('idx_def_alliance_position');
            $table->dropIndex('idx_wars_date_only');
        });
    }
};
