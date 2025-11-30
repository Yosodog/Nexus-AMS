<?php

use App\Enums\SpyAssignmentStatus;
use App\Enums\SpyCampaignAllianceRole;
use App\Enums\SpyCampaignStatus;
use App\Enums\SpyOperationType;
use App\Enums\SpyRoundStatus;
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
        Schema::create('spy_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', SpyCampaignStatus::values())->default(SpyCampaignStatus::DRAFT->value)->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('spy_campaign_alliances', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\SpyCampaign::class, 'spy_campaign_id')->constrained('spy_campaigns')->cascadeOnDelete();
            $table->foreignIdFor(Alliance::class, 'alliance_id')->constrained('alliances')->cascadeOnDelete();
            $table->enum('role', SpyCampaignAllianceRole::values())->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['spy_campaign_id', 'alliance_id', 'role'], 'spy_campaign_alliance_unique');
        });

        Schema::create('spy_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\SpyCampaign::class, 'spy_campaign_id')->constrained('spy_campaigns')->cascadeOnDelete();
            $table->unsignedTinyInteger('round_number');
            $table->enum('op_type', SpyOperationType::values())->index();
            $table->decimal('min_success_chance', 5, 2)->nullable();
            $table->enum('status', SpyRoundStatus::values())->default(SpyRoundStatus::DRAFT->value)->index();
            $table->json('results')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
            $table->unique(['spy_campaign_id', 'round_number'], 'spy_round_unique_number');
        });

        Schema::create('spy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\SpyRound::class, 'spy_round_id')->constrained('spy_rounds')->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'attacker_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'defender_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->enum('op_type', SpyOperationType::values());
            $table->unsignedTinyInteger('safety_level')->default(1);
            $table->decimal('calculated_odds', 5, 2)->default(0);
            $table->decimal('expected_impact', 8, 2)->default(0);
            $table->decimal('policy_synergy', 5, 2)->default(0);
            $table->decimal('final_score_used_for_sorting', 10, 4)->default(0)->index();
            $table->enum('status', SpyAssignmentStatus::values())->default(SpyAssignmentStatus::PENDING->value)->index();
            $table->boolean('low_odds_flag')->default(false);
            $table->timestamps();
            $table->index(['spy_round_id', 'attacker_nation_id']);
            $table->index(['spy_round_id', 'defender_nation_id']);
            $table->unique(['spy_round_id', 'attacker_nation_id', 'defender_nation_id'], 'spy_assignment_unique_pair');
        });

        Schema::create('spy_assignment_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\SpyRound::class, 'spy_round_id')->constrained('spy_rounds')->cascadeOnDelete();
            $table->foreignIdFor(Nation::class, 'attacker_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->string('message_hash');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['spy_round_id', 'attacker_nation_id', 'message_hash'], 'spy_assignment_message_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spy_assignment_message_logs');
        Schema::dropIfExists('spy_assignments');
        Schema::dropIfExists('spy_rounds');
        Schema::dropIfExists('spy_campaign_alliances');
        Schema::dropIfExists('spy_campaigns');
    }
};
