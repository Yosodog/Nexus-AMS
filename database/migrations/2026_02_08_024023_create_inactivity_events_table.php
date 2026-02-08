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
        Schema::create('inactivity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->timestamp('episode_started_at');
            $table->timestamp('episode_ended_at')->nullable();
            $table->timestamp('detected_inactive_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->string('last_notification_type', 50)->nullable();
            $table->timestamp('dd_autoenrolled_at')->nullable();
            $table->timestamp('dd_opted_out_at')->nullable();
            $table->json('actions_config_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('nation_id')
                ->references('id')
                ->on('nations')
                ->cascadeOnDelete();
            $table->index(['nation_id', 'episode_ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inactivity_events');
    }
};
