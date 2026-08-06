<?php

namespace Tests\Unit\Services;

use App\Models\Alliance;
use App\Models\NoRaidList;
use App\Models\Treaty;
use App\Services\AllianceMembershipService;
use App\Services\RaidPolicyService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 0);
        app(AllianceMembershipService::class)->clear();
        SettingService::setTopRaidable(1);
    }

    public function test_alliance_outside_durable_protections_is_allowed(): void
    {
        Alliance::factory()->create(['name' => 'Protected', 'score' => 10_000]);
        $allowed = Alliance::factory()->create(['name' => 'Allowed', 'score' => 1_000]);

        $evaluation = app(RaidPolicyService::class)->evaluateAlliance($allowed->id);

        $this->assertTrue($evaluation->allowed);
        $this->assertSame([], $evaluation->reasons);
        $this->assertContains($allowed->id, app(RaidPolicyService::class)->raidableAllianceIds());
    }

    public function test_top_cap_includes_the_boundary_and_excludes_the_next_alliance(): void
    {
        SettingService::setTopRaidable(2);
        Alliance::factory()->create(['name' => 'First', 'score' => 30_000]);
        $second = Alliance::factory()->create(['name' => 'Second', 'score' => 20_000]);
        $third = Alliance::factory()->create(['name' => 'Third', 'score' => 10_000]);

        $protected = app(RaidPolicyService::class)->evaluateAlliance($second->id);
        $allowed = app(RaidPolicyService::class)->evaluateAlliance($third->id);

        $this->assertFalse($protected->allowed);
        $this->assertSame(['top_alliance_cap'], array_column($protected->reasons, 'code'));
        $this->assertSame(2, $protected->reasons[0]['context']['score_rank']);
        $this->assertTrue($allowed->allowed);
    }

    public function test_no_raid_list_is_a_policy_violation(): void
    {
        Alliance::factory()->create(['score' => 20_000]);
        $defender = Alliance::factory()->create(['name' => 'Do Not Raid', 'score' => 1_000]);
        NoRaidList::query()->create(['alliance_id' => $defender->id]);

        $evaluation = app(RaidPolicyService::class)->evaluateAlliance($defender->id);

        $this->assertFalse($evaluation->allowed);
        $this->assertSame(['no_raid_list'], array_column($evaluation->reasons, 'code'));
        $this->assertStringContainsString('is on the no-raid list', $evaluation->reasons[0]['message']);
    }

    public function test_member_alliance_is_a_policy_violation(): void
    {
        Alliance::factory()->create(['score' => 20_000]);
        $memberAlliance = Alliance::factory()->create(['name' => 'Our Offshore', 'score' => 1_000]);
        config()->set('services.pw.alliance_id', $memberAlliance->id);
        app(AllianceMembershipService::class)->clear();

        $evaluation = app(RaidPolicyService::class)->evaluateAlliance($memberAlliance->id);

        $this->assertFalse($evaluation->allowed);
        $this->assertSame(['member_alliance'], array_column($evaluation->reasons, 'code'));
    }

    public function test_treaty_with_top_alliance_is_a_policy_violation(): void
    {
        $protected = Alliance::factory()->create(['name' => 'Protected', 'score' => 20_000]);
        $defender = Alliance::factory()->create(['name' => 'Treaty Partner', 'score' => 1_000]);
        $this->createTreaty($defender, $protected);

        $evaluation = app(RaidPolicyService::class)->evaluateAlliance($defender->id);

        $this->assertFalse($evaluation->allowed);
        $this->assertSame(['protected_treaty'], array_column($evaluation->reasons, 'code'));
        $this->assertSame(
            $protected->id,
            $evaluation->reasons[0]['context']['protected_partners'][0]['alliance_id'],
        );
    }

    public function test_all_matching_reasons_have_deterministic_order(): void
    {
        SettingService::setTopRaidable(2);
        $defender = Alliance::factory()->create(['name' => 'Everything Protected', 'score' => 30_000]);
        $treatyPartner = Alliance::factory()->create(['name' => 'Treaty Protector', 'score' => 20_000]);
        config()->set('services.pw.alliance_id', $defender->id);
        app(AllianceMembershipService::class)->clear();
        NoRaidList::query()->create(['alliance_id' => $defender->id]);
        $this->createTreaty($defender, $treatyPartner);

        $evaluation = app(RaidPolicyService::class)->evaluateAlliance($defender->id);

        $this->assertSame([
            'member_alliance',
            'no_raid_list',
            'top_alliance_cap',
            'protected_treaty',
        ], array_column($evaluation->reasons, 'code'));
    }

    public function test_missing_or_unallied_defender_does_not_create_a_false_violation(): void
    {
        Alliance::factory()->create(['score' => 20_000]);

        $this->assertTrue(app(RaidPolicyService::class)->evaluateAlliance(null)->allowed);
        $this->assertTrue(app(RaidPolicyService::class)->evaluateAlliance(0)->allowed);
        $this->assertTrue(app(RaidPolicyService::class)->evaluateAlliance(999_999)->allowed);
    }

    public function test_raidable_alliance_ids_apply_every_durable_rule(): void
    {
        $top = Alliance::factory()->create(['score' => 50_000]);
        $member = Alliance::factory()->create(['score' => 4_000]);
        $noRaid = Alliance::factory()->create(['score' => 3_000]);
        $treatyProtected = Alliance::factory()->create(['score' => 2_000]);
        $allowed = Alliance::factory()->create(['score' => 1_000]);
        config()->set('services.pw.alliance_id', $member->id);
        app(AllianceMembershipService::class)->clear();
        NoRaidList::query()->create(['alliance_id' => $noRaid->id]);
        $this->createTreaty($treatyProtected, $top);

        $raidableIds = app(RaidPolicyService::class)->raidableAllianceIds();

        $this->assertSame([$allowed->id], $raidableIds);
    }

    private function createTreaty(Alliance $first, Alliance $second): Treaty
    {
        return Treaty::query()->create([
            'pw_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'pw_date' => now(),
            'turns_left' => 100,
            'alliance1_id' => $first->id,
            'alliance2_id' => $second->id,
            'type' => 'MDP',
        ]);
    }
}
