<?php

namespace Tests\Feature\API;

use App\Events\NationAllianceChanged;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Jobs\UpdateNationJob;
use App\Models\Nation;
use App\Services\BeigeAlertService;
use App\Services\NationProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class NationSubscriptionNullTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydration_distinguishes_missing_fields_from_explicit_nulls(): void
    {
        $nation = new GraphQLNation;

        $nation->buildWithJSON((object) [
            'id' => 7001,
            'alliance_id' => null,
        ]);

        $this->assertTrue($nation->hasSourceField('alliance_id'));
        $this->assertFalse($nation->hasSourceField('nation_name'));
        $this->assertNull($nation->alliance_id);
        $this->assertNull($nation->nation_name);
    }

    public function test_explicit_null_alliance_update_persists_departure_and_preserves_missing_fields(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'alliance_seniority' => 120,
            'tax_id' => 88,
            'discord_id' => '1234567890',
            'flag' => 'https://example.test/original-flag.png',
            'vip' => true,
            'commendations' => 13,
        ]);
        Event::fake([NationAllianceChanged::class]);

        $beigeAlertService = Mockery::mock(BeigeAlertService::class);
        $beigeAlertService->shouldReceive('maybeDispatchEarlyExitAlert')
            ->once()
            ->withArgs(fn (
                int $nationId,
                ?int $allianceId,
                int $previousBeigeTurns,
                int $currentBeigeTurns
            ): bool => $nationId === $nation->id && $allianceId === null);

        $profitabilityService = Mockery::mock(NationProfitabilityService::class);
        $profitabilityService->shouldReceive('shouldStoreSnapshotForNation')
            ->once()
            ->andReturnFalse();
        $profitabilityService->shouldReceive('deleteStoredSnapshotForNationId')
            ->once()
            ->with($nation->id);

        (new UpdateNationJob([[
            'id' => $nation->id,
            'alliance_id' => null,
            'discord_id' => null,
        ]]))->handle($beigeAlertService, $profitabilityService);

        $nation->refresh();

        $this->assertNull($nation->alliance_id);
        $this->assertSame('NOALLIANCE', $nation->alliance_position);
        $this->assertSame(0, $nation->alliance_position_id);
        $this->assertSame(0, $nation->alliance_seniority);
        $this->assertNull($nation->tax_id);
        $this->assertNull($nation->discord_id);
        $this->assertSame('https://example.test/original-flag.png', $nation->flag);
        $this->assertTrue((bool) $nation->vip);
        $this->assertSame(13, $nation->commendations);

        Event::assertDispatchedTimes(NationAllianceChanged::class, 1);
        Event::assertDispatched(
            NationAllianceChanged::class,
            fn (NationAllianceChanged $event): bool => $event->nation->is($nation)
                && $event->oldAllianceId === 777
                && $event->newAllianceId === null
                && $event->newAlliancePosition === 'NOALLIANCE'
        );
    }
}
