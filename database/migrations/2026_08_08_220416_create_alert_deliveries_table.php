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
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_delivery_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('destination_kind', 32);
            $table->string('delivery_mode', 16)->default('immediate');
            $table->string('status', 24)->default('pending');
            $table->string('match_key', 191)->unique();
            $table->string('reason_code', 100)->nullable();
            $table->json('destination_snapshot')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'created_at', 'id'], 'alert_delivery_user_activity_idx');
            $table->index(['status', 'scheduled_at', 'id']);
            $table->index(['alert_subscription_id', 'created_at']);
            $table->index(['alert_route_id', 'created_at']);
            $table->index(['alert_delivery_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
