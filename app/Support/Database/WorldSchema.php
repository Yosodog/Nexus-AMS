<?php

namespace App\Support\Database;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Services\World\WorldModelManifest;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class WorldSchema
{
    /**
     * @param  Closure(Blueprint): void  $callback
     */
    public static function create(string $table, Closure $callback): void
    {
        self::assertWorldTable($table);

        if (self::usesPhysicalTables()) {
            Schema::create($table, $callback);
        }
    }

    /**
     * @param  Closure(Blueprint): void  $callback
     */
    public static function table(string $table, Closure $callback): void
    {
        self::assertWorldTable($table);

        if (self::usesPhysicalTables()) {
            Schema::table($table, $callback);
        }
    }

    public static function dropIfExists(string $table): void
    {
        self::assertWorldTable($table);

        if (self::usesPhysicalTables()) {
            Schema::dropIfExists($table);
        }
    }

    public static function usesPhysicalTables(): bool
    {
        return app(RuntimeCapabilities::class)->runtime() !== NexusRuntime::HostedTenant;
    }

    private static function assertWorldTable(string $table): void
    {
        if (! array_key_exists($table, WorldModelManifest::modelsByTable())) {
            throw new InvalidArgumentException('World schema helper received an unclassified table.');
        }
    }

    private function __construct() {}
}
