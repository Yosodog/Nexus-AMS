<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Events\WarStateChanged;
use App\Listeners\ReconcileMilcomWarState;
use App\Models\Alliance;
use App\Models\MilcomEvent;
use App\Models\Nation;
use App\Models\NoRaidList;
use App\Services\AllianceMembershipService;
use App\Services\Milcom\LifecycleReconciler;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomRaidPolicyAlertTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        SettingService::setTopRaidable(1);
    }

    public function test_unplanned_declaration_against_protected_alliance_creates_one_snapshotted_alert(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: true);
        NoRaidList::query()->create(['alliance_id' => $defender->alliance_id]);
        $war = $this->createWar(970001, $attacker, $defender);

        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);
        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);

        $event = MilcomEvent::query()
            ->where('event_type', 'war.raid_policy_violation.970001')
            ->sole();
        $this->assertSame('war.raid_policy_violation.970001', $event->deduplication_key);
        $this->assertSame(970001, data_get($event->payload, 'war_id'));
        $this->assertSame($attacker->nation_name, data_get($event->payload, 'friendly_nation_name'));
        $this->assertSame($defender->nation_name, data_get($event->payload, 'target_nation_name'));
        $this->assertSame(
            ['no_raid_list', 'top_alliance_cap'],
            array_column(data_get($event->payload, 'raid_policy.reasons'), 'code'),
        );
        $this->assertDatabaseCount('milcom_events', 1);
    }

    public function test_allowed_unplanned_raid_creates_no_alert(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: false);
        $war = $this->createWar(970002, $attacker, $defender);

        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);

        $this->assertDatabaseMissing('milcom_events', [
            'event_type' => 'war.raid_policy_violation.970002',
        ]);
    }

    public function test_planned_war_against_protected_alliance_creates_no_alert(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: true);
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $defender);
        $this->createAssignment($objective, $attacker, ['status' => AssignmentStatus::Approved]);
        $war = $this->createWar(970003, $attacker, $defender);

        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);

        $this->assertDatabaseMissing('milcom_events', [
            'event_type' => 'war.raid_policy_violation.970003',
        ]);
        $this->assertSame($war->id, $objective->assignments()->sole()->declared_war_id);
    }

    public function test_war_state_changes_do_not_retroactively_create_alerts(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: true);
        $war = $this->createWar(970004, $attacker, $defender);

        app(ReconcileMilcomWarState::class)->handle(new WarStateChanged($war->id));

        $this->assertDatabaseMissing('milcom_events', [
            'event_type' => 'war.raid_policy_violation.970004',
        ]);
    }

    public function test_dashboard_exposes_open_violation_with_reasons_and_war_timeline(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: true);
        $war = $this->createWar(970005, $attacker, $defender);
        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);
        MilcomEvent::query()->create([
            'source' => 'game',
            'event_type' => 'war.unplanned_declaration.111',
            'payload' => ['war_id' => 111],
            'occurred_at' => now(),
        ]);
        $manager = $this->authenticateMilcomManager();

        $this->getJson('/api/v1/milcom/dashboard')
            ->assertOk()
            ->assertJsonPath('data.exceptions.0.type', 'raid_policy')
            ->assertJsonPath('data.exceptions.0.event_id', fn ($id): bool => is_int($id))
            ->assertJsonPath('data.exceptions.0.attacker_nation_id', $attacker->id)
            ->assertJsonPath('data.exceptions.0.defender_nation_id', $defender->id)
            ->assertJsonPath('data.exceptions.0.reasons.0.code', 'top_alliance_cap')
            ->assertJsonPath(
                'data.exceptions.0.url',
                'https://politicsandwar.com/nation/war/timeline/war=970005',
            )
            ->assertJsonMissing(['type' => 'conflict', 'title' => 'Unplanned friendly declaration']);

        $this->actingAs($manager)
            ->get(route('admin.milcom.dashboard'))
            ->assertOk()
            ->assertSee('Raid policy violation')
            ->assertSee('War timeline')
            ->assertSee('Dismiss');
    }

    public function test_dismissal_is_global_idempotent_and_preserves_first_officer(): void
    {
        [$attacker, $defender] = $this->createWarNations(protectedDefender: true);
        $war = $this->createWar(970006, $attacker, $defender);
        app(LifecycleReconciler::class)->reconcileDeclaration($war->id);
        $event = MilcomEvent::query()->sole();
        $firstManager = $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/events/{$event->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.dismissed_by_user_id', $firstManager->id);

        $secondManager = $this->authenticateMilcomManager();
        $this->postJson("/api/v1/milcom/events/{$event->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.dismissed_by_user_id', $firstManager->id);
        $this->assertSame($firstManager->id, $event->fresh()->dismissed_by_user_id);

        $this->getJson('/api/v1/milcom/dashboard')
            ->assertOk()
            ->assertJsonCount(0, 'data.exceptions');

        $this->assertNotSame($firstManager->id, $secondManager->id);
    }

    public function test_dismiss_endpoint_requires_permission_and_rejects_other_events(): void
    {
        $event = MilcomEvent::query()->create([
            'source' => 'system',
            'event_type' => 'operation.created',
            'occurred_at' => now(),
        ]);
        $unauthorized = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($unauthorized);
        $this->actingAsSanctum($unauthorized);

        $this->postJson("/api/v1/milcom/events/{$event->id}/dismiss")->assertForbidden();

        $this->authenticateMilcomManager();
        $this->postJson("/api/v1/milcom/events/{$event->id}/dismiss")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');
        $this->assertNull($event->fresh()->dismissed_at);
    }

    /**
     * @return array{Nation, Nation}
     */
    private function createWarNations(bool $protectedDefender): array
    {
        $friendlyAlliance = Alliance::factory()->create(['name' => 'Friendly', 'score' => 5_000]);
        $protectedAlliance = Alliance::factory()->create(['name' => 'Protected', 'score' => 50_000]);
        $defenderAlliance = $protectedDefender
            ? $protectedAlliance
            : Alliance::factory()->create(['name' => 'Raidable', 'score' => 1_000]);
        config()->set('services.pw.alliance_id', $friendlyAlliance->id);
        app(AllianceMembershipService::class)->clear();

        return [
            Nation::factory()->create([
                'alliance_id' => $friendlyAlliance->id,
                'nation_name' => 'Friendly Raider',
            ]),
            Nation::factory()->create([
                'alliance_id' => $defenderAlliance->id,
                'nation_name' => 'Defending Nation',
            ]),
        ];
    }
}
