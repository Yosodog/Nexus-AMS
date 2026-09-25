<?php

use App\Services\Economy\EconomyRules;
use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::create('raid_loot_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('war_id')->index();
            $table->string('kind', 16);
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('winner_nation_id')->index();
            $table->unsignedBigInteger('loser_nation_id');
            $table->unsignedBigInteger('loser_alliance_id')->nullable();
            $table->string('war_type', 16);
            $this->resourceColumns($table, '');
            $table->decimal('loot_fraction', 10, 8)->nullable();
            $table->string('fraction_source', 16);
            $table->json('predicted_resources')->nullable();
            $table->decimal('predicted_value', 20, 2)->nullable();
            $table->decimal('revealed_value', 20, 2)->nullable();
            $table->string('prediction_evidence_kind', 24)->nullable();
            $table->decimal('prediction_age_hours', 10, 2)->nullable();
            $table->string('prediction_activity_bucket', 16)->nullable();
            $table->timestamps();

            $table->index(['loser_nation_id', 'kind', 'occurred_at'], 'raid_loot_events_loser_kind_index');
            $table->index(['loser_alliance_id', 'kind', 'occurred_at'], 'raid_loot_events_alliance_kind_index');
        });

        WorldSchema::create('raid_target_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('nation_id')->primary();
            $table->string('nation_name', 64);
            $table->string('leader_name', 64);
            $table->unsignedBigInteger('alliance_id')->default(0)->index();
            $table->string('alliance_position', 24)->nullable();
            $table->decimal('score', 12, 2)->index();
            $table->unsignedSmallInteger('num_cities');
            $table->string('color', 16);
            $table->unsignedSmallInteger('beige_turns');
            $table->unsignedInteger('vacation_mode_turns');
            $table->timestamp('last_active')->nullable();
            $table->string('war_policy', 24)->nullable();
            foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'] as $unit) {
                $table->unsignedInteger($unit)->default(0);
            }
            $table->unsignedInteger('highest_city_population')->default(0);
            $table->decimal('highest_city_infra', 10, 2)->default(0);
            $table->decimal('avg_infra', 10, 2)->default(0);
            $table->unsignedTinyInteger('defensive_wars')->default(0);
            $table->unsignedTinyInteger('offensive_wars')->default(0);
            $table->string('baseline_kind', 24);
            $table->timestamp('baseline_at');
            $table->unsignedBigInteger('baseline_attack_id')->nullable();
            $this->resourceColumns($table, 'baseline_');
            $this->resourceColumns($table, 'daily_net_');
            $table->char('economy_hash', 40)->nullable();
            $table->timestamp('economy_computed_at')->nullable();
            $table->decimal('retention_observed', 6, 4)->nullable();
            $table->unsignedSmallInteger('retention_samples')->default(0);
            $table->decimal('projected_value', 20, 2)->default(0)->index();
            $table->timestamp('projected_at')->nullable();
            $table->timestamp('dirty_at')->nullable()->index();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });

        WorldSchema::create('raid_alliance_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('alliance_id')->primary();
            $table->decimal('alliance_score', 14, 2)->default(0);
            foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                $table->decimal('bank_'.$resource, 20, 2)->nullable();
            }
            $table->timestamp('bank_evidence_at')->nullable();
            $table->unsignedBigInteger('bank_attack_id')->nullable();
            $table->unsignedInteger('raids_received_30d')->default(0);
            $table->unsignedInteger('countered_30d')->default(0);
            $table->decimal('counter_rate', 6, 4)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });

        WorldSchema::create('raid_model_parameters', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->unsignedInteger('sample_count')->default(0);
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        WorldSchema::dropIfExists('raid_model_parameters');
        WorldSchema::dropIfExists('raid_alliance_profiles');
        WorldSchema::dropIfExists('raid_target_profiles');
        WorldSchema::dropIfExists('raid_loot_events');
    }

    private function resourceColumns(Blueprint $table, string $prefix): void
    {
        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $table->decimal($prefix.$resource, 20, 2)->default(0);
        }
    }
};
