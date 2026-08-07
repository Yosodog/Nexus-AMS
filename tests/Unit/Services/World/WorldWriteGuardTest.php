<?php

namespace Tests\Unit\Services\World;

use App\Enums\NexusRuntime;
use App\Exceptions\WorldWriteForbidden;
use App\Models\Alliance;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\MarketPriceSnapshotItem;
use App\Models\MarketTrade;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Models\TradePrice;
use App\Models\Treaty;
use App\Models\User;
use App\Models\War;
use App\Models\WarAttack;
use App\Providers\NexusRuntimeServiceProvider;
use App\Services\RuntimeCapabilities;
use App\Services\World\WorldModelManifest;
use App\Services\World\WorldWriteGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorldWriteGuardTest extends TestCase
{
    public function test_manifest_matches_the_frozen_public_world_model_boundary(): void
    {
        $expectedModelsByTable = [
            'alliances' => Alliance::class,
            'nations' => Nation::class,
            'cities' => City::class,
            'wars' => War::class,
            'war_attacks' => WarAttack::class,
            'treaties' => Treaty::class,
            'trade_prices' => TradePrice::class,
            'market_trades' => MarketTrade::class,
            'market_price_snapshots' => MarketPriceSnapshot::class,
            'market_price_snapshot_items' => MarketPriceSnapshotItem::class,
            'radiation_snapshots' => RadiationSnapshot::class,
        ];

        $this->assertSame(1, WorldModelManifest::CONTRACT_VERSION);
        $this->assertSame($expectedModelsByTable, WorldModelManifest::modelsByTable());
        $this->assertSame(array_values($expectedModelsByTable), WorldModelManifest::models());

        foreach ($expectedModelsByTable as $table => $modelClass) {
            $this->assertSame($table, (new $modelClass)->getTable());
        }
    }

    public function test_hosted_runtime_rejects_save_and_delete_for_every_world_model(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        $provider = new NexusRuntimeServiceProvider($this->app);
        $provider->boot($this->app->make(NexusRuntime::class));

        foreach (WorldModelManifest::modelsByTable() as $table => $modelClass) {
            $model = new $modelClass;
            $model->setRawAttributes([
                'id' => 123,
                'private_canary' => 'must-not-appear',
            ]);

            $this->assertWorldWriteIsForbidden(
                static fn (): bool => $model->save(),
                $table,
                $modelClass,
            );

            $model->exists = true;

            $this->assertWorldWriteIsForbidden(
                static fn (): ?bool => $model->delete(),
                $table,
                $modelClass,
            );
        }
    }

    #[DataProvider('worldWritingRuntimeProvider')]
    public function test_world_writing_runtimes_allow_every_classified_model(NexusRuntime $runtime): void
    {
        $guard = new WorldWriteGuard(new RuntimeCapabilities($runtime));

        foreach (WorldModelManifest::models() as $modelClass) {
            $guard->assertCanWrite($modelClass);
            $this->addToAssertionCount(1);
        }
    }

    public function test_guard_rejects_unclassified_models_even_in_a_world_writing_runtime(): void
    {
        $guard = new WorldWriteGuard(new RuntimeCapabilities(NexusRuntime::Standalone));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('World write guard received an unclassified model.');

        $guard->assertCanWrite(User::class);
    }

    /**
     * @return iterable<string, array{NexusRuntime}>
     */
    public static function worldWritingRuntimeProvider(): iterable
    {
        yield 'standalone' => [NexusRuntime::Standalone];
        yield 'temporary world writer' => [NexusRuntime::WorldWriter];
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->app->forgetInstance(WorldWriteGuard::class);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    /**
     * @param  callable(): mixed  $operation
     * @param  class-string  $modelClass
     */
    private function assertWorldWriteIsForbidden(callable $operation, string $table, string $modelClass): void
    {
        try {
            $operation();
            $this->fail("Hosted world write unexpectedly succeeded for [{$table}].");
        } catch (WorldWriteForbidden $exception) {
            $this->assertSame(
                'Public world data is read-only in hosted tenant mode.',
                $exception->getMessage(),
            );
            $this->assertSame('runtime.world_write_forbidden', $exception->errorCode());
            $this->assertStringNotContainsString($table, $exception->getMessage());
            $this->assertStringNotContainsString($modelClass, $exception->getMessage());
            $this->assertStringNotContainsString('must-not-appear', $exception->getMessage());
        }
    }
}
