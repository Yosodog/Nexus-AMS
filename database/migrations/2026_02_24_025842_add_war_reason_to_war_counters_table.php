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
            $table->string('war_reason', 255)
                ->nullable()
                ->after('discord_forum_channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_counters', function (Blueprint $table) {
            $table->dropColumn('war_reason');
        });
    }
};
