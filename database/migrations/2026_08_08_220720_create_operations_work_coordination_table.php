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
        Schema::create('operations_work_coordination', function (Blueprint $table) {
            $table->id();
            $table->string('work_key', 191);
            $table->string('occurrence_key', 191);
            $table->string('source_type', 64);
            $table->string('source_fingerprint', 64);
            $table->string('team_override_key', 64)->nullable();
            $table->foreignId('assignee_user_id')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('triage_acknowledged_at')->nullable();
            $table->foreignId('triage_acknowledged_by_user_id')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by_user_id')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('first_action_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->timestamps();

            $table->foreign('assignee_user_id', 'operations_coord_assignee_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by_user_id', 'operations_coord_assigned_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('triage_acknowledged_by_user_id', 'operations_coord_ack_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('escalated_by_user_id', 'operations_coord_escalated_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique(['work_key', 'occurrence_key'], 'operations_coord_work_occ_unique');
            $table->unique(['work_key', 'active_key'], 'operations_coord_work_active_unique');
            $table->index(['active_key', 'source_type'], 'operations_coord_active_source_idx');
            $table->index(['active_key', 'assignee_user_id'], 'operations_coord_active_assignee_idx');
            $table->index(['source_type', 'last_seen_at'], 'operations_coord_source_seen_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_work_coordination');
    }
};
