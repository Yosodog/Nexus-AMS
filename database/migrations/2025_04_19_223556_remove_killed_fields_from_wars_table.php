<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        WorldSchema::table('wars', function (Blueprint $table) {
            //
        });
    }
};
