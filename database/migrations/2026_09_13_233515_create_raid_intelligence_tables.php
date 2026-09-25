<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical world tables. They were later removed from the world manifest and
 * dropped, so this migration uses Schema directly behind the physical-table guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! WorldSchema::usesPhysicalTables()) {
            return;
        }

        Schema::create('raid_nation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->timestamp('observed_at');
            $table->json('payload');
            $table->json('provenance_war_ids');
            $table->string('source', 32)->default('public_api');
            $table->timestamps();
            $table->index(['nation_id', 'observed_at']);
        });
        Schema::create('raid_attack_observations', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('war_id')->index();
            $table->unsignedBigInteger('att_id')->index();
            $table->unsignedBigInteger('def_id')->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('observed_at');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (! WorldSchema::usesPhysicalTables()) {
            return;
        }

        Schema::dropIfExists('raid_attack_observations');
        Schema::dropIfExists('raid_nation_observations');
    }
};
