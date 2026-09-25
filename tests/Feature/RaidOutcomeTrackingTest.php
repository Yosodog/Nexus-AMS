<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\WarAttackRecorded;
use App\Events\WarDeclared;
use App\Events\WarStateChanged;
use App\Jobs\ReconcileRaidPredictionJob;
use App\Jobs\RecordRaidOutcomeAttackJob;
use App\Listeners\CaptureRaidOutcomeOnAttackRecorded;
use App\Listeners\CaptureRaidPredictionOnWarDeclared;
use App\Listeners\ReconcileRaidPredictionOnWarStateChanged;
use App\Models\Nation;
use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Models\WarAttack;
use App\Services\QueryService;
use App\Services\RaidAssessmentService;
use App\Services\RaidOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RaidOutcomeTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_raid_declarations_are_captured_once_with_frozen_inputs(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        Queue::fake();
        $war = $this->createWar(91001, [
            'att_id' => 101,
            'att_alliance_id' => 777,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 202,
        ]);
        $event = new WarDeclared(
            warId: $war->id,
            attackerNationId: 101,
            attackerAllianceId: 777,
            attackerAlliancePosition: 'MEMBER',
            defenderNationId: 202,
            defenderAllianceId: null,
            defenderAlliancePosition: 'NOALLIANCE',
        );
        $listener = app(CaptureRaidPredictionOnWarDeclared::class);
        $listener->handle($event);
        $listener->handle($event);

        $prediction = RaidPrediction::query()->where('war_id', $war->id)->firstOrFail();
        $this->assertSame(1, RaidPrediction::query()->where('war_id', $war->id)->count());
        $this->assertSame(RaidPrediction::CAPTURE_INCOMPLETE, $prediction->capture_status);
        $this->assertSame(101, $prediction->attacker_nation_id);
        $this->assertSame(202, $prediction->target_nation_id);
    }

    public function test_attack_events_are_idempotent_correctable_and_reconciled_after_terminal_state(): void
    {
        Nation::factory()->create(['id' => 101]);
        Nation::factory()->create(['id' => 202]);
        $war = $this->createWar(91002, [
            'att_id' => 101,
            'att_alliance_id' => 777,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 202,
            'date' => '2026-09-13 10:00:00',
            'att_gas_used' => 3,
            'att_money_looted' => 50,
            'att_soldiers_lost' => 10,
            'att_infra_destroyed_value' => 20,
        ]);
        $prediction = $this->createPrediction($war, [
            'declared_at' => '2026-09-13 10:00:00',
            'captured_at' => '2026-09-13 10:00:01',
            'observed_at' => '2026-09-13 09:55:00',
            'price_snapshot' => [
                'acquisition' => [
                    'coal' => 5, 'oil' => 2, 'uranium' => 100, 'iron' => 3, 'bauxite' => 3,
                    'lead' => 3, 'gasoline' => 2, 'munitions' => 3, 'steel' => 10, 'aluminum' => 10, 'food' => 5,
                ],
                'liquidation' => [
                    'coal' => 5, 'oil' => 2, 'uranium' => 100, 'iron' => 3, 'bauxite' => 3,
                    'lead' => 3, 'gasoline' => 2, 'munitions' => 3, 'steel' => 10, 'aluminum' => 10, 'food' => 5,
                ],
            ],
            'simulation_payload' => [
                'approach' => ['key' => 'ground_focused', 'actions' => [
                    ['type' => 'ground'], ['type' => 'ground'],
                ]],
            ],
            'expected_net' => 100,
            'gross_loot' => 200,
            'conservative_net' => 50,
            'duration_hours' => 72,
            'components' => ['consumables' => 5, 'military_losses' => 40, 'infrastructure_losses' => 10],
            'loot_resources' => ['money' => 150, 'coal' => 2],
            'cost_resources' => ['gasoline' => 2],
            'scenarios' => [['expected_net' => 50], ['expected_net' => 200]],
            'evaluation_status' => RaidPrediction::EVALUATION_COMPLETE,
        ]);
        $attack = WarAttack::query()->create([
            'id' => 92001,
            'date' => '2026-09-13 11:00:00',
            'att_id' => 101,
            'def_id' => 202,
            'type' => 'GROUND',
            'war_id' => $war->id,
            'victor' => 101,
            'success' => 1,
            'money_looted' => 50,
            'money_stolen' => 100,
            'coal_looted' => 2,
            'att_gas_used' => 3,
            'att_soldiers_lost' => 10,
            'infra_destroyed_value' => 20,
        ]);
        Queue::fake();
        app(CaptureRaidOutcomeOnAttackRecorded::class)->handle(new WarAttackRecorded($attack->id, $war->id));
        Queue::assertPushed(RecordRaidOutcomeAttackJob::class, 1);
        (new RecordRaidOutcomeAttackJob($attack->id, $war->id))->handle(app(RaidOutcomeService::class));
        (new RecordRaidOutcomeAttackJob($attack->id, $war->id))->handle(app(RaidOutcomeService::class));

        $evidence = RaidOutcomeAttack::query()->where('attack_id', $attack->id)->firstOrFail();
        $this->assertSame(1, $evidence->revision);
        $this->assertSame(104.0, (float) $prediction->refresh()->actual_net);

        $war->update([
            'winner_id' => 101,
            'end_date' => '2026-09-13 12:00:00',
        ]);
        app(ReconcileRaidPredictionOnWarStateChanged::class)->handle(new WarStateChanged($war->id));
        Queue::assertPushed(ReconcileRaidPredictionJob::class, 1);
        (new ReconcileRaidPredictionJob($war->id))->handle(app(RaidOutcomeService::class));
        $this->assertSame(RaidPrediction::OUTCOME_WON, $prediction->refresh()->outcome_status);
        $this->assertNotNull($prediction->outcome_finalized_at);
        $this->assertSame(2.0, (float) $prediction->actual_duration_hours);

        $corrected = [
            'id' => $attack->id,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'GROUND',
            'victor' => 101,
            'money_looted' => 50,
            'money_stolen' => 100,
            'coal_looted' => 2,
            'bank_loot' => ['coal' => 1],
            'att_gas_used' => 3,
            'att_soldiers_lost' => 10,
            'infra_destroyed_value' => 20,
        ];
        $outcome = app(RaidOutcomeService::class)->recordPayload($corrected);
        $this->assertNotNull($outcome);
        $this->assertSame(2, $outcome->revision);
        $this->assertTrue($outcome->is_late);
        $this->assertSame(109.0, (float) $prediction->refresh()->actual_net);
        $this->assertSame(2.0, (float) $prediction->actual_duration_hours);
        $this->assertSame(1.0, (float) $prediction->actual_bank_loot['coal']);
        $this->assertSame(1, RaidOutcomeAttack::query()->where('war_id', $war->id)->count());
    }

    public function test_assessment_reports_capture_coverage_errors_and_activity_breakdowns(): void
    {
        $firstWar = $this->createWar(91003, ['att_id' => 101, 'def_id' => 203]);
        $secondWar = $this->createWar(91004, ['att_id' => 102, 'def_id' => 204]);
        $this->createPrediction($firstWar, [
            'declared_at' => now()->subDays(2),
            'captured_at' => now()->subDays(2),
            'observed_at' => now()->subDays(2),
            'target_snapshot' => ['last_active' => now()->subDays(2)->addHours(4)->toIso8601String()],
            'provenance' => ['confidence' => 'high'],
            'context_snapshot' => ['competition' => 1],
            'expected_net' => 100,
            'actual_net' => 120,
            'gross_loot' => 200,
            'actual_gross_loot' => 220,
            'conservative_net' => 50,
            'scenarios' => [['expected_net' => 50], ['expected_net' => 200]],
            'components' => ['consumables' => 10],
            'actual_components' => ['consumables' => 12],
            'loot_resources' => ['coal' => 10],
            'actual_loot_resources' => ['coal' => 12],
            'outcome_metadata' => [
                'evidence_status' => 'complete',
                'plan_adherence' => ['status' => 'observed', 'score' => 0.5, 'matched_actions' => 1, 'observed_actions' => ['ground']],
            ],
            'outcome_status' => RaidPrediction::OUTCOME_WON,
            'evaluation_status' => RaidPrediction::EVALUATION_COMPLETE,
        ]);
        $this->createPrediction($secondWar, [
            'declared_at' => now()->subDays(1),
            'captured_at' => now()->subDays(1),
            'capture_status' => RaidPrediction::CAPTURE_INCOMPLETE,
            'capture_reason' => 'No clean baseline',
            'evaluation_status' => RaidPrediction::EVALUATION_FAILED,
            'outcome_status' => RaidPrediction::OUTCOME_OPEN,
        ]);

        $report = app(RaidAssessmentService::class)->assess(
            now()->subDays(30),
            now(),
        );

        $this->assertSame(2, $report['capture']['total']);
        $this->assertSame(1, $report['capture']['ready']);
        $this->assertSame(1, $report['capture']['incomplete']);
        $this->assertSame(1, $report['sample_count']);
        $this->assertSame(20.0, $report['metrics']['signed_error']);
        $this->assertSame(2.0, $report['metrics']['resource_errors']['coal']);
        $this->assertSame(1, $report['metrics']['plan_adherence']['known']);
        $this->assertArrayHasKey('activity', $report['breakdowns']);
        $this->assertArrayHasKey('under_1_day', $report['breakdowns']['activity']);
        $this->assertSame(100.0, $report['metrics']['range_coverage']['percent']);
        $this->assertSame(50.0, $report['capture_readiness_percent']);
        $this->assertSame('unknown', $report['capture_coverage']['status']);
        $this->assertNull($report['capture_coverage']['percent']);
    }

    public function test_terminal_outcomes_stay_incomplete_until_delayed_attack_evidence_arrives(): void
    {
        $war = $this->createWar(91005, [
            'att_id' => 101,
            'def_id' => 202,
            'winner_id' => 101,
            'end_date' => '2026-09-13 12:00:00',
            'att_money_looted' => 100,
        ]);
        $prediction = $this->createPrediction($war, [
            'price_snapshot' => ['acquisition' => [], 'liquidation' => []],
            'expected_net' => 100,
            'evaluation_status' => RaidPrediction::EVALUATION_COMPLETE,
        ]);
        $queries = Mockery::mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->once()->andReturn((object) []);
        $this->app->instance(QueryService::class, $queries);

        $outcome = app(RaidOutcomeService::class)->reconcile($prediction);
        $this->assertSame(RaidPrediction::OUTCOME_WON, $outcome?->outcome_status);
        $this->assertNull($outcome?->outcome_finalized_at);
        $this->assertNull($outcome?->actual_net);
        $this->assertSame('incomplete', data_get($outcome?->outcome_metadata, 'evidence_status'));

        $outcome = app(RaidOutcomeService::class)->recordPayload([
            'id' => 92005,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
            'payout' => 50,
        ]);
        $this->assertNotNull($outcome);
        $this->assertSame('complete', data_get($outcome?->prediction?->outcome_metadata, 'evidence_status'));
        $this->assertSame(100.0, (float) $outcome?->prediction?->actual_net);
        $this->assertNotNull($outcome?->prediction?->outcome_finalized_at);
    }

    public function test_reconciliation_values_both_participants_and_only_member_infrastructure_losses(): void
    {
        $war = $this->createWar(91006, [
            'att_id' => 101,
            'def_id' => 202,
            'att_gas_used' => 7,
            'def_gas_used' => 0,
            'att_money_looted' => 50,
            'att_soldiers_lost' => 17,
            'def_soldiers_lost' => 0,
            'att_infra_destroyed_value' => 20,
            'def_infra_destroyed_value' => 12,
        ]);
        $prediction = $this->createPrediction($war, [
            'price_snapshot' => [
                'acquisition' => [
                    'coal' => 5, 'oil' => 2, 'uranium' => 100, 'iron' => 3, 'bauxite' => 3,
                    'lead' => 3, 'gasoline' => 2, 'munitions' => 3, 'steel' => 10, 'aluminum' => 10, 'food' => 5,
                ],
                'liquidation' => [
                    'coal' => 5, 'oil' => 2, 'uranium' => 100, 'iron' => 3, 'bauxite' => 3,
                    'lead' => 3, 'gasoline' => 2, 'munitions' => 3, 'steel' => 10, 'aluminum' => 10, 'food' => 5,
                ],
            ],
        ]);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92006,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'GROUND',
            'victor' => 101,
            'money_looted' => 50,
            'coal_looted' => 2,
            'att_gas_used' => 3,
            'att_soldiers_lost' => 10,
            'infra_destroyed_value' => 20,
        ]);
        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92007,
            'war_id' => $war->id,
            'att_id' => 202,
            'def_id' => 101,
            'date' => '2026-09-13 11:30:00',
            'type' => 'GROUND',
            'victor' => 202,
            'def_gas_used' => 4,
            'def_soldiers_lost' => 7,
            'infra_destroyed_value' => 12,
        ]);

        $war->update([
            'winner_id' => 101,
            'end_date' => '2026-09-13 12:00:00',
        ]);
        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        $this->assertSame(RaidPrediction::OUTCOME_WON, $result?->outcome_status);
        $this->assertSame(14.0, (float) data_get($result?->actual_components, 'consumables'));
        $this->assertSame(85.0, (float) data_get($result?->actual_components, 'military_losses'));
        $this->assertSame(12.0, (float) data_get($result?->actual_components, 'infrastructure_losses'));
        $this->assertSame(-51.0, (float) $result?->actual_net);
        $this->assertSame('complete', data_get($result?->outcome_metadata, 'evidence_status'));
    }

    public function test_attack_payloads_must_match_the_declared_war_participants(): void
    {
        $war = $this->createWar(91007, ['att_id' => 101, 'def_id' => 202]);
        $prediction = $this->createPrediction($war);

        $result = app(RaidOutcomeService::class)->recordPayload([
            'id' => 92008,
            'war_id' => $war->id,
            'att_id' => 303,
            'def_id' => 404,
            'date' => '2026-09-13 11:00:00',
            'type' => 'GROUND',
            'money_looted' => 500,
        ]);

        $this->assertNull($result);
        $this->assertDatabaseMissing('raid_outcome_attacks', ['attack_id' => 92008]);
        $this->assertNull($prediction->refresh()->actual_net);
    }

    public function test_raw_loot_fields_are_attributed_by_attack_type_without_double_counting(): void
    {
        $war = $this->createWar(91012, [
            'att_id' => 101,
            'def_id' => 202,
        ]);
        $prediction = $this->createPrediction($war, [
            'price_snapshot' => [
                'acquisition' => [],
                'liquidation' => ['coal' => 10],
            ],
        ]);
        $service = app(RaidOutcomeService::class);

        $service->recordPayload([
            'id' => 92012,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
            'coal_looted' => 5,
        ]);
        $service->recordPayload([
            'id' => 92013,
            'war_id' => $war->id,
            // The API row can be oriented from the other side; the victor
            // still determines which participant receives victory loot.
            'att_id' => 202,
            'def_id' => 101,
            'date' => '2026-09-13 11:30:00',
            'type' => 'ALLIANCELOOT',
            'victor' => 101,
            'money_looted' => 40,
            'coal_looted' => 3,
            // Raw alliance-loot fields are canonical; this duplicate-shaped
            // field must not add a second bank contribution.
            'bank_loot' => ['money' => 40, 'coal' => 3],
        ]);
        $service->recordPayload([
            'id' => 92014,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 12:00:00',
            'type' => 'GROUND',
            'money_stolen' => 20,
        ]);

        $result = $prediction->refresh();

        $this->assertSame(150.0, (float) data_get($result->actual_components, 'nation_loot'));
        $this->assertSame(70.0, (float) data_get($result->actual_components, 'bank_loot'));
        $this->assertSame(20.0, (float) data_get($result->actual_components, 'ground_loot'));
        $this->assertSame(240.0, (float) $result->actual_gross_loot);
        $this->assertSame(240.0, (float) $result->actual_net);
        $this->assertSame(160.0, (float) data_get($result->actual_loot_resources, 'money'));
        $this->assertSame(8.0, (float) data_get($result->actual_loot_resources, 'coal'));
        $this->assertSame(40.0, (float) data_get($result->actual_bank_loot, 'money'));
        $this->assertSame(3.0, (float) data_get($result->actual_bank_loot, 'coal'));
    }

    public function test_expired_war_without_end_date_keeps_actual_duration_unavailable(): void
    {
        $war = $this->createWar(91013, [
            'att_id' => 101,
            'def_id' => 202,
            'turns_left' => 0,
            'att_gas_used' => 0,
            'def_gas_used' => 0,
            'att_mun_used' => 0,
            'def_mun_used' => 0,
            'att_alum_used' => 0,
            'def_alum_used' => 0,
            'att_steel_used' => 0,
            'def_steel_used' => 0,
            'att_soldiers_lost' => 0,
            'def_soldiers_lost' => 0,
            'att_tanks_lost' => 0,
            'def_tanks_lost' => 0,
            'att_aircraft_lost' => 0,
            'def_aircraft_lost' => 0,
            'att_ships_lost' => 0,
            'def_ships_lost' => 0,
            'att_infra_destroyed_value' => 0,
            'def_infra_destroyed_value' => 0,
            'att_money_looted' => 0,
            'def_money_looted' => 0,
        ]);
        $prediction = $this->createPrediction($war);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92015,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'GROUND',
        ]);

        $result = $prediction->refresh();

        $this->assertSame(RaidPrediction::OUTCOME_EXPIRED, $result->outcome_status);
        $this->assertNull($result->actual_duration_hours);
        $this->assertSame('unavailable', data_get($result->outcome_metadata, 'duration_basis'));
    }

    public function test_victory_attack_timestamp_can_supply_duration_without_end_date(): void
    {
        $war = $this->createWar(91014, [
            'att_id' => 101,
            'def_id' => 202,
            'winner_id' => 101,
            'att_gas_used' => 0,
            'def_gas_used' => 0,
            'att_mun_used' => 0,
            'def_mun_used' => 0,
            'att_alum_used' => 0,
            'def_alum_used' => 0,
            'att_steel_used' => 0,
            'def_steel_used' => 0,
            'att_soldiers_lost' => 0,
            'def_soldiers_lost' => 0,
            'att_tanks_lost' => 0,
            'def_tanks_lost' => 0,
            'att_aircraft_lost' => 0,
            'def_aircraft_lost' => 0,
            'att_ships_lost' => 0,
            'def_ships_lost' => 0,
            'att_infra_destroyed_value' => 0,
            'def_infra_destroyed_value' => 0,
            'att_money_looted' => 0,
            'def_money_looted' => 0,
        ]);
        $prediction = $this->createPrediction($war);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92016,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 13:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
        ]);

        $result = $prediction->refresh();

        $this->assertSame(RaidPrediction::OUTCOME_WON, $result->outcome_status);
        $this->assertSame(3.0, (float) $result->actual_duration_hours);
        $this->assertSame('victory_attack_at', data_get($result->outcome_metadata, 'duration_basis'));
    }

    public function test_strategic_weapon_usage_uses_shared_military_costs(): void
    {
        $war = $this->createWar(91009, [
            'att_id' => 101,
            'def_id' => 202,
            'att_missiles_used' => 1,
            'att_nukes_used' => 1,
        ]);
        $prediction = $this->createPrediction($war, [
            'price_snapshot' => [
                'acquisition' => [
                    'coal' => 5,
                    'oil' => 2,
                    'uranium' => 100,
                    'iron' => 3,
                    'bauxite' => 3,
                    'lead' => 3,
                    'gasoline' => 2,
                    'munitions' => 3,
                    'steel' => 10,
                    'aluminum' => 10,
                    'food' => 5,
                ],
                'liquidation' => [
                    'coal' => 5,
                    'oil' => 2,
                    'uranium' => 100,
                    'iron' => 3,
                    'bauxite' => 3,
                    'lead' => 3,
                    'gasoline' => 2,
                    'munitions' => 3,
                    'steel' => 10,
                    'aluminum' => 10,
                    'food' => 5,
                ],
            ],
        ]);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92010,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'MISSILE',
            'att_missiles_lost' => 1,
            'att_nukes_lost' => 1,
        ]);
        $war->update([
            'winner_id' => 101,
            'end_date' => '2026-09-13 12:00:00',
        ]);

        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        $this->assertSame('complete', data_get($result?->outcome_metadata, 'evidence_status'));
        $this->assertSame(1.0, (float) data_get($result?->actual_losses, 'missiles'));
        $this->assertSame(1.0, (float) data_get($result?->actual_losses, 'nukes'));
        $this->assertSame(1_963_000.0, (float) data_get($result?->actual_components, 'military_losses'));
        $this->assertSame(-1_963_000.0, (float) $result?->actual_net);
    }

    public function test_unvalued_strategic_usage_keeps_terminal_outcome_incomplete(): void
    {
        $war = $this->createWar(91010, [
            'att_id' => 101,
            'def_id' => 202,
            'att_missiles_used' => 1,
        ]);
        $prediction = $this->createPrediction($war);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92011,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'MISSILE',
        ]);
        $war->update([
            'winner_id' => 101,
            'end_date' => '2026-09-13 12:00:00',
        ]);

        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        $this->assertSame('incomplete', data_get($result?->outcome_metadata, 'evidence_status'));
        $this->assertContains('military_losses.valuation', data_get($result?->outcome_metadata, 'evidence_missing', []));
        $this->assertNull($result?->actual_net);
        $this->assertNull($result?->actual_components);
    }

    public function test_eligible_bounty_stays_unknown_until_a_payout_is_observed(): void
    {
        $war = $this->createWar(91008, [
            'att_id' => 101,
            'def_id' => 202,
            'att_money_looted' => 100,
        ]);
        $prediction = $this->createPrediction($war, [
            'components' => ['bounty' => 50],
            'price_snapshot' => ['acquisition' => [], 'liquidation' => []],
        ]);

        app(RaidOutcomeService::class)->recordPayload([
            'id' => 92009,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
        ]);
        $war->update(['winner_id' => 101, 'end_date' => '2026-09-13 12:00:00']);

        $incomplete = app(RaidOutcomeService::class)->reconcile($prediction);
        $this->assertSame('incomplete', data_get($incomplete?->outcome_metadata, 'evidence_status'));
        $this->assertContains('bounty_payout', data_get($incomplete?->outcome_metadata, 'evidence_missing', []));
        $this->assertNull($incomplete?->actual_net);

        $complete = app(RaidOutcomeService::class)->recordPayload([
            'id' => 92009,
            'war_id' => $war->id,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
            'bounty_amount' => 50,
        ]);

        $this->assertSame(2, $complete?->revision);
        $this->assertSame('complete', data_get($complete?->prediction?->outcome_metadata, 'evidence_status'));
        $this->assertSame(150.0, (float) $complete?->prediction?->actual_net);
        $this->assertSame(50.0, (float) data_get($complete?->prediction?->actual_components, 'bounty'));
    }

    /** @param array<string, mixed> $overrides */
    private function createWar(int $id, array $overrides = []): War
    {
        return War::query()->create([
            'id' => $id,
            'date' => '2026-09-13 10:00:00',
            'end_date' => null,
            'reason' => 'Raid tracking test',
            'war_type' => 'RAID',
            'turns_left' => 12,
            'att_id' => 101,
            'att_alliance_id' => 777,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 202,
            'def_alliance_id' => null,
            'def_alliance_position' => 'NOALLIANCE',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPrediction(War $war, array $overrides = []): RaidPrediction
    {
        return RaidPrediction::query()->create([
            'war_id' => $war->id,
            'attacker_nation_id' => $war->att_id,
            'target_nation_id' => $war->def_id,
            'attacker_alliance_id' => $war->att_alliance_id,
            'attacker_alliance_position' => $war->att_alliance_position,
            'declared_at' => $war->date,
            'captured_at' => $war->date,
            'capture_status' => RaidPrediction::CAPTURE_READY,
            'evaluation_status' => RaidPrediction::EVALUATION_COMPLETE,
            'attacker_snapshot' => ['id' => $war->att_id],
            'target_snapshot' => ['id' => $war->def_id],
            'stockpile_snapshot' => ['resources' => ['money' => 1_000_000]],
            'price_snapshot' => ['acquisition' => [], 'liquidation' => []],
            'context_snapshot' => ['war_type' => 'RAID'],
            'provenance' => [],
            'frozen_payload' => [],
            ...$overrides,
        ]);
    }
}
