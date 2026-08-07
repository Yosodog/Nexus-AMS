<?php

namespace App\Support\Database;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use Illuminate\Database\Schema\Blueprint;

final class WorldReference
{
    public static function alliance(
        Blueprint $table,
        string $column = 'alliance_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'alliances', $column, $constraintName);
    }

    public static function nation(
        Blueprint $table,
        string $column = 'nation_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'nations', $column, $constraintName);
    }

    public static function city(
        Blueprint $table,
        string $column = 'city_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'cities', $column, $constraintName);
    }

    public static function war(
        Blueprint $table,
        string $column = 'war_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'wars', $column, $constraintName);
    }

    public static function radiationSnapshot(
        Blueprint $table,
        string $column = 'radiation_snapshot_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'radiation_snapshots', $column, $constraintName);
    }

    public static function marketPriceSnapshot(
        Blueprint $table,
        string $column = 'market_price_snapshot_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to($table, 'market_price_snapshots', $column, $constraintName);
    }

    public static function to(
        Blueprint $table,
        string $referencedTable,
        string $column,
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        $runtime = app(RuntimeCapabilities::class)->runtime();

        return new WorldReferenceDefinition(
            table: $table,
            referencedTable: $referencedTable,
            column: $column,
            usesForeignKey: $runtime !== NexusRuntime::HostedTenant,
            constraintName: $constraintName,
        );
    }

    private function __construct() {}
}
