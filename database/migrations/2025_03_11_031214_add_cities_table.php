<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->onDelete('cascade');
            $table->string('name');
            $table->date('date');
            $table->float('infrastructure');
            $table->float('land');
            $table->boolean('powered');
            $table->integer('oil_power');
            $table->integer('wind_power');
            $table->integer('coal_power');
            $table->integer('nuclear_power');
            $table->integer('coal_mine');
            $table->integer('oil_well');
            $table->integer('uranium_mine');
            $table->integer('barracks');
            $table->integer('farm');
            $table->integer('police_station');
            $table->integer('hospital');
            $table->integer('recycling_center');
            $table->integer('subway');
            $table->integer('supermarket');
            $table->integer('bank');
            $table->integer('shopping_mall');
            $table->integer('stadium');
            $table->integer('lead_mine');
            $table->integer('iron_mine');
            $table->integer('bauxite_mine');
            $table->integer('oil_refinery');
            $table->integer('aluminum_refinery');
            $table->integer('steel_mill');
            $table->integer('munitions_factory');
            $table->integer('factory');
            $table->integer('hangar');
            $table->integer('drydock');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cities');
    }
};
