<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raid_finder_impressions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attacker_nation_id');
            $table->unsignedBigInteger('target_nation_id');
            $table->unsignedSmallInteger('rank');
            $table->decimal('expected_net', 20, 2)->nullable();
            $table->timestamp('shown_at')->index();
            $table->timestamps();

            $table->unique(['attacker_nation_id', 'target_nation_id'], 'raid_finder_impressions_pair_unique');
        });

        Schema::create('raid_target_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('target_nation_id');
            $table->unsignedBigInteger('nation_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['target_nation_id', 'pending_key'], 'raid_target_claims_target_pending_unique');
            $table->index(['status', 'expires_at'], 'raid_target_claims_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_target_claims');
        Schema::dropIfExists('raid_finder_impressions');
    }
};
