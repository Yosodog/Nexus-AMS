<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COUNTER_COLUMNS = [
        'soldiers',
        'tanks',
        'aircraft',
        'ships',
        'missiles',
        'nukes',
        'spies',
        'soldiers_today',
        'tanks_today',
        'aircraft_today',
        'ships_today',
        'missiles_today',
        'nukes_today',
        'spies_today',
        'soldier_casualties',
        'soldier_kills',
        'tank_casualties',
        'tank_kills',
        'aircraft_casualties',
        'aircraft_kills',
        'ship_casualties',
        'ship_kills',
        'missile_casualties',
        'missile_kills',
        'nuke_casualties',
        'nuke_kills',
        'spy_casualties',
        'spy_kills',
        'spy_attacks',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $alterations = array_map(
            static fn (string $column): string => "ALTER COLUMN `{$column}` SET DEFAULT 0",
            self::COUNTER_COLUMNS,
        );

        DB::statement('ALTER TABLE `nation_military` '.implode(', ', $alterations));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $alterations = array_map(
            static fn (string $column): string => "ALTER COLUMN `{$column}` DROP DEFAULT",
            self::COUNTER_COLUMNS,
        );

        DB::statement('ALTER TABLE `nation_military` '.implode(', ', $alterations));
    }
};
