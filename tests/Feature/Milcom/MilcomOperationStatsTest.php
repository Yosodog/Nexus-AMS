<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Models\Alliance;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\WarAttack;
use App\Services\Milcom\MilcomOperationStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomOperationStatsTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        Carbon::setTestNow('2026-08-03 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stats_only_count_wars_linked_to_the_operation(): void
    {
        $fixture = $this->createStatsFixture();

        $stats = app(MilcomOperationStatsService::class)->forOperation($fixture['operation']);

        $this->assertSame(3, $stats['summary']['assignments']);
        $this->assertSame(2, $stats['summary']['declared_assignments']);
        $this->assertSame(66.7, $stats['summary']['declaration_rate']);
        $this->assertSame(1, $stats['summary']['active_wars']);
        $this->assertSame(1, $stats['summary']['finished_wars']);
        $this->assertSame(1, $stats['summary']['wins']);
        $this->assertSame(0, $stats['summary']['losses']);
        $this->assertSame(3_000_000.0, $stats['summary']['infra_inflicted_value']);
        $this->assertSame(750_000.0, $stats['summary']['infra_taken_value']);
        $this->assertSame(2_250_000.0, $stats['summary']['net_infra_value']);
        $this->assertSame(150_000.0, $stats['summary']['loot']);
        $this->assertSame(1, $stats['summary']['outgoing_attacks']);
        $this->assertSame(100.0, $stats['summary']['attack_success_rate']);
        $this->assertSame(1, $stats['attention']['waiting_to_declare']);
        $this->assertSame(0, $stats['attention']['no_first_hit']);
        $this->assertSame(1, $stats['attention']['low_resistance']);
        $this->assertCount(1, $stats['current_wars']);
        $this->assertSame('Friendly resistance is low', $stats['current_wars'][0]['alert']);
        $this->assertSame(1, array_sum($stats['charts']['outgoing_attacks']));
        $this->assertSame(1, array_sum($stats['charts']['incoming_attacks']));
        $this->assertCount(2, $stats['alliances']);
        $this->assertSame('Friendly Stats Alliance', $stats['contributors'][0]['nation']['alliance']['name']);
        $this->assertSame(2, $stats['forces']['friendly']['nations']);
        $this->assertSame(3, $stats['forces']['enemy']['nations']);
        $this->assertSame(2, $stats['forces']['friendly']['military_reports']);
        $this->assertSame(3, $stats['forces']['enemy']['military_reports']);
        $this->assertSame('latest_generation', $stats['forces']['source']);
        $this->assertSame(40, $stats['forces']['friendly']['cities']);
        $this->assertSame(44, $stats['forces']['enemy']['cities']);
        $this->assertSame(200_000, $stats['forces']['friendly']['soldiers']);
        $this->assertSame(190_000, $stats['forces']['enemy']['soldiers']);
        $this->assertSame(5_000.0, $stats['forces']['friendly']['soldiers_per_city']);
        $this->assertSame(2_000, $stats['forces']['friendly']['aircraft']);
        $this->assertSame(1_900, $stats['forces']['enemy']['aircraft']);
        $this->assertSame(300.0, $stats['side_results']['friendly']['infra_destroyed']);
        $this->assertSame(75.0, $stats['side_results']['enemy']['infra_destroyed']);
        $this->assertSame(150_000.0, $stats['side_results']['friendly']['loot']);
        $this->assertSame(30_000.0, $stats['side_results']['enemy']['loot']);
        $this->assertSame(100.0, $stats['side_results']['friendly']['attack_success_rate']);
        $this->assertSame(100.0, $stats['side_results']['enemy']['attack_success_rate']);
    }

    public function test_stats_tab_renders_compact_links_charts_and_attention_details(): void
    {
        $manager = $this->createMilcomManager();
        $this->actingAs($manager);
        $fixture = $this->createStatsFixture();

        $response = $this->get(route('admin.milcom.plans.show', [
            'operation' => $fixture['operation'],
            'stage' => 'stats',
        ]));

        $response
            ->assertOk()
            ->assertSee('Operation stats')
            ->assertSee('Side comparison')
            ->assertSee('Current forces')
            ->assertSee('Battle results')
            ->assertSee('Soldiers destroyed')
            ->assertSee('Combat activity')
            ->assertSee('Current wars')
            ->assertSee('Alliance performance')
            ->assertSee('Top contributors')
            ->assertSee('Friendly Alpha')
            ->assertSee('Target Alpha')
            ->assertSee('Waiting Friendly')
            ->assertSee('Waiting Target')
            ->assertSee('id="milcom-activity-chart"', false)
            ->assertSee('id="milcom-damage-chart"', false)
            ->assertSee('https://politicsandwar.com/nation/id='.$fixture['friendly']->id, false)
            ->assertSee('https://politicsandwar.com/nation/war/timeline/war=960001', false)
            ->assertDontSee('data-milcom-objective-row', false);

        $this->assertLessThan(250_000, strlen($response->getContent()));
    }

    public function test_current_war_rows_are_bounded_for_large_operations(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
        ]);

        foreach (Nation::factory()->count(30)->create(['alliance_id' => $enemyAlliance->id]) as $index => $target) {
            $objective = $this->createMilcomObjective($operation, $target, [
                'status' => ObjectiveStatus::Engaged,
            ]);
            $war = $this->createWar(970_000 + $index, $friendly, $target, [
                'att_resistance' => 100,
                'def_resistance' => 100,
            ]);
            $this->createAssignment($objective, $friendly, [
                'status' => AssignmentStatus::Engaged,
                'declared_war_id' => $war->id,
            ]);
        }

        $stats = app(MilcomOperationStatsService::class)->forOperation($operation);

        $this->assertSame(30, $stats['current_wars_total']);
        $this->assertCount(25, $stats['current_wars']);
    }

    /** @return array{operation: MilcomOperation, friendly: Nation} */
    private function createStatsFixture(): array
    {
        $friendlyAlliance = Alliance::factory()->create([
            'name' => 'Friendly Stats Alliance',
            'acronym' => 'FSA',
        ]);
        $enemyAlliance = Alliance::factory()->create([
            'name' => 'Enemy Stats Alliance',
            'acronym' => 'ESA',
        ]);
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'nation_name' => 'Friendly Alpha',
            'leader_name' => 'Friendly Leader',
            'num_cities' => 24,
            'score' => 4_800,
            'offensive_wars_count' => 1,
            'defensive_wars_count' => 1,
        ]);
        $activeTarget = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => 'Target Alpha',
            'leader_name' => 'Target Leader',
            'num_cities' => 20,
            'score' => 4_000,
            'offensive_wars_count' => 1,
            'defensive_wars_count' => 1,
        ]);
        $finishedTarget = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => 'Target Bravo',
            'num_cities' => 14,
            'score' => 2_800,
        ]);
        $waitingFriendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'nation_name' => 'Waiting Friendly',
            'num_cities' => 16,
            'score' => 3_200,
        ]);
        $waitingTarget = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => 'Waiting Target',
            'num_cities' => 10,
            'score' => 2_000,
        ]);
        $this->createMilitary($friendly, [
            'soldiers' => 120_000,
            'tanks' => 2_500,
            'aircraft' => 1_200,
            'ships' => 120,
            'missiles' => 5,
            'nukes' => 3,
            'spies' => 60,
        ]);
        $this->createMilitary($waitingFriendly, [
            'soldiers' => 80_000,
            'tanks' => 1_500,
            'aircraft' => 800,
            'ships' => 80,
            'missiles' => 3,
            'nukes' => 1,
            'spies' => 40,
        ]);
        $this->createMilitary($activeTarget, [
            'soldiers' => 90_000,
            'tanks' => 1_800,
            'aircraft' => 900,
            'ships' => 90,
            'missiles' => 3,
            'nukes' => 2,
            'spies' => 50,
        ]);
        $this->createMilitary($finishedTarget, [
            'soldiers' => 60_000,
            'tanks' => 1_200,
            'aircraft' => 600,
            'ships' => 60,
            'missiles' => 2,
            'nukes' => 1,
            'spies' => 30,
        ]);
        $this->createMilitary($waitingTarget, [
            'soldiers' => 40_000,
            'tanks' => 800,
            'aircraft' => 400,
            'ships' => 40,
            'missiles' => 1,
            'nukes' => 0,
            'spies' => 20,
        ]);
        $operation = $this->createMilcomOperation([
            'name' => 'Operation Stats Test',
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
        ]);

        $activeObjective = $this->createMilcomObjective($operation, $activeTarget, [
            'status' => ObjectiveStatus::Engaged,
        ]);
        $activeWar = $this->createWar(960_001, $friendly, $activeTarget, [
            'date' => now()->subDays(2),
            'att_resistance' => 20,
            'def_resistance' => 75,
            'att_infra_destroyed' => 100,
            'def_infra_destroyed' => 25,
            'att_infra_destroyed_value' => 1_000_000,
            'def_infra_destroyed_value' => 250_000,
            'att_money_looted' => 50_000,
            'def_money_looted' => 10_000,
            'def_soldiers_lost' => 10_000,
            'att_soldiers_lost' => 1_000,
            'att_missiles_used' => 2,
            'att_nukes_used' => 1,
            'def_missiles_used' => 1,
        ]);
        $this->createAssignment($activeObjective, $friendly, [
            'status' => AssignmentStatus::Engaged,
            'declared_war_id' => $activeWar->id,
        ]);

        $finishedObjective = $this->createMilcomObjective($operation, $finishedTarget, [
            'status' => ObjectiveStatus::Completed,
        ]);
        $finishedWar = $this->createWar(960_002, $friendly, $finishedTarget, [
            'date' => now()->subDays(5),
            'end_date' => now()->subDay(),
            'turns_left' => 0,
            'winner_id' => $friendly->id,
            'att_resistance' => 42,
            'def_resistance' => 0,
            'att_infra_destroyed' => 200,
            'def_infra_destroyed' => 50,
            'att_infra_destroyed_value' => 2_000_000,
            'def_infra_destroyed_value' => 500_000,
            'att_money_looted' => 100_000,
            'def_money_looted' => 20_000,
            'def_tanks_lost' => 2_000,
            'att_tanks_lost' => 500,
            'att_missiles_used' => 1,
            'def_missiles_used' => 2,
            'def_nukes_used' => 1,
        ]);
        $this->createAssignment($finishedObjective, $friendly, [
            'status' => AssignmentStatus::Completed,
            'declared_war_id' => $finishedWar->id,
        ]);

        $waitingObjective = $this->createMilcomObjective($operation, $waitingTarget, [
            'status' => ObjectiveStatus::Dispatched,
            'deadline_at' => now()->addDay(),
        ]);
        $this->createAssignment($waitingObjective, $waitingFriendly, [
            'status' => AssignmentStatus::Dispatched,
        ]);
        $run = $this->attachSuccessfulRecommendation($activeObjective, [$friendly, $waitingFriendly]);
        $this->createReadinessSnapshot($run, $finishedTarget, 'target');
        $this->createReadinessSnapshot($run, $waitingTarget, 'target');
        $run->forceFill(['objective_id' => null])->save();
        $activeObjective->forceFill(['status' => ObjectiveStatus::Engaged])->save();

        WarAttack::query()->create([
            'id' => 961_001,
            'war_id' => $activeWar->id,
            'date' => now()->subDay(),
            'att_id' => $friendly->id,
            'def_id' => $activeTarget->id,
            'type' => 'GROUND',
            'success' => 3,
            'infra_destroyed_value' => 125_000,
        ]);
        WarAttack::query()->create([
            'id' => 961_002,
            'war_id' => $activeWar->id,
            'date' => now()->subDay(),
            'att_id' => $activeTarget->id,
            'def_id' => $friendly->id,
            'type' => 'GROUND',
            'success' => 2,
            'infra_destroyed_value' => 25_000,
        ]);

        $this->createWar(969_999, $friendly, $activeTarget, [
            'att_infra_destroyed_value' => 999_000_000,
            'att_money_looted' => 999_000_000,
        ]);

        return [
            'operation' => $operation,
            'friendly' => $friendly,
        ];
    }

    /** @param array<string, int> $overrides */
    private function createMilitary(Nation $nation, array $overrides): void
    {
        NationMilitary::query()->create(array_merge([
            'nation_id' => $nation->id,
            'soldiers' => 0,
            'tanks' => 0,
            'aircraft' => 0,
            'ships' => 0,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 0,
            'soldiers_today' => 0,
            'tanks_today' => 0,
            'aircraft_today' => 0,
            'ships_today' => 0,
            'missiles_today' => 0,
            'nukes_today' => 0,
            'spies_today' => 0,
            'soldier_casualties' => 0,
            'soldier_kills' => 0,
            'tank_casualties' => 0,
            'tank_kills' => 0,
            'aircraft_casualties' => 0,
            'aircraft_kills' => 0,
            'ship_casualties' => 0,
            'ship_kills' => 0,
            'missile_casualties' => 0,
            'missile_kills' => 0,
            'nuke_casualties' => 0,
            'nuke_kills' => 0,
            'spy_casualties' => 0,
            'spy_kills' => 0,
            'spy_attacks' => 0,
        ], $overrides));
    }
}
