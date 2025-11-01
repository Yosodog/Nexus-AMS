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
            $table->unsignedBigInteger('alliance_id')->nullable()->index(); // Alliance relationship
            $table->enum('alliance_position', ['NOALLIANCE', 'APPLICANT', 'MEMBER', 'OFFICER', 'HEIR', 'LEADER']
            )->default('NOALLIANCE');
            $table->unsignedInteger('alliance_position_id');
            $table->string('nation_name')->index();
            $table->string('leader_name')->index();
            $table->string('continent', 2);
            $table->enum('war_policy',
                [
                    'ATTRITION',
                    'TURTLE',
                    'BLITZKRIEG',
                    'FORTRESS',
                    'MONEYBAGS',
                    'PIRATE',
                    'TACTICIAN',
                    'GUARDIAN',
                    'COVERT',
                    'ARCANE',
                ]
            )->default('ATTRITION');
            $table->unsignedSmallInteger('war_policy_turns');
            $table->enum('domestic_policy',
                [
                    'MANIFEST_DESTINY',
                    'OPEN_MARKETS',
                    'TECHNOLOGICAL_ADVANCEMENT',
                    'IMPERIALISM',
                    'URBANIZATION',
                    'RAPID_EXPANSION',
                ]
            )->default('MANIFEST_DESTINY');
            $table->unsignedSmallInteger('domestic_policy_turns');
            $table->string('color', 20)->index();
            $table->unsignedSmallInteger('num_cities');
            $table->float('score')->index();
            $table->tinyInteger('update_tz')->nullable();
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
            $table->string('project_bits'); // Storing project ownership as a bit sequence
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
            $table->float('money_looted');
            $table->float('total_infrastructure_destroyed');
            $table->float('total_infrastructure_lost');
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
