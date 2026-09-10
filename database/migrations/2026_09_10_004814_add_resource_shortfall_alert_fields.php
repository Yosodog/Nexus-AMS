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
        Schema::table('alert_user_settings', function (Blueprint $table): void {
            $table->boolean('resource_shortfall_alerts_enabled')->default(false)->after('discord_enabled');
        });

        Schema::table('alert_occurrences', function (Blueprint $table): void {
            $table->index(
                ['audience_user_id', 'event_key', 'occurred_at'],
                'alert_occurrence_user_event_idx',
            );
        });

        Schema::table('mmr_assistant_purchases', function (Blueprint $table): void {
            $table->foreignId('discord_action_intent_id')
                ->nullable()
                ->after('account_id')
                ->constrained('discord_action_intents')
                ->nullOnDelete();
            $table->unique('discord_action_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mmr_assistant_purchases', function (Blueprint $table): void {
            $table->dropUnique(['discord_action_intent_id']);
            $table->dropConstrainedForeignId('discord_action_intent_id');
        });

        Schema::table('alert_occurrences', function (Blueprint $table): void {
            $table->dropIndex('alert_occurrence_user_event_idx');
        });

        Schema::table('alert_user_settings', function (Blueprint $table): void {
            $table->dropColumn('resource_shortfall_alerts_enabled');
        });
    }
};
