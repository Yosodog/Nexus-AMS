<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milcom_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 24)->default('draft');
            $table->string('current_stage', 32)->default('scope');
            $table->string('name');
            $table->string('doctrine_version', 32)->default('fixed-v1');
            $table->string('default_war_type', 32)->default('ORDINARY');
            $table->string('default_war_reason')->nullable();
            $table->string('discord_forum_id')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->unsignedInteger('generation_version')->default(1);
            $table->unsignedInteger('dispatch_version')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->json('failure_details')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by', 'milcom_ops_creator_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['type', 'status', 'updated_at', 'id'], 'milcom_ops_cursor_idx');
            $table->index(['status', 'deadline_at'], 'milcom_ops_deadline_idx');
            $table->index(['status', 'completed_at', 'id'], 'milcom_ops_archive_idx');
        });

        Schema::create('milcom_operation_alliances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('alliance_id');
            $table->string('role', 16);
            $table->boolean('included')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_op_alliance_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('alliance_id', 'milcom_op_alliance_alliance_fk')
                ->references('id')->on('alliances')->restrictOnDelete();
            $table->unique(['operation_id', 'alliance_id', 'role'], 'milcom_op_alliance_unique');
        });

        Schema::create('milcom_operation_nations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('nation_id');
            $table->string('role', 16);
            $table->boolean('included')->default(true);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_op_nation_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('nation_id', 'milcom_op_nation_nation_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->unique(['operation_id', 'nation_id', 'role'], 'milcom_op_nation_unique');
        });

        Schema::create('milcom_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('war_id');
            $table->foreignId('attacked_nation_id');
            $table->foreignId('aggressor_nation_id');
            $table->string('status', 24)->default('new');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('ignored_reason')->nullable();
            $table->text('coverage_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('war_id', 'milcom_incident_war_fk')
                ->references('id')->on('wars')->restrictOnDelete();
            $table->foreign('attacked_nation_id', 'milcom_incident_attacked_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->foreign('aggressor_nation_id', 'milcom_incident_aggressor_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->unique('war_id', 'milcom_incident_war_unique');
            $table->index(['status', 'detected_at', 'id'], 'milcom_incident_cursor_idx');
            $table->index(['aggressor_nation_id', 'status'], 'milcom_incident_aggressor_idx');
        });

        Schema::create('milcom_objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('target_nation_id');
            $table->string('priority_tier', 16)->default('standard');
            $table->decimal('priority_score', 7, 2)->default(0);
            $table->unsignedTinyInteger('desired_team_depth')->default(1);
            $table->unsignedTinyInteger('minimum_team_depth')->default(1);
            $table->string('war_type', 32)->default('ORDINARY');
            $table->string('war_reason')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('source_incident_id')->nullable();
            $table->unsignedTinyInteger('open_key')->nullable();
            $table->foreignId('latest_recommendation_run_id')->nullable();
            $table->unsignedInteger('generation_version')->default(1);
            $table->unsignedInteger('dispatch_version')->default(0);
            $table->string('discord_channel_id')->nullable();
            $table->json('blocker_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('engaged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_objective_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('target_nation_id', 'milcom_objective_target_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->foreign('source_incident_id', 'milcom_objective_incident_fk')
                ->references('id')->on('milcom_incidents')->nullOnDelete();
            $table->unique(['operation_id', 'target_nation_id'], 'milcom_objective_target_unique');
            $table->unique(['target_nation_id', 'open_key'], 'milcom_objective_open_unique');
            $table->index(
                ['operation_id', 'status', 'priority_tier', 'priority_score', 'id'],
                'milcom_objective_cursor_idx'
            );
            $table->index(['status', 'deadline_at'], 'milcom_objective_deadline_idx');
            $table->index(
                ['priority_tier', 'status', 'updated_at', 'id'],
                'milcom_objective_attention_idx'
            );
        });

        Schema::table('milcom_incidents', function (Blueprint $table): void {
            $table->foreignId('objective_id')->nullable()->after('status');
            $table->foreign('objective_id', 'milcom_incident_objective_fk')
                ->references('id')->on('milcom_objectives')->nullOnDelete();
            $table->index(['objective_id', 'status'], 'milcom_incident_objective_idx');
        });

        Schema::create('milcom_recommendation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('objective_id')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('algorithm_version', 32)->default('fixed-v1');
            $table->char('input_hash', 64);
            $table->string('trigger', 32);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->unsignedInteger('generation_version');
            $table->unsignedInteger('objectives_total')->default(0);
            $table->unsignedInteger('objectives_processed')->default(0);
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->json('failure_details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_run_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('objective_id', 'milcom_run_objective_fk')
                ->references('id')->on('milcom_objectives')->cascadeOnDelete();
            $table->index(['operation_id', 'status', 'id'], 'milcom_run_op_status_idx');
            $table->index(['objective_id', 'status', 'id'], 'milcom_run_objective_status_idx');
            $table->index(['input_hash', 'status'], 'milcom_run_hash_idx');
            $table->index(['status', 'created_at', 'id'], 'milcom_run_status_created_idx');
        });

        Schema::table('milcom_objectives', function (Blueprint $table): void {
            $table->foreign('latest_recommendation_run_id', 'milcom_objective_latest_run_fk')
                ->references('id')->on('milcom_recommendation_runs')->nullOnDelete();
        });

        Schema::create('milcom_readiness_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recommendation_run_id');
            $table->foreignId('nation_id');
            $table->string('role', 16);
            $table->foreignId('alliance_id')->nullable();
            $table->string('alliance_position', 24)->nullable();
            $table->decimal('score', 12, 2)->nullable();
            $table->unsignedSmallInteger('cities')->nullable();
            $table->unsignedSmallInteger('vacation_turns')->default(0);
            $table->unsignedSmallInteger('beige_turns')->default(0);
            $table->unsignedTinyInteger('offensive_capacity')->nullable();
            $table->unsignedTinyInteger('active_offensive_wars')->nullable();
            $table->unsignedTinyInteger('reserved_offensive_slots')->default(0);
            $table->unsignedInteger('soldiers')->nullable();
            $table->unsignedInteger('tanks')->nullable();
            $table->unsignedInteger('aircraft')->nullable();
            $table->unsignedInteger('ships')->nullable();
            $table->unsignedSmallInteger('missiles')->nullable();
            $table->unsignedSmallInteger('nukes')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('fetched_at');
            $table->unsignedTinyInteger('completeness_percent')->default(0);
            $table->json('payload');
            $table->timestamps();

            $table->foreign('recommendation_run_id', 'milcom_snapshot_run_fk')
                ->references('id')->on('milcom_recommendation_runs')->cascadeOnDelete();
            $table->foreign('nation_id', 'milcom_snapshot_nation_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->unique(
                ['recommendation_run_id', 'nation_id', 'role'],
                'milcom_snapshot_run_nation_unique'
            );
            $table->index(['recommendation_run_id', 'role', 'nation_id'], 'milcom_snapshot_role_idx');
        });

        Schema::create('milcom_objective_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recommendation_run_id');
            $table->foreignId('objective_id');
            $table->decimal('team_score', 6, 2)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('proposed_team');
            $table->json('alternatives')->nullable();
            $table->json('blocker_summary')->nullable();
            $table->json('factor_explanations')->nullable();
            $table->timestamps();

            $table->foreign('recommendation_run_id', 'milcom_recommendation_run_fk')
                ->references('id')->on('milcom_recommendation_runs')->cascadeOnDelete();
            $table->foreign('objective_id', 'milcom_recommendation_objective_fk')
                ->references('id')->on('milcom_objectives')->cascadeOnDelete();
            $table->unique(
                ['recommendation_run_id', 'objective_id'],
                'milcom_recommendation_objective_unique'
            );
            $table->index(['objective_id', 'id'], 'milcom_recommendation_objective_idx');
        });

        Schema::create('milcom_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('objective_id');
            $table->foreignId('friendly_nation_id');
            $table->decimal('score', 6, 2);
            $table->decimal('confidence', 5, 2)->default(0);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('status', 24)->default('proposed');
            $table->boolean('is_locked')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('recommendation_run_id')->nullable();
            $table->unsignedBigInteger('declared_war_id')->nullable();
            $table->json('factor_explanations')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('engaged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('objective_id', 'milcom_assignment_objective_fk')
                ->references('id')->on('milcom_objectives')->cascadeOnDelete();
            $table->foreign('friendly_nation_id', 'milcom_assignment_nation_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->foreign('recommendation_run_id', 'milcom_assignment_run_fk')
                ->references('id')->on('milcom_recommendation_runs')->nullOnDelete();
            $table->foreign('declared_war_id', 'milcom_assignment_war_fk')
                ->references('id')->on('wars')->nullOnDelete();
            $table->unique(['objective_id', 'friendly_nation_id'], 'milcom_assignment_pair_unique');
            $table->index(['objective_id', 'status', 'id'], 'milcom_assignment_objective_idx');
            $table->index(['friendly_nation_id', 'status', 'id'], 'milcom_assignment_friendly_idx');
            $table->index(['declared_war_id', 'status'], 'milcom_assignment_declared_war_idx');
            $table->index(['status', 'declared_war_id', 'id'], 'milcom_assignment_reconcile_idx');
        });

        Schema::create('milcom_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('objective_id');
            $table->unsignedInteger('dispatch_version');
            $table->string('status', 24)->default('pending');
            $table->uuid('queue_id')->nullable();
            $table->string('dedupe_key');
            $table->json('payload_snapshot');
            $table->string('external_channel_id')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_dispatch_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('objective_id', 'milcom_dispatch_objective_fk')
                ->references('id')->on('milcom_objectives')->cascadeOnDelete();
            $table->unique('dedupe_key', 'milcom_dispatch_dedupe_unique');
            $table->index(['objective_id', 'status', 'id'], 'milcom_dispatch_objective_idx');
            $table->index(['status', 'created_at', 'id'], 'milcom_dispatch_status_idx');
            $table->index(['status', 'queue_id', 'id'], 'milcom_dispatch_queue_idx');
        });

        Schema::create('milcom_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->nullable();
            $table->foreignId('objective_id')->nullable();
            $table->foreignId('incident_id')->nullable();
            $table->foreignId('assignment_id')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('source', 24);
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_event_op_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('objective_id', 'milcom_event_objective_fk')
                ->references('id')->on('milcom_objectives')->cascadeOnDelete();
            $table->foreign('incident_id', 'milcom_event_incident_fk')
                ->references('id')->on('milcom_incidents')->cascadeOnDelete();
            $table->foreign('assignment_id', 'milcom_event_assignment_fk')
                ->references('id')->on('milcom_assignments')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'milcom_event_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['operation_id', 'id'], 'milcom_event_operation_cursor_idx');
            $table->index(['objective_id', 'id'], 'milcom_event_objective_cursor_idx');
            $table->index(['incident_id', 'id'], 'milcom_event_incident_cursor_idx');
            $table->index(['event_type', 'id'], 'milcom_event_type_idx');
        });

        Schema::create('milcom_nation_capacity_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('friendly_nation_id');
            $table->unsignedInteger('version')->default(0);
            $table->unsignedTinyInteger('last_known_capacity')->default(0);
            $table->unsignedTinyInteger('last_known_active_wars')->default(0);
            $table->unsignedTinyInteger('last_known_reservations')->default(0);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->foreign('friendly_nation_id', 'milcom_capacity_nation_fk')
                ->references('id')->on('nations')->restrictOnDelete();
            $table->unique('friendly_nation_id', 'milcom_capacity_nation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('milcom_objectives', function (Blueprint $table): void {
            $table->dropForeign('milcom_objective_latest_run_fk');
        });

        Schema::dropIfExists('milcom_nation_capacity_locks');
        Schema::dropIfExists('milcom_events');
        Schema::dropIfExists('milcom_dispatches');
        Schema::dropIfExists('milcom_assignments');
        Schema::dropIfExists('milcom_objective_recommendations');
        Schema::dropIfExists('milcom_readiness_snapshots');
        Schema::dropIfExists('milcom_recommendation_runs');

        Schema::table('milcom_incidents', function (Blueprint $table): void {
            $table->dropForeign('milcom_incident_objective_fk');
        });

        Schema::dropIfExists('milcom_objectives');
        Schema::dropIfExists('milcom_incidents');
        Schema::dropIfExists('milcom_operation_nations');
        Schema::dropIfExists('milcom_operation_alliances');
        Schema::dropIfExists('milcom_operations');
    }
};
