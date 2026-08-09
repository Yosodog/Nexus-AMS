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
        Schema::create('operations_work_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordination_id');
            $table->string('work_key', 191);
            $table->string('occurrence_key', 191);
            $table->string('source_type', 64);
            $table->string('team_key', 64);
            $table->string('event_type', 64);
            $table->foreignId('actor_user_id')->nullable();
            $table->foreignId('subject_user_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('coordination_id', 'operations_event_coordination_fk')
                ->references('id')->on('operations_work_coordination')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'operations_event_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('subject_user_id', 'operations_event_subject_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique('idempotency_key', 'operations_event_idempotency_unique');
            $table->index(['coordination_id', 'occurred_at'], 'operations_event_coord_time_idx');
            $table->index(['team_key', 'occurred_at'], 'operations_event_team_time_idx');
            $table->index(['event_type', 'occurred_at'], 'operations_event_type_time_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'operations_event_actor_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_work_events');
    }
};
