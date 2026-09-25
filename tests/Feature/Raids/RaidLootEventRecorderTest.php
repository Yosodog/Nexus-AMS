<?php

namespace Tests\Feature\Raids;

use App\Events\WarAttackRecorded;
use App\Jobs\CreateWarAttackJob;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\RaidAllianceProfile;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidLootEventRecorder;
use App\Services\SubscriptionRecordQuarantine;
use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\World\WorldWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\TestCase;

class RaidLootEventRecorderTest extends TestCase
{
    use BuildsRaidFixtures;
    use RefreshDatabase;

    private Nation $winner;

    private Nation $loser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        $this->seedRaidMarketPrices(100);
        $this->winner = Nation::factory()->create(['alliance_id' => null, 'war_policy' => 'PIRATE', 'score' => 2_000]);
        $this->loser = Nation::factory()->create(['alliance_id' => Alliance::factory()->create(['score' => 60_000])->id, 'war_policy' => 'TURTLE']);
    }

    public function test_non_member_victory_is_recorded_with_a_modifier_fraction(): void
    {
        $event = $this->recorder()->record($this->victory(['money_looted' => 1_400_000, 'steel_looted' => 70]));

        $this->assertNotNull($event);
        $this->assertSame(RaidLootEvent::KIND_VICTORY, $event->kind);
        $this->assertSame($this->winner->id, $event->winner_nation_id);
        $this->assertSame($this->loser->id, $event->loser_nation_id);
        $this->assertSame((int) $this->loser->alliance_id, $event->loser_alliance_id);
        $this->assertSame('ORDINARY', $event->war_type);
        $this->assertSame('modifiers', $event->fraction_source);
        $this->assertEqualsWithDelta(0.07, $event->loot_fraction, 1e-9);
        $this->assertSame(1_400_000.0, $event->money);
        $this->assertSame(70.0, $event->steel);
    }

    public function test_loot_report_fraction_takes_precedence(): void
    {
        $event = $this->recorder()->record($this->victory(['loot_info' => 'The victor looted 12.5% of the resources.']));

        $this->assertSame('report', $event->fraction_source);
        $this->assertEqualsWithDelta(0.125, $event->loot_fraction, 1e-9);
    }

    public function test_ground_attacks_are_ignored(): void
    {
        $this->assertNull($this->recorder()->record($this->victory(['type' => 'GROUND'])));
        $this->assertDatabaseCount('raid_loot_events', 0);
    }

    public function test_redelivery_is_idempotent_and_never_overwrites_the_backtest(): void
    {
        $profile = RaidTargetProfile::factory()->create([
            'nation_id' => $this->loser->id,
            'last_active' => now()->subDays(10),
            'baseline_at' => now()->subDays(2),
            ...collect($this->raidResources(['money' => 5_000_000]))->mapWithKeys(fn ($amount, $resource) => ['baseline_'.$resource => $amount])->all(),
            ...collect($this->raidResources(['money' => 100_000]))->mapWithKeys(fn ($amount, $resource) => ['daily_net_'.$resource => $amount])->all(),
        ]);

        $first = $this->recorder()->record($this->victory(['money_looted' => 700_000]));

        $this->assertSame(5_200_000.0, $first->predicted_value);
        $this->assertSame(10_000_000.0, $first->revealed_value);
        $this->assertSame(RaidTargetProfile::BASELINE_LOOT, $first->prediction_evidence_kind);
        $this->assertSame(48.0, $first->prediction_age_hours);
        $this->assertSame('inactive', $first->prediction_activity_bucket);

        $profile->update(['baseline_money' => 1]);
        $second = $this->recorder()->record($this->victory(['money_looted' => 800_000]));

        $this->assertDatabaseCount('raid_loot_events', 1);
        $this->assertSame(800_000.0, $second->money);
        $this->assertSame(5_200_000.0, $second->refresh()->predicted_value);
        $this->assertSame(10_000_000.0, $second->revealed_value);
    }

    public function test_winner_and_loser_profiles_are_marked_dirty(): void
    {
        $this->recorder()->record($this->victory());

        $this->assertNotNull(RaidTargetProfile::query()->find($this->winner->id)?->dirty_at);
        $this->assertNotNull(RaidTargetProfile::query()->find($this->loser->id)?->dirty_at);
        $this->assertNull(RaidTargetProfile::query()->find($this->loser->id)?->computed_at);
    }

    public function test_alliance_loot_updates_the_bank_estimate(): void
    {
        $event = $this->recorder()->record($this->victory(['id' => 5002, 'type' => 'ALLIANCELOOT', 'money_looted' => 1_000, 'food_looted' => 50]));
        $expected = RaidLootFormula::expectedBankLootFraction(2_000, 60_000, 0.5 * 1.4);
        $bank = RaidAllianceProfile::query()->findOrFail($this->loser->alliance_id);

        $this->assertNull($event->loot_fraction);
        $this->assertSame('default', $event->fraction_source);
        $this->assertEqualsWithDelta(1_000 / $expected - 1_000, $bank->bank_money, 0.01);
        $this->assertEqualsWithDelta(50 / $expected - 50, $bank->bank_food, 0.01);
        $this->assertSame(0.0, $bank->bank_steel);
        $this->assertSame(5002, $bank->bank_attack_id);
        $this->assertSame(60_000.0, $bank->alliance_score);
    }

    public function test_newer_bank_evidence_is_not_replaced_by_an_older_attack(): void
    {
        RaidAllianceProfile::factory()->withBank(['money' => 42])->create([
            'alliance_id' => $this->loser->alliance_id,
            'bank_evidence_at' => now()->addDay(),
        ]);

        $this->recorder()->record($this->victory(['type' => 'ALLIANCELOOT', 'money_looted' => 1_000]));

        $this->assertSame(42.0, RaidAllianceProfile::query()->findOrFail($this->loser->alliance_id)->bank_money);
    }

    public function test_create_war_attack_job_records_non_member_victories_but_skips_storing_the_attack(): void
    {
        Event::fake([WarAttackRecorded::class]);
        Cache::forever('alliances:membership:ids', [987_654]);

        (new CreateWarAttackJob([$this->victory(['id' => 6001, 'money_looted' => 900])]))->handle(
            app(SubscriptionRecordQuarantine::class),
            app(WorldWriteGuard::class),
        );

        $this->assertDatabaseHas('raid_loot_events', ['id' => 6001, 'loser_nation_id' => $this->loser->id]);
        $this->assertDatabaseMissing('war_attacks', ['id' => 6001]);
        Event::assertNotDispatched(WarAttackRecorded::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function victory(array $overrides = []): array
    {
        return [
            'id' => 5001,
            'war_id' => 4001,
            'date' => now()->toIso8601String(),
            'att_id' => $this->winner->id,
            'def_id' => $this->loser->id,
            'type' => 'VICTORY',
            'victor' => $this->winner->id,
            'money_looted' => 100_000,
            ...$overrides,
        ];
    }

    private function recorder(): RaidLootEventRecorder
    {
        $this->app->forgetScopedInstances();

        return app(RaidLootEventRecorder::class);
    }
}
