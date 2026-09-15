<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('raid_predictions')) {
            Schema::create('raid_predictions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('war_id')->unique();
                $table->unsignedBigInteger('attacker_nation_id')->index();
                $table->unsignedBigInteger('target_nation_id')->index();
                $table->unsignedBigInteger('attacker_alliance_id')->nullable()->index();
                $table->string('attacker_alliance_position', 24)->nullable();
                $table->timestamp('declared_at');
                $table->timestamp('captured_at');
                $table->timestamp('observed_at')->nullable();
                $table->string('capture_status', 24)->default('ready');
                $table->string('capture_reason', 255)->nullable();
                $table->string('evaluation_status', 24)->default('queued');
                $table->string('model_version', 64)->nullable();

                // These columns are a complete declaration-time input snapshot.
                $table->json('attacker_snapshot')->nullable();
                $table->json('target_snapshot')->nullable();
                $table->json('stockpile_snapshot')->nullable();
                $table->json('price_snapshot')->nullable();
                $table->json('context_snapshot')->nullable();
                $table->json('provenance')->nullable();
                $table->json('frozen_payload')->nullable();

                // Immutable evaluation result for the declaration snapshot.
                $table->decimal('expected_net', 20, 2)->nullable();
                $table->decimal('gross_loot', 20, 2)->nullable();
                $table->decimal('conservative_net', 20, 2)->nullable();
                $table->decimal('duration_hours', 12, 4)->nullable();
                $table->json('components')->nullable();
                $table->json('loot_resources')->nullable();
                $table->json('cost_resources')->nullable();
                $table->json('scenarios')->nullable();
                $table->json('simulation_payload')->nullable();
                $table->timestamp('evaluated_at')->nullable();

                // Reconciled outcome fields remain mutable as corrected/late attacks arrive.
                $table->string('outcome_status', 24)->default('open');
                $table->unsignedInteger('actual_attack_count')->default(0);
                $table->decimal('actual_gross_loot', 20, 2)->nullable();
                $table->decimal('actual_net', 20, 2)->nullable();
                $table->decimal('actual_duration_hours', 12, 4)->nullable();
                $table->json('actual_components')->nullable();
                $table->json('actual_loot_resources')->nullable();
                $table->json('actual_bank_loot')->nullable();
                $table->json('actual_cost_resources')->nullable();
                $table->json('actual_losses')->nullable();
                $table->json('outcome_metadata')->nullable();
                $table->timestamp('outcome_finalized_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['capture_status', 'evaluation_status'],
                    'raid_predictions_capture_evaluation_index',
                );
                $table->index(
                    ['outcome_status', 'declared_at'],
                    'raid_predictions_outcome_declared_index',
                );
            });
        }

        if (! Schema::hasTable('raid_outcome_attacks')) {
            Schema::create('raid_outcome_attacks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('raid_prediction_id')->nullable();
                $table->unsignedBigInteger('war_id');
                $table->unsignedBigInteger('attack_id');
                $table->unsignedBigInteger('attacker_nation_id')->nullable();
                $table->unsignedBigInteger('defender_nation_id')->nullable();
                $table->timestamp('attack_at')->nullable();
                $table->string('attack_type', 32)->nullable();
                $table->unsignedBigInteger('victor')->nullable();
                $table->unsignedTinyInteger('success')->nullable();
                $table->decimal('money_looted', 20, 2)->default(0);
                $table->decimal('money_stolen', 20, 2)->default(0);
                $table->decimal('money_destroyed', 20, 2)->default(0);
                $table->json('loot_resources')->nullable();
                $table->json('bank_loot')->nullable();
                $table->json('cost_resources')->nullable();
                $table->json('defender_cost_resources')->nullable();
                $table->json('casualties')->nullable();
                $table->json('defender_casualties')->nullable();
                $table->json('infrastructure')->nullable();
                $table->json('attacker_infrastructure')->nullable();
                $table->json('defender_infrastructure')->nullable();
                $table->json('payload')->nullable();
                $table->char('payload_hash', 64)->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->boolean('is_late')->default(false);
                $table->timestamp('observed_at');
                $table->timestamps();

                $table->unique(
                    ['attack_id'],
                    'raid_outcome_attacks_attack_unique',
                );
                $table->index(
                    ['raid_prediction_id', 'attack_at'],
                    'raid_outcome_attacks_prediction_date_index',
                );
                $table->index(
                    ['war_id', 'attack_at'],
                    'raid_outcome_attacks_war_date_index',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_outcome_attacks');
        Schema::dropIfExists('raid_predictions');
    }
};
