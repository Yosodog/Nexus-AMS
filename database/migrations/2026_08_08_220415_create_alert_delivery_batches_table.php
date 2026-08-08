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
        Schema::create('alert_delivery_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('destination_kind', 32);
            $table->string('status', 24)->default('pending');
            $table->string('template_key', 96);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('dedupe_key', 191)->unique();
            $table->json('destination_snapshot')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('discord_queue_id', 36)->nullable();
            $table->string('provider_message_id', 32)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at', 'id']);
            $table->index(['recipient_user_id', 'created_at']);
            $table->index('discord_queue_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_delivery_batches');
    }
};
