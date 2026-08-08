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
        Schema::table('discord_queue', function (Blueprint $table) {
            $table->foreignId('alert_delivery_batch_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('guild_id', 32)->nullable()->after('action');
            $table->string('lane', 24)->default('legacy')->after('guild_id');
            $table->unsignedTinyInteger('priority')->default(50)->after('lane');

            $table->index(
                ['status', 'lane', 'priority', 'available_at'],
                'discord_queue_claim_lane_idx',
            );
        });

        Schema::table('alert_delivery_batches', function (Blueprint $table) {
            $table->foreign('discord_queue_id', 'alert_delivery_batch_queue_foreign')
                ->references('id')
                ->on('discord_queue')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_delivery_batches', function (Blueprint $table) {
            $table->dropForeign('alert_delivery_batch_queue_foreign');
        });

        Schema::table('discord_queue', function (Blueprint $table) {
            $table->dropIndex('discord_queue_claim_lane_idx');
            $table->dropConstrainedForeignId('alert_delivery_batch_id');
            $table->dropColumn(['guild_id', 'lane', 'priority']);
        });
    }
};
