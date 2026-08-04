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

    public function test_v2_cannot_be_requested_without_an_explicit_live_contract_acknowledgement(): void
    {
        config()->set('milcom.v2_requested', true);
        config()->set('milcom.game_rules.contract_verified', false);
        config()->set('milcom.v2_enabled', false);

        $this->assertTrue(config('milcom.v2_requested'));
        $this->assertFalse(config('milcom.game_rules.contract_verified'));
        $this->assertFalse(config('milcom.v2_enabled'));
    }
}
