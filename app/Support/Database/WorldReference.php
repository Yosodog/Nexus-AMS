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
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'alliances', $column, $constraintName, $indexInHosted);
    }

    public static function nation(
        Blueprint $table,
        string $column = 'nation_id',
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'nations', $column, $constraintName, $indexInHosted);
    }

    public static function nationPrimaryKey(
        Blueprint $table,
        string $column = 'nation_id',
        ?string $constraintName = null,
    ): WorldReferenceDefinition {
        return self::to(
            table: $table,
            referencedTable: 'nations',
            column: $column,
            constraintName: $constraintName,
            indexInHosted: false,
        )->primary();
    }

    public static function city(
        Blueprint $table,
        string $column = 'city_id',
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'cities', $column, $constraintName, $indexInHosted);
    }

    public static function war(
        Blueprint $table,
        string $column = 'war_id',
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'wars', $column, $constraintName, $indexInHosted);
    }

    public static function radiationSnapshot(
        Blueprint $table,
        string $column = 'radiation_snapshot_id',
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'radiation_snapshots', $column, $constraintName, $indexInHosted);
    }

    public static function marketPriceSnapshot(
        Blueprint $table,
        string $column = 'market_price_snapshot_id',
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        return self::to($table, 'market_price_snapshots', $column, $constraintName, $indexInHosted);
    }

    public static function to(
        Blueprint $table,
        string $referencedTable,
        string $column,
        ?string $constraintName = null,
        bool $indexInHosted = true,
    ): WorldReferenceDefinition {
        $runtime = app(RuntimeCapabilities::class)->runtime();

        return new WorldReferenceDefinition(
            table: $table,
            referencedTable: $referencedTable,
            column: $column,
            usesForeignKey: $runtime !== NexusRuntime::HostedTenant,
            constraintName: $constraintName,
            indexInHosted: $indexInHosted,
        );
    }

    public static function drop(Blueprint $table, string $column): void
    {
        if (app(RuntimeCapabilities::class)->runtime() === NexusRuntime::HostedTenant) {
            $table->dropIndex([$column]);

            return;
        }

        $table->dropForeign([$column]);
    }

    /**
     * @param  string|list<string>  $columns
     */
    public static function indexInHosted(Blueprint $table, string|array $columns, ?string $name = null): void
    {
        if (app(RuntimeCapabilities::class)->runtime() !== NexusRuntime::HostedTenant) {
            return;
        }

        if ($name === null) {
            $table->index($columns);

            return;
        }

        $table->index($columns, $name);
    }

    private function __construct() {}
}
