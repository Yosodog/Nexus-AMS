<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\AlliancePositionEnum;
use App\Enums\WarTypeEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wars', function (Blueprint $table) {
            $table->id();
            $table->timestamp('date')->useCurrent();
            $table->timestamp('end_date')->nullable();

            $table->string('reason');
            $table->enum('war_type', WarTypeEnum::values())->default(WarTypeEnum::ORDINARY->value);

            $table->unsignedBigInteger('ground_control')->nullable();
            $table->unsignedBigInteger('air_superiority')->nullable();
            $table->unsignedBigInteger('naval_blockade')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();

            $table->unsignedInteger('turns_left')->default(0);

            // Attacker
            $table->unsignedBigInteger('att_id');
            $table->unsignedBigInteger('att_alliance_id')->nullable();
            $table->enum('att_alliance_position', AlliancePositionEnum::values())->default(AlliancePositionEnum::NOALLIANCE->value);

            // Defender
            $table->unsignedBigInteger('def_id');
            $table->unsignedBigInteger('def_alliance_id')->nullable();
            $table->enum('def_alliance_position', AlliancePositionEnum::values())->default(AlliancePositionEnum::NOALLIANCE->value);

            // Points
            $table->unsignedInteger('att_points')->default(0);
            $table->unsignedInteger('def_points')->default(0);

            // Peace flags
            $table->boolean('att_peace')->default(false);
            $table->boolean('def_peace')->default(false);

            // Resistance
            $table->unsignedInteger('att_resistance')->default(0);
            $table->unsignedInteger('def_resistance')->default(0);

            // Fortify
            $table->boolean('att_fortify')->default(false);
            $table->boolean('def_fortify')->default(false);

            // Resource usage
            $table->float('att_gas_used')->default(0);
            $table->float('def_gas_used')->default(0);
            $table->float('att_mun_used')->default(0);
            $table->float('def_mun_used')->default(0);
            $table->float('att_alum_used')->default(0);
            $table->float('def_alum_used')->default(0);
            $table->float('att_steel_used')->default(0);
            $table->float('def_steel_used')->default(0);

            // Infrastructure & money damage
            $table->float('att_infra_destroyed')->default(0);
            $table->float('def_infra_destroyed')->default(0);
            $table->float('att_money_looted')->default(0);
            $table->float('def_money_looted')->default(0);

            // Unit losses
            $table->unsignedInteger('def_soldiers_lost')->default(0);
            $table->unsignedInteger('att_soldiers_lost')->default(0);
            $table->unsignedInteger('def_tanks_lost')->default(0);
            $table->unsignedInteger('att_tanks_lost')->default(0);
            $table->unsignedInteger('def_aircraft_lost')->default(0);
            $table->unsignedInteger('att_aircraft_lost')->default(0);
            $table->unsignedInteger('def_ships_lost')->default(0);
            $table->unsignedInteger('att_ships_lost')->default(0);

            // Missiles & nukes
            $table->unsignedInteger('att_missiles_used')->default(0);
            $table->unsignedInteger('def_missiles_used')->default(0);
            $table->unsignedInteger('att_nukes_used')->default(0);
            $table->unsignedInteger('def_nukes_used')->default(0);

            // Infra destruction value (money equivalent)
            $table->float('att_infra_destroyed_value')->default(0);
            $table->float('def_infra_destroyed_value')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wars');
    }
};
