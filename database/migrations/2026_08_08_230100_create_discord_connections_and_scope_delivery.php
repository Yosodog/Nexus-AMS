<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('mode', 24);
            $table->string('state', 24)->default('active');
            $table->string('application_id', 20);
            $table->string('guild_id', 20);
            $table->unsignedInteger('generation');
            $table->unsignedTinyInteger('protocol_version')->default(2);

            $table->string('relay_current_key_id', 128);
            $table->string('relay_current_public_key', 128);
            $table->string('relay_next_key_id', 128)->nullable();
            $table->string('relay_next_public_key', 128)->nullable();
            $table->timestamp('relay_next_activates_at')->nullable();

            $table->string('nexus_current_key_id', 128)->nullable();
            $table->string('nexus_current_public_key', 128)->nullable();
            $table->string('nexus_next_key_id', 128)->nullable();
            $table->string('nexus_next_public_key', 128)->nullable();
            $table->timestamp('nexus_next_activates_at')->nullable();

            $table->unsignedInteger('capability_version')->default(1);
            $table->json('capabilities')->nullable();
            $table->boolean('v1_reader_enabled')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->string('active_guild_id', 20)
                ->nullable()
                ->storedAs("case when state = 'active' then guild_id else null end");
            $table->unique('active_guild_id', 'discord_connections_one_active_guild');
            $table->unique(['application_id', 'guild_id', 'generation'], 'discord_connections_binding_generation_unique');
            $table->index(['guild_id', 'state'], 'discord_connections_guild_state_idx');
        });

        Schema::table('discord_queue', function (Blueprint $table): void {
            $table->uuid('connection_id')->nullable()->after('alert_delivery_batch_id');
            $table->string('application_id', 20)->nullable()->after('connection_id');
            $table->unsignedInteger('connection_generation')->nullable()->after('application_id');
            $table->string('dedupe_scope', 80)->default('legacy')->after('dedupe_key');

            $table->dropUnique(['dedupe_key']);
            $table->unique(['dedupe_scope', 'dedupe_key'], 'discord_queue_scope_dedupe_unique');
            $table->index(
                ['connection_id', 'connection_generation', 'status', 'lane', 'priority', 'available_at'],
                'discord_queue_connection_claim_idx',
            );
        });

        Schema::table('discord_command_receipts', function (Blueprint $table): void {
            $table->uuid('connection_id')->nullable()->after('interaction_id');
            $table->unsignedInteger('connection_generation')->nullable()->after('connection_id');
            $table->uuid('relay_idempotency_key')->nullable()->after('connection_generation');
            $table->index(
                ['connection_id', 'connection_generation', 'interaction_id'],
                'discord_command_receipts_connection_idx',
            );
            $table->unique(
                ['connection_id', 'connection_generation', 'relay_idempotency_key'],
                'discord_command_receipts_relay_idempotency_unique',
            );
        });

        DB::table('discord_queue')->whereNull('dedupe_scope')->update(['dedupe_scope' => 'legacy']);
    }

    public function down(): void
    {
        Schema::table('discord_command_receipts', function (Blueprint $table): void {
            $table->dropUnique('discord_command_receipts_relay_idempotency_unique');
            $table->dropIndex('discord_command_receipts_connection_idx');
            $table->dropColumn(['connection_id', 'connection_generation', 'relay_idempotency_key']);
        });

        Schema::table('discord_queue', function (Blueprint $table): void {
            $table->dropIndex('discord_queue_connection_claim_idx');
            $table->dropUnique('discord_queue_scope_dedupe_unique');
            $table->dropColumn(['connection_id', 'application_id', 'connection_generation', 'dedupe_scope']);
            $table->unique('dedupe_key');
        });

        Schema::dropIfExists('discord_connections');
    }
};
