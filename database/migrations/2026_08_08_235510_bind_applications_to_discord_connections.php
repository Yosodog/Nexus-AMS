<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->uuid('discord_connection_id')->nullable()->after('discord_channel_id')->index();
            $table->unsignedInteger('discord_connection_generation')->nullable()->after('discord_connection_id');
            $table->string('discord_application_id', 20)->nullable()->after('discord_connection_generation');
            $table->string('discord_guild_id', 20)->nullable()->after('discord_application_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['discord_connection_id']);
            $table->dropIndex(['discord_guild_id']);
            $table->dropColumn([
                'discord_connection_id',
                'discord_connection_generation',
                'discord_application_id',
                'discord_guild_id',
            ]);
        });
    }
};
