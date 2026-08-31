<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MilitarySignIn;
use App\Console\Commands\SyncTreaties;
use App\Console\Commands\UpdateTradePrices;
use App\Enums\NexusRuntime;
use App\Exceptions\WorldWriteForbidden;
use App\Jobs\CreateAllianceJob;
use App\Jobs\CreateNationJob;
use App\Jobs\CreateWarAttackJob;
use App\Jobs\FinalizeAllianceSyncJob;
use App\Jobs\FinalizeNationSyncJob;
use App\Jobs\FinalizeWarSyncJob;
use App\Jobs\SyncAlliancesJob;
use App\Jobs\SyncNationsJob;
use App\Jobs\SyncWarsJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Jobs\UpdateWarJob;
use App\Services\AllianceMembershipService;
use App\Services\BeigeAlertService;
use App\Services\Economy\EconomyContextService;
use App\Services\Economy\MarketTradeIngestionService;
use App\Services\NationProfitabilityService;
use App\Services\QueryService;
use App\Services\RadiationService;
use App\Services\RuntimeCapabilities;
use App\Services\SignInService;
use App\Services\SubscriptionRecordQuarantine;
use App\Services\World\WorldWriteGuard;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HostedWorldWriteBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['nexus.runtime' => NexusRuntime::HostedTenant->value]);
        $this->app->forgetInstance(NexusRuntime::class);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(WorldWriteGuard::class);

        Http::preventStrayRequests();
    }

    #[DataProvider('rawWorldWriteEntryPointProvider')]
    public function test_hosted_runtime_rejects_raw_world_write_entry_points_before_external_io(
        string $entryPoint,
    ): void {
        $guard = $this->app->make(WorldWriteGuard::class);

        try {
            $this->invokeEntryPoint($entryPoint, $guard);
            $this->fail("Hosted world write entry point [{$entryPoint}] did not fail closed.");
        } catch (WorldWriteForbidden $exception) {
            $this->assertSame('runtime.world_write_forbidden', $exception->errorCode());
        }

        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rawWorldWriteEntryPointProvider(): iterable
    {
        yield 'nation page sync' => ['sync_nations'];
        yield 'alliance page sync' => ['sync_alliances'];
        yield 'war page sync' => ['sync_wars'];
        yield 'nation sync finalizer' => ['finalize_nations'];
        yield 'alliance sync finalizer' => ['finalize_alliances'];
        yield 'war sync finalizer' => ['finalize_wars'];
        yield 'war attack ingestion' => ['war_attack_ingestion'];
        yield 'treaty sync command' => ['sync_treaties'];
        yield 'trade price command' => ['update_trade_prices'];
        yield 'market trade ingestion' => ['market_trade_ingestion'];
        yield 'economy context sync' => ['economy_context'];
        yield 'radiation refresh' => ['radiation'];
        yield 'subscription nation create' => ['create_nation_subscription'];
        yield 'subscription nation update' => ['update_nation_subscription'];
        yield 'subscription alliance create' => ['create_alliance_subscription'];
        yield 'subscription alliance update' => ['update_alliance_subscription'];
        yield 'subscription city update' => ['update_city_subscription'];
        yield 'subscription war update' => ['update_war_subscription'];
    }

    public function test_hosted_military_sign_in_keeps_the_private_snapshot_without_updating_world_nations(): void
    {
        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('getAllianceIds')->once()->andReturn(collect([9_001]));
        $membershipService->shouldReceive('getPrimaryAllianceId')->once()->andReturn(9_000);
        $membershipService->shouldReceive('getCredentialsForAlliance')->once()->with(9_001)->andReturnNull();
        $signInService = Mockery::mock(SignInService::class);
        $signInService->shouldReceive('snapshotNation')->once()->andReturn(100);
        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldReceive('sendQuery')->once()->andReturn((object) [[
            'id' => 9_001,
            'name' => 'Hosted Alliance',
            'acronym' => 'HA',
            'score' => 1,
            'color' => 'blue',
            'average_score' => 1,
            'accept_members' => false,
            'rank' => 1,
            'nations' => [[
                'id' => 91_001,
                'nation_name' => 'Provider world name',
                'alliance_position' => 'MEMBER',
                'vacation_mode_turns' => 0,
            ]],
        ]]);
        $this->app->instance(AllianceMembershipService::class, $membershipService);
        $this->app->instance(SignInService::class, $signInService);
        $this->app->instance(QueryService::class, $queryService);

        $this->artisan(MilitarySignIn::class)
            ->expectsOutputToContain('Provider world name')
            ->assertSuccessful();
    }

    private function invokeEntryPoint(string $entryPoint, WorldWriteGuard $guard): void
    {
        match ($entryPoint) {
            'sync_nations' => (new SyncNationsJob(1, 100))->handle($guard),
            'sync_alliances' => (new SyncAlliancesJob(1, 100))->handle($guard),
            'sync_wars' => (new SyncWarsJob(1, 100))->handle($guard),
            'finalize_nations' => (new FinalizeNationSyncJob('hosted-boundary'))->handle($guard),
            'finalize_alliances' => (new FinalizeAllianceSyncJob('hosted-boundary'))->handle($guard),
            'finalize_wars' => (new FinalizeWarSyncJob('hosted-boundary'))->handle($guard),
            'war_attack_ingestion' => (new CreateWarAttackJob([['type' => 'GROUND']]))->handle(
                $this->app->make(SubscriptionRecordQuarantine::class),
                $guard,
            ),
            'sync_treaties' => $this->app->make(SyncTreaties::class)->handle($guard),
            'update_trade_prices' => $this->app->make(UpdateTradePrices::class)->handle($guard),
            'market_trade_ingestion' => $this->app->make(MarketTradeIngestionService::class)->refresh(),
            'economy_context' => $this->app->make(EconomyContextService::class)->refresh(),
            'radiation' => $this->app->make(RadiationService::class)->refresh(),
            'create_nation_subscription' => (new CreateNationJob([['id' => 91_001]]))->handle(
                $guard,
                $this->createMock(NationProfitabilityService::class),
            ),
            'update_nation_subscription' => (new UpdateNationJob([['id' => 91_001]]))->handle(
                $guard,
                $this->createMock(BeigeAlertService::class),
                $this->createMock(NationProfitabilityService::class),
            ),
            'create_alliance_subscription' => (new CreateAllianceJob(9_001))->handle($guard),
            'update_alliance_subscription' => (new UpdateAllianceJob([['id' => 9_001]]))->handle($guard),
            'update_city_subscription' => (new UpdateCityJob([['id' => 91_001]]))->handle(
                $guard,
                $this->createMock(NationProfitabilityService::class),
            ),
            'update_war_subscription' => (new UpdateWarJob([['id' => 91_001]]))->handle($guard),
            default => $this->fail("Unknown hosted world write entry point [{$entryPoint}]."),
        };
    }
}
