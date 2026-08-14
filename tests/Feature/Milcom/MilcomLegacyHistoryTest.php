<?php

namespace Tests\Feature\Milcom;

use App\Models\Nation;
use App\Models\WarCounter;
use App\Models\WarPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomLegacyHistoryTest extends TestCase
{
    use BuildsMilcomFixtures;
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();

        config()->set('milcom.v2_enabled', true);
    }

    public function test_legacy_mutations_return_gone_and_safe_routes_redirect_to_history(): void
    {
        $plan = WarPlan::query()->create([
            'name' => 'Legacy Iron Tempest',
            'status' => 'archived',
            'archived_at' => now(),
        ]);
        $manager = $this->createMilcomManager();
        $this->actingAs($manager);

        $this->postJson(route('admin.war-plans.archive', $plan))
            ->assertGone()
            ->assertJsonPath('error.code', 'legacy_milcom_gone');

        $this->get(route('admin.war-plans.show', $plan))
            ->assertRedirect(route('admin.milcom.archive', [
                'tab' => 'legacy',
                'legacy_type' => 'plans',
            ]));

        $this->assertSame('archived', $plan->fresh()->status);
    }

    public function test_legacy_plan_and_counter_details_remain_readable_through_v2_history_api(): void
    {
        $aggressor = Nation::factory()->create();
        $plan = WarPlan::query()->create([
            'name' => 'Legacy Coalition Dawn',
            'status' => 'archived',
            'archived_at' => now()->subDay(),
        ]);
        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'archived',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
            'discord_channel_id' => '423456789012345678',
            'archived_at' => now(),
        ]);
        $this->authenticateMilcomManager();

        $this->getJson("/api/v1/milcom/archive/legacy/plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.name', 'Legacy Coalition Dawn')
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.summary', 'Read-only legacy mass-war plan.');

        $this->getJson("/api/v1/milcom/archive/legacy/counters/{$counter->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $counter->id)
            ->assertJsonPath('data.aggressor.id', $aggressor->id)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.discord_channel_id', '423456789012345678')
            ->assertJsonPath('data.summary', 'Read-only legacy fast counter.');
    }

    public function test_cutover_archives_open_legacy_work_and_is_idempotent(): void
    {
        config()->set('milcom.game_rules.contract_verified', true);
        $aggressor = Nation::factory()->create();
        $plan = WarPlan::query()->create([
            'name' => 'Legacy Open Plan',
            'status' => 'planning',
        ]);
        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'draft',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
            'discord_channel_id' => '423456789012345678',
        ]);

        $this->artisan('milcom:v2-cutover')->assertSuccessful();
        $this->artisan('milcom:v2-cutover')->assertSuccessful();

        $this->assertSame('archived', $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->archived_at);
        $this->assertSame('archived', $counter->fresh()->status);
        $this->assertNull($counter->fresh()->active_key);
        $this->assertNotNull($counter->fresh()->archived_at);
        $this->assertDatabaseCount('discord_queue', 1);
        $this->assertDatabaseHas('discord_queue', [
            'action' => 'WAR_ROOM_ARCHIVE',
            'dedupe_key' => "legacy-war-counter:{$counter->id}:archive:v2-cutover",
        ]);
    }
}
