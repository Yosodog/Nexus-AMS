<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id');
            $table->unsignedSmallInteger('num_cities');
            $table->double('score');
            $table->unsignedInteger('wars_won');
            $table->unsignedInteger('wars_lost');
            $table->double('total_infrastructure_destroyed');
            $table->double('total_infrastructure_lost');

            // Military - current
            $table->unsignedInteger('soldiers');
            $table->unsignedInteger('tanks');
            $table->unsignedInteger('aircraft');
            $table->unsignedInteger('ships');
            $table->unsignedInteger('missiles');
            $table->unsignedInteger('nukes');
            $table->unsignedInteger('spies');

            // Military - kills/losses
            $table->unsignedInteger('soldier_kills');
            $table->unsignedInteger('soldier_casualties');
            $table->unsignedInteger('tank_kills');
            $table->unsignedInteger('tank_casualties');
            $table->unsignedInteger('aircraft_kills');
            $table->unsignedInteger('aircraft_casualties');
            $table->unsignedInteger('ship_kills');
            $table->unsignedInteger('ship_casualties');
            $table->unsignedInteger('missile_kills');
            $table->unsignedInteger('missile_casualties');
            $table->unsignedInteger('nuke_kills');
            $table->unsignedInteger('nuke_casualties');
            $table->unsignedInteger('spy_kills');
            $table->unsignedInteger('spy_casualties');

            // Resources
            $table->double('money');
            $table->double('coal');
            $table->double('oil');
            $table->double('uranium');
            $table->double('iron');
            $table->double('bauxite');
            $table->double('lead');
            $table->double('gasoline');
            $table->double('munitions');
            $table->double('steel');
            $table->double('aluminum');
            $table->double('food');
            $table->unsignedInteger('credits');

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_sign_ins');
    }
};
