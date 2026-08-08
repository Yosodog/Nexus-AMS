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
        Schema::create('alert_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alliance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('kind', 32);
            $table->string('guild_id', 32);
            $table->string('channel_id', 32)->nullable();
            $table->json('mention_role_ids')->nullable();
            $table->string('health_status', 24)->default('unverified');
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_failure_code', 100)->nullable();
            $table->timestamps();

            $table->index(['alliance_id', 'kind', 'health_status'], 'alert_destination_scope_health_idx');
            $table->index(['guild_id', 'channel_id']);
            $table->unique(['guild_id', 'channel_id', 'kind'], 'alert_destination_discord_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_destinations');
    }
};
