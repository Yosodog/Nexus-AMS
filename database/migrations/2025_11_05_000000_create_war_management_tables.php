<?php

use App\Models\Alliance;
use App\Models\Nation;
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
        Schema::create('war_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan_type')->default(config('war.plan_defaults.plan_type', 'ordinary'));
            $table->enum('status', ['planning', 'active', 'archived'])->default('planning')->index();
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('preferred_nations_per_target')
                ->default((int) config('war.plan_defaults.preferred_nations_per_target', 3));
            $table->unsignedTinyInteger('max_squad_size')
                ->default((int) config('war.squads.max_size', 3));
            $table->unsignedTinyInteger('squad_cohesion_tolerance')
                ->default((int) config('war.squads.cohesion_tolerance', 10));
            $table->unsignedInteger('activity_window_hours')
                ->default((int) config('war.plan_defaults.activity_window_hours', 72));
            $table->boolean('suppress_counters_when_active')
                ->default((bool) config('war.plan_defaults.suppress_counters_when_active', true));
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('assignments_published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('war_plan_alliances', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\WarPlan::class, 'war_plan_id')->constrained('war_plans')->cascadeOnDelete();
            $table->foreignIdFor(Alliance::class, 'alliance_id')->constrained('alliances')->cascadeOnDelete();
            $table->enum('role', ['friendly', 'enemy'])->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['war_plan_id', 'alliance_id', 'role'], 'war_plan_alliance_unique');
        });

        Schema::create('war_plan_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\WarPlan::class, 'war_plan_id')->constrained('war_plans')->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'nation_id')->constrained('nations')->cascadeOnDelete();
            $table->decimal('target_priority_score', 6, 2)->default(0)->index();
            $table->json('meta')->nullable();
            $table->timestamp('computed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['war_plan_id', 'nation_id'], 'war_plan_target_unique');
        });

        Schema::create('war_plan_squads', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\WarPlan::class, 'war_plan_id')->constrained('war_plans')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedTinyInteger('round')->default(1);
            $table->decimal('cohesion_score', 6, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['war_plan_id', 'round']);
        });

        Schema::create('war_plan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\WarPlan::class, 'war_plan_id')->constrained('war_plans')->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\WarPlanTarget::class, 'war_plan_target_id')
                ->constrained('war_plan_targets')
                ->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'friendly_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\WarPlanSquad::class, 'war_plan_squad_id')
                ->nullable()
                ->constrained('war_plan_squads')
                ->nullOnDelete();
            $table->decimal('match_score', 6, 2)->default(0);
            $table->enum('status', ['proposed', 'confirmed', 'published'])->default('proposed')->index();
            $table->boolean('is_overridden')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['war_plan_target_id', 'friendly_nation_id'], 'war_plan_assignment_unique');
        });

        Schema::create('war_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Nation::class, 'aggressor_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft')->index();
            $table->unsignedTinyInteger('team_size')
                ->default((int) config('war.counters.default_team_size', 3));
            $table->foreignIdFor(\App\Models\WarPlan::class, 'suppressed_by_plan_id')
                ->nullable()
                ->constrained('war_plans')
                ->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_war_declared_at')->nullable();
            $table->timestamps();
            $table->unique(['aggressor_nation_id', 'status'], 'war_counter_active_unique')
                ->where('status', '!=', 'archived');
        });

        Schema::create('war_counter_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\WarCounter::class, 'war_counter_id')->constrained('war_counters')->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'friendly_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->decimal('match_score', 6, 2)->default(0);
            $table->enum('status', ['proposed', 'finalized'])->default('proposed')->index();
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['war_counter_id', 'friendly_nation_id'], 'war_counter_assignment_unique');
        });

        Schema::create('war_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->json('payload');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_notifications');
        Schema::dropIfExists('war_counter_assignments');
        Schema::dropIfExists('war_counters');
        Schema::dropIfExists('war_plan_assignments');
        Schema::dropIfExists('war_plan_squads');
        Schema::dropIfExists('war_plan_targets');
        Schema::dropIfExists('war_plan_alliances');
        Schema::dropIfExists('war_plans');
    }
};
