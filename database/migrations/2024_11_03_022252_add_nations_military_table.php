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
        Schema::create('nation_military', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->unique()->index()->constrained('nations')->onDelete('cascade');
            $table->unsignedInteger('soldiers')->default(0);
            $table->unsignedInteger('tanks')->default(0);
            $table->unsignedInteger('aircraft')->default(0);
            $table->unsignedInteger('ships')->default(0);
            $table->unsignedInteger('missiles')->default(0);
            $table->unsignedInteger('nukes')->default(0);
            $table->unsignedInteger('spies')->default(0);
            $table->unsignedInteger('soldiers_today')->default(0);
            $table->unsignedInteger('tanks_today')->default(0);
            $table->unsignedInteger('aircraft_today')->default(0);
            $table->unsignedInteger('ships_today')->default(0);
            $table->unsignedTinyInteger('missiles_today')->default(0);
            $table->unsignedTinyInteger('nukes_today')->default(0);
            $table->unsignedTinyInteger('spies_today')->default(0);
            $table->unsignedInteger('soldier_casualties')->default(0);
            $table->unsignedInteger('soldier_kills')->default(0);
            $table->unsignedInteger('tank_casualties')->default(0);
            $table->unsignedInteger('tank_kills')->default(0);
            $table->unsignedInteger('aircraft_casualties')->default(0);
            $table->unsignedInteger('aircraft_kills')->default(0);
            $table->unsignedInteger('ship_casualties')->default(0);
            $table->unsignedInteger('ship_kills')->default(0);
            $table->unsignedInteger('missile_casualties')->default(0);
            $table->unsignedInteger('missile_kills')->default(0);
            $table->unsignedInteger('nuke_casualties')->default(0);
            $table->unsignedInteger('nuke_kills')->default(0);
            $table->unsignedInteger('spy_casualties')->default(0);
            $table->unsignedInteger('spy_kills')->default(0);
            $table->unsignedInteger('spy_attacks')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nation_military');
    }
};
