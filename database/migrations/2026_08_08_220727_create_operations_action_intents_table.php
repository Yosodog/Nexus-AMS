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
        Schema::create('operations_action_intents', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64);
            $table->foreignId('actor_user_id');
            $table->string('action', 80);
            $table->json('payload');
            $table->char('preview_fingerprint', 64);
            $table->string('status', 24)->default('draft');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_user_id', 'operations_intent_actor_fk')
                ->references('id')->on('users')->cascadeOnDelete();

            $table->unique('token_hash', 'operations_intent_token_unique');
            $table->index(['actor_user_id', 'status'], 'operations_intent_actor_status_idx');
            $table->index(['status', 'expires_at'], 'operations_intent_status_expiry_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_action_intents');
    }
};
