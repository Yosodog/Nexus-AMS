<?php

namespace Tests\Feature;

use App\DataTransferObjects\Raids\RaidFinderFilters;
use App\Events\WarDeclared;
use App\Listeners\CaptureRaidPredictionOnWarDeclared;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\RaidPrediction;
use App\Models\RaidTargetClaim;
use App\Models\RaidTargetProfile;
use App\Models\War;
use App\Services\RaidFinderService;
use App\Services\Raids\RaidValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\TestCase;

class RaidWorkflowTest extends TestCase
{
    use BuildsRaidFixtures;
    use RefreshDatabase;

    private Nation $attacker;

    private RaidTargetProfile $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        Cache::forever('alliances:membership:ids', [777]);
        $this->seedRaidMarketPrices(100);
        $this->attacker = Nation::factory()->create(['alliance_id' => 777, 'score' => 1_000, 'war_policy' => 'PIRATE']);
        NationMilitary::query()->create(['nation_id' => $this->attacker->id, 'soldiers' => 120_000, 'tanks' => 4_000]);
        $baseline = collect($this->raidResources(['money' => 12_000_000, 'steel' => 3_000]))
            ->mapWithKeys(fn ($amount, $resource) => ['baseline_'.$resource => $amount]);
        $dailyNet = collect($this->raidResources())->mapWithKeys(fn ($amount, $resource) => ['daily_net_'.$resource => 0]);
        $this->target = RaidTargetProfile::factory()->create([
            ...$baseline->all(),
            ...$dailyNet->all(),
            'score' => 1_100,
            'soldiers' => 2_000,
            'tanks' => 0,
            'war_policy' => 'TURTLE',
            'last_active' => now()->subDays(12),
            'baseline_at' => now()->subDays(2),
        ]);
    }

    public function test_declaration_freezes_the_valuation_the_member_saw_in_the_finder(): void
    {
        $shown = collect(app(RaidFinderService::class)->find($this->attacker->id, new RaidFinderFilters)->rows)
            ->firstWhere('nation.id', $this->target->nation_id);
        RaidTargetClaim::factory()->create(['target_nation_id' => $this->target->nation_id, 'nation_id' => $this->attacker->id]);
        $this->travel(10)->minutes();

        $prediction = $this->declare();

        $this->assertSame(RaidPrediction::CAPTURE_READY, $prediction->capture_status);
        $this->assertSame(RaidValuationService::MODEL_VERSION, $prediction->model_version);
        $this->assertSame($shown['valuation']['expected_net'], $prediction->expected_net);
        $this->assertSame($shown['valuation']['expected_net_low'], $prediction->expected_net_low);
        $this->assertSame($shown['valuation']['expected_net_high'], $prediction->expected_net_high);
        $this->assertSame($shown['valuation']['victory_probability'], $prediction->victory_probability);
        $this->assertSame($shown['valuation']['expected_attacks'], $prediction->expected_attacks);
        $this->assertSame($shown['valuation']['confidence'], $prediction->confidence);
        $this->assertSame(1, $prediction->finder_rank);
        $this->assertSame($shown['valuation']['expected_net'], $prediction->finder_expected_net);
        $this->assertTrue($prediction->observed_at->equalTo($this->target->baseline_at));
        $this->assertSame(120_000, $prediction->attacker_snapshot['soldiers']);
        $this->assertSame('inactive', $prediction->target_snapshot['activity_bucket']);
        $this->assertEquals($shown['valuation']['stockpile']['value'], $prediction->target_snapshot['stockpile']['value']);
        $this->assertSame(array_fill(0, $prediction->expected_attacks, 'ground'), $prediction->context_snapshot['plan']);
        $this->assertSame(0, $prediction->context_snapshot['competing_attackers']);
        $this->assertEquals(100, $prediction->price_snapshot['liquidation']['steel']);
        $this->assertSame(RaidTargetClaim::STATUS_DECLARED, RaidTargetClaim::query()->firstOrFail()->status);
    }

    public function test_capture_is_idempotent_and_immutable_after_world_changes(): void
    {
        $first = $this->declare();
        $this->target->update(['baseline_money' => 1]);

        $second = $this->declare();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->expected_net, $second->refresh()->expected_net);
        $this->assertSame(1, RaidPrediction::query()->count());
    }

    public function test_capture_without_target_intelligence_is_incomplete(): void
    {
        $this->target->update(['computed_at' => null]);

        $prediction = $this->declare();

        $this->assertSame(RaidPrediction::CAPTURE_INCOMPLETE, $prediction->capture_status);
        $this->assertSame('Target intelligence is unavailable.', $prediction->capture_reason);
        $this->assertNull($prediction->expected_net);
        $this->assertNull($prediction->target_snapshot);
    }

    public function test_capture_failure_keeps_an_incomplete_prediction(): void
    {
        $this->attacker->forceDelete();

        $prediction = $this->declare();

        $this->assertSame(RaidPrediction::CAPTURE_INCOMPLETE, $prediction->capture_status);
        $this->assertSame('Raid valuation failed.', $prediction->capture_reason);
    }

    public function test_declarations_without_a_recent_impression_are_not_linked_to_the_finder(): void
    {
        app(RaidFinderService::class)->find($this->attacker->id, new RaidFinderFilters);
        $this->travel(49)->hours();

        $this->assertNull($this->declare()->finder_rank);
    }

    private function declare(): RaidPrediction
    {
        $war = War::query()->firstOrCreate(['id' => 91], [
            'date' => now(), 'reason' => 'Raid workflow', 'war_type' => 'RAID', 'turns_left' => 60,
            'att_id' => $this->attacker->id, 'att_alliance_id' => 777, 'att_alliance_position' => 'MEMBER',
            'def_id' => $this->target->nation_id, 'def_alliance_id' => null, 'def_alliance_position' => 'NOALLIANCE',
        ]);

        app(CaptureRaidPredictionOnWarDeclared::class)->handle(new WarDeclared(
            warId: $war->id,
            attackerNationId: $this->attacker->id,
            attackerAllianceId: 777,
            attackerAlliancePosition: 'MEMBER',
            defenderNationId: $this->target->nation_id,
            defenderAllianceId: null,
            defenderAlliancePosition: 'NOALLIANCE',
        ));

        return RaidPrediction::query()->where('war_id', $war->id)->firstOrFail();
    }
}
