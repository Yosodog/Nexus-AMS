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
        Schema::create('alert_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_delivery_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('adapter', 32)->default('discord');
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('provider_message_id', 32)->nullable();
            $table->string('provider_guild_id', 32)->nullable();
            $table->string('provider_channel_id', 32)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['alert_delivery_batch_id', 'attempt_number'], 'alert_delivery_attempt_unique');
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_delivery_attempts');
    }
};
