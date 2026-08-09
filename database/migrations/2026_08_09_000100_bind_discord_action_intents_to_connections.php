<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_action_intents', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('discord_user_id', 20)->nullable()->after('discord_account_id');
            $table->uuid('connection_id')->nullable()->after('guild_id');
            $table->unsignedInteger('connection_generation')->nullable()->after('connection_id');
            $table->string('application_id', 20)->nullable()->after('connection_generation');
            $table->index(
                ['connection_id', 'connection_generation', 'discord_user_id', 'action', 'status'],
                'discord_action_intents_connection_actor_idx',
            );
        });

        DB::table('discord_action_intents')
            ->whereNotNull('discord_account_id')
            ->orderBy('id')
            ->eachById(function (object $intent): void {
                $discordUserId = DB::table('discord_accounts')
                    ->where('id', $intent->discord_account_id)
                    ->value('discord_id');

                if (is_string($discordUserId) && $discordUserId !== '') {
                    DB::table('discord_action_intents')
                        ->where('id', $intent->id)
                        ->update(['discord_user_id' => $discordUserId]);
                }
            });
    }

    public function down(): void
    {
        DB::table('discord_action_intents')->whereNull('user_id')->delete();

        Schema::table('discord_action_intents', function (Blueprint $table): void {
            $table->dropIndex('discord_action_intents_connection_actor_idx');
            $table->dropColumn([
                'discord_user_id',
                'connection_id',
                'connection_generation',
                'application_id',
            ]);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
