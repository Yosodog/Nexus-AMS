<?php

namespace Tests\Feature\API;

use App\Events\NationAllianceChanged;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Jobs\UpdateNationJob;
use App\Models\Nation;
use App\Services\BeigeAlertService;
use App\Services\NationProfitabilityService;
use App\Services\PWHelperService;
use App\Services\World\WorldWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'treasure_income_modifier' => 0.05,
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => now(),
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
        ]]))->handle(
            app(WorldWriteGuard::class),
            $beigeAlertService,
            $profitabilityService,
        );

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
        $this->assertNull($nation->treasure_income_modifier);
        $this->assertNull($nation->color_turn_bonus);
        $this->assertNull($nation->economy_context_synced_at);

        Event::assertDispatchedTimes(NationAllianceChanged::class, 1);
        Event::assertDispatched(
            NationAllianceChanged::class,
            fn (NationAllianceChanged $event): bool => $event->nation->is($nation)
                && $event->oldAllianceId === 777
                && $event->newAllianceId === null
                && $event->newAlliancePosition === 'NOALLIANCE'
        );
    }

    public function test_partial_research_updates_preserve_omitted_research_fields(): void
    {
        $nation = Nation::factory()->create([
            'ground_capacity_research' => 9,
            'ground_cost_research' => 4,
        ]);
        $payload = new GraphQLNation;
        $payload->buildWithJSON((object) [
            'id' => $nation->id,
            'military_research' => (object) [
                'ground_cost' => 12,
            ],
        ]);

        Nation::updateFromAPI($payload);
        $nation->refresh();

        $this->assertSame(9, $nation->ground_capacity_research);
        $this->assertSame(12, $nation->ground_cost_research);
    }

    public function test_explicit_project_flag_corrects_the_stored_project_mask(): void
    {
        $nation = Nation::factory()->create(['project_bits' => '0']);
        $payload = new GraphQLNation;
        $payload->buildWithJSON((object) [
            'id' => $nation->id,
            'uranium_enrichment_program' => true,
        ]);

        Nation::updateFromAPI($payload);
        $nation->refresh();

        $this->assertContains(
            'Uranium Enrichment Program',
            PWHelperService::getNationProjects($nation->project_bits),
        );
    }

    public function test_partial_military_payload_creates_missing_snapshot_with_zero_defaults(): void
    {
        $nation = Nation::factory()->create();
        $payload = new GraphQLNation;
        $payload->buildWithJSON((object) [
            'id' => $nation->id,
            'soldiers' => 1234,
        ]);

        Nation::updateFromAPI($payload);

        $military = $nation->military()->firstOrFail();
        $this->assertSame(1234, $military->soldiers);
        $this->assertSame(0, $military->soldiers_today);
        $this->assertSame(0, $military->spy_attacks);
    }

    public function test_database_defaults_allow_partial_military_inserts(): void
    {
        $nation = Nation::factory()->create();

        DB::table('nation_military')->insert([
            'nation_id' => $nation->id,
        ]);

        $this->assertDatabaseHas('nation_military', [
            'nation_id' => $nation->id,
            'soldiers' => 0,
            'soldiers_today' => 0,
            'spy_attacks' => 0,
        ]);
    }
}
