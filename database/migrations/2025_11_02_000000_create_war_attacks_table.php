<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('war_attacks', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->timestamp('date')->nullable();
            $table->unsignedBigInteger('att_id');
            $table->unsignedBigInteger('def_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('war_id')->index();
            $table->unsignedBigInteger('victor')->nullable();
            $table->unsignedTinyInteger('success')->nullable();
            $table->decimal('attcas1', 12, 2)->nullable();
            $table->decimal('defcas1', 12, 2)->nullable();
            $table->decimal('attcas2', 12, 2)->nullable();
            $table->decimal('defcas2', 12, 2)->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->decimal('infra_destroyed', 12, 2)->default(0);
            $table->unsignedInteger('improvements_lost')->default(0);
            $table->decimal('money_stolen', 18, 2)->default(0);
            $table->text('note')->nullable();
            $table->decimal('city_infra_before', 12, 2)->nullable();
            $table->decimal('infra_destroyed_value', 18, 2)->default(0);
            $table->decimal('att_mun_used', 12, 2)->default(0);
            $table->decimal('def_mun_used', 12, 2)->default(0);
            $table->decimal('att_gas_used', 12, 2)->default(0);
            $table->decimal('def_gas_used', 12, 2)->default(0);
            $table->decimal('money_destroyed', 18, 2)->default(0);
            $table->unsignedInteger('resistance_lost')->default(0);
            $table->decimal('military_salvage_aluminum', 12, 2)->default(0);
            $table->decimal('military_salvage_steel', 12, 2)->default(0);
            $table->unsignedInteger('att_soldiers_used')->default(0);
            $table->unsignedInteger('att_soldiers_lost')->default(0);
            $table->unsignedInteger('def_soldiers_used')->default(0);
            $table->unsignedInteger('def_soldiers_lost')->default(0);
            $table->unsignedInteger('att_tanks_used')->default(0);
            $table->unsignedInteger('att_tanks_lost')->default(0);
            $table->unsignedInteger('def_tanks_used')->default(0);
            $table->unsignedInteger('def_tanks_lost')->default(0);
            $table->unsignedInteger('att_aircraft_used')->default(0);
            $table->unsignedInteger('att_aircraft_lost')->default(0);
            $table->unsignedInteger('def_aircraft_used')->default(0);
            $table->unsignedInteger('def_aircraft_lost')->default(0);
            $table->unsignedInteger('att_ships_used')->default(0);
            $table->unsignedInteger('att_ships_lost')->default(0);
            $table->unsignedInteger('def_ships_used')->default(0);
            $table->unsignedInteger('def_ships_lost')->default(0);
            $table->unsignedInteger('att_missiles_lost')->default(0);
            $table->unsignedInteger('def_missiles_lost')->default(0);
            $table->unsignedInteger('att_nukes_lost')->default(0);
            $table->unsignedInteger('def_nukes_lost')->default(0);
            $table->json('improvements_destroyed')->nullable();
            $table->decimal('infra_destroyed_percentage', 5, 2)->nullable();
            $table->json('cities_infra_before')->nullable();
            $table->decimal('money_looted', 18, 2)->default(0);
            $table->decimal('coal_looted', 12, 2)->default(0);
            $table->decimal('oil_looted', 12, 2)->default(0);
            $table->decimal('uranium_looted', 12, 2)->default(0);
            $table->decimal('iron_looted', 12, 2)->default(0);
            $table->decimal('bauxite_looted', 12, 2)->default(0);
            $table->decimal('lead_looted', 12, 2)->default(0);
            $table->decimal('gasoline_looted', 12, 2)->default(0);
            $table->decimal('munitions_looted', 12, 2)->default(0);
            $table->decimal('steel_looted', 12, 2)->default(0);
            $table->decimal('aluminum_looted', 12, 2)->default(0);
            $table->decimal('food_looted', 12, 2)->default(0);
            $table->string('loot_info')->nullable();
            $table->unsignedInteger('resistance_eliminated')->nullable();
            $table->timestamps();

            $table->foreign('att_id')
                ->references('id')
                ->on('nations')
                ->cascadeOnDelete();

            $table->foreign('def_id')
                ->references('id')
                ->on('nations')
                ->cascadeOnDelete();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('war_attacks');
    }
};
