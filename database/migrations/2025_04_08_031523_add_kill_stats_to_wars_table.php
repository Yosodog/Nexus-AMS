<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::table('wars', function (Blueprint $table) {
            $table->unsignedInteger('att_soldiers_killed')->default(0);
            $table->unsignedInteger('def_soldiers_killed')->default(0);
            $table->unsignedInteger('att_tanks_killed')->default(0);
            $table->unsignedInteger('def_tanks_killed')->default(0);
            $table->unsignedInteger('att_aircraft_killed')->default(0);
            $table->unsignedInteger('def_aircraft_killed')->default(0);
            $table->unsignedInteger('att_ships_killed')->default(0);
            $table->unsignedInteger('def_ships_killed')->default(0);
        });
    }

    public function down(): void
    {
        WorldSchema::table('wars', function (Blueprint $table) {
            $table->dropColumn([
                'att_soldiers_killed',
                'def_soldiers_killed',
                'att_tanks_killed',
                'def_tanks_killed',
                'att_aircraft_killed',
                'def_aircraft_killed',
                'att_ships_killed',
                'def_ships_killed',
            ]);
        });
    }
};
