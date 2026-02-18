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
        Schema::table('war_plans', function (Blueprint $table) {
            $table->string('discord_forum_channel_id', 190)
                ->nullable()
                ->after('assignments_published_at');
        });

        Schema::table('war_counters', function (Blueprint $table) {
            $table->string('discord_forum_channel_id', 190)
                ->nullable()
                ->after('war_declaration_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_plans', function (Blueprint $table) {
            $table->dropColumn('discord_forum_channel_id');
        });

        Schema::table('war_counters', function (Blueprint $table) {
            $table->dropColumn('discord_forum_channel_id');
        });
    }
};
