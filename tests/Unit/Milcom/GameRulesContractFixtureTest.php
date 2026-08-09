<?php

namespace Tests\Unit\Milcom;

use Tests\TestCase;

class GameRulesContractFixtureTest extends TestCase
{
    public function test_versioned_configuration_matches_the_enablement_contract_fixture(): void
    {
        $fixture = require base_path('tests/Fixtures/Milcom/game_rules_contract.php');

        $this->assertSame($fixture['version'], config('milcom.game_rules.contract_version'));
        $this->assertSame($fixture['base_offensive_slots'], config('milcom.game_rules.base_offensive_slots'));
        $this->assertSame(
            $fixture['declaration_score_minimum_multiplier'],
            config('milcom.game_rules.declaration_score_minimum_multiplier')
        );
        $this->assertSame(
            $fixture['declaration_score_maximum_multiplier'],
            config('milcom.game_rules.declaration_score_maximum_multiplier')
        );
        $this->assertSame(
            $fixture['offensive_slot_projects'],
            config('milcom.game_rules.offensive_slot_projects')
        );

        foreach ($fixture['non_slot_projects'] as $project) {
            $this->assertArrayNotHasKey($project, config('milcom.game_rules.offensive_slot_projects'));
        }
    }

    public function test_v2_is_the_default_while_contract_verification_remains_visible(): void
    {
        $this->assertFalse(config('milcom.v1_enabled'));
        $this->assertTrue(config('milcom.v2_requested'));
        $this->assertTrue(config('milcom.v2_enabled'));
        $this->assertIsBool(config('milcom.game_rules.contract_verified'));
    }
}
