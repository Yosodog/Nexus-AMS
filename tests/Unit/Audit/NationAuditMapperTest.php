<?php

namespace Tests\Unit\Audit;

use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Models\NationSignIn;
use App\Services\Audit\NationAuditMapper;
use Carbon\Carbon;
use Tests\TestCase;

class NationAuditMapperTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_builds_flat_nation_context_with_derived_fields(): void
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 11, 0, 0, 0, 'UTC'));
        $nation = new Nation([
            'id' => 1,
            'alliance_id' => 10,
            'alliance_position' => 'MEMBER',
            'nation_name' => 'Test Nation',
            'leader_name' => 'Tester',
            'continent' => 'AF',
            'war_policy' => 'BLITZKRIEG',
            'domestic_policy' => 'URBANIZATION',
            'color' => 'blue',
            'num_cities' => 5,
            'score' => 1234.56,
            'population' => 5000000,
            'projects' => 12,
            'project_bits' => '1010',
            'wars_won' => 4,
            'wars_lost' => 1,
            'offensive_wars_count' => 2,
            'defensive_wars_count' => 1,
            'gross_national_income' => 1000000,
            'gross_domestic_product' => 2000000,
            'commendations' => 3,
            'denouncements' => 1,
        ]);
        $nation->syncOriginal();

        $nation->setRelation('resources', new NationResources([
            'money' => 1000,
            'coal' => 5,
            'oil' => 10,
            'uranium' => 2,
            'iron' => 7,
            'bauxite' => 3,
            'lead' => 4,
            'gasoline' => 9,
            'munitions' => 8,
            'steel' => 6,
            'aluminum' => 11,
            'food' => 12,
            'credits' => 2,
        ]));

        $nation->setRelation('military', new NationMilitary([
            'soldiers' => 10000,
            'tanks' => 500,
            'aircraft' => 200,
            'ships' => 50,
            'missiles' => 5,
            'nukes' => 1,
            'spies' => 10,
        ]));

        $nation->setRelation('accountProfile', new NationAccount([
            'credits' => 25,
            'discord_id' => '123456',
            'last_active' => Carbon::create(2024, 1, 1, 0, 0, 0),
        ]));

        $latestSignIn = new NationSignIn;
        $latestSignIn->mmr_score = 85;
        $nation->setRelation('latestSignIn', $latestSignIn);

        $mapper = new NationAuditMapper;
        $context = $mapper->buildContext($nation);

        $this->assertArrayNotHasKey('nation', $context);
        $this->assertSame(1, $context['nation.id']);
        $this->assertSame(1234.56, $context['nation.score']);
        $this->assertSame(1000, $context['nation.money']);
        $this->assertSame(500, $context['nation.tanks']);
        $this->assertSame(100.0, $context['nation.tanks_per_city']);
        $this->assertSame(40.0, $context['nation.aircraft_per_city']);
        $this->assertSame(10.0, $context['nation.ships_per_city']);
        $this->assertSame(25, $context['nation.account_credits']);
        $this->assertSame('2024-01-01T00:00:00+00:00', $context['nation.last_activity']);
        $this->assertEquals(10, $context['nation.days_since_last_activity']);
        $this->assertTrue($context['nation.discord_account_linked']);
        $this->assertSame(85, $context['nation.mmr_score']);
        $this->assertIsArray($context['nation.projects']);
        foreach ($context['nation.projects'] as $project) {
            $this->assertIsString($project);
        }
        $this->assertContains('nation.soldiers_per_city', array_keys($context));
        $this->assertContains('nation.spies_per_city', array_keys($context));
    }

    public function test_unloaded_optional_relations_remain_missing_instead_of_zero(): void
    {
        $nation = new Nation([
            'id' => 2,
            'num_cities' => 0,
            'project_bits' => null,
        ]);
        $nation->syncOriginal();

        $context = (new NationAuditMapper)->buildContext($nation);

        $this->assertNull($context['nation.account_credits']);
        $this->assertNull($context['nation.last_activity']);
        $this->assertNull($context['nation.days_since_last_activity']);
        $this->assertNull($context['nation.discord_account_linked']);
        $this->assertNull($context['nation.aircraft']);
        $this->assertNull($context['nation.aircraft_per_city']);
        $this->assertNull($context['nation.projects']);
    }
}
