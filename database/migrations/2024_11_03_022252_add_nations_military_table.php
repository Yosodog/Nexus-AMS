<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nation_military', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->unique()->index()->constrained('nations')->onDelete('cascade');
            $table->unsignedInteger('soldiers');
            $table->unsignedInteger('tanks');
            $table->unsignedInteger('aircraft');
            $table->unsignedInteger('ships');
            $table->unsignedInteger('missiles');
            $table->unsignedInteger('nukes');
            $table->unsignedInteger('spies');
            $table->unsignedInteger('soldiers_today');
            $table->unsignedInteger('tanks_today');
            $table->unsignedInteger('aircraft_today');
            $table->unsignedInteger('ships_today');
            $table->unsignedTinyInteger('missiles_today');
            $table->unsignedTinyInteger('nukes_today');
            $table->unsignedTinyInteger('spies_today');
            $table->unsignedInteger('soldier_casualties');
            $table->unsignedInteger('soldier_kills');
            $table->unsignedInteger('tank_casualties');
            $table->unsignedInteger('tank_kills');
            $table->unsignedInteger('aircraft_casualties');
            $table->unsignedInteger('aircraft_kills');
            $table->unsignedInteger('ship_casualties');
            $table->unsignedInteger('ship_kills');
            $table->unsignedInteger('missile_casualties');
            $table->unsignedInteger('missile_kills');
            $table->unsignedInteger('nuke_casualties');
            $table->unsignedInteger('nuke_kills');
            $table->unsignedInteger('spy_casualties');
            $table->unsignedInteger('spy_kills');
            $table->unsignedInteger('spy_attacks');
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
