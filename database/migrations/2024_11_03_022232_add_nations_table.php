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
        Schema::create('nations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->nullable()->index(); // Index for quick joins
            $table->string('alliance_position', 50)->nullable();
            $table->unsignedInteger('alliance_position_id');
            $table->string('nation_name')->index(); // Index for search on nation name
            $table->string('leader_name')->index(); // Index for search on leader name
            $table->string('continent', 2);
            $table->unsignedSmallInteger('war_policy_turns');
            $table->unsignedSmallInteger('domestic_policy_turns');
            $table->string('color', 20)->index(); // Index for filtering by color
            $table->unsignedSmallInteger('num_cities');
            $table->float('score')->index(); // Index for sorting/filtering by score
            $table->unsignedTinyInteger('update_tz')->nullable();
            $table->unsignedInteger('population');
            $table->string('flag')->nullable();
            $table->unsignedSmallInteger('vacation_mode_turns');
            $table->unsignedSmallInteger('beige_turns');
            $table->boolean('espionage_available');
            $table->string('discord')->nullable();
            $table->string('discord_id')->nullable();
            $table->unsignedSmallInteger('turns_since_last_city');
            $table->unsignedSmallInteger('turns_since_last_project');
            $table->unsignedTinyInteger('projects');
            $table->string('project_bits'); // Storing project ownership as a bit sequence. Stores as string just in case lol
            $table->unsignedInteger('wars_won');
            $table->unsignedInteger('wars_lost');
            $table->unsignedInteger('tax_id')->nullable();
            $table->unsignedInteger('alliance_seniority');
            $table->float('gross_national_income');
            $table->float('gross_domestic_product');
            $table->boolean('vip');
            $table->unsignedSmallInteger('commendations');
            $table->unsignedSmallInteger('denouncements');
            $table->unsignedInteger('offensive_wars_count');
            $table->unsignedInteger('defensive_wars_count');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nations');
    }
};
