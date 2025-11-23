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
    public function test_builds_flat_nation_variables(): void
    {
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
        $variables = $mapper->buildVariables($nation);

        $this->assertArrayHasKey('nation', $variables);
        $this->assertSame(1, $variables['nation']['id']);
        $this->assertSame(1234.56, $variables['nation']['score']);
        $this->assertSame(1000, $variables['nation']['money']);
        $this->assertSame(500, $variables['nation']['tanks']);
        $this->assertSame(25, $variables['nation']['account_credits']);
        $this->assertSame(1704067200, $variables['nation']['last_active']);
        $this->assertSame(85, $variables['nation']['mmr_score']);
    }
}
