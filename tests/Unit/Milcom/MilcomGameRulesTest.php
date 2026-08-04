<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\MilcomGameRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Milcom\Concerns\BuildsReadinessProfiles;

class MilcomGameRulesTest extends TestCase
{
    use BuildsReadinessProfiles;

    private MilcomGameRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new MilcomGameRules;
    }

    #[Test]
    public function only_pirate_economy_projects_increase_offensive_capacity(): void
    {
        $this->assertSame([
            'pirate_economy' => 1,
            'advanced_pirate_economy' => 1,
        ], config('milcom.game_rules.offensive_slot_projects'));

        $nation = $this->profile([
            'projects' => [
                'pirate_economy' => true,
                'advanced_pirate_economy' => true,
                'space_program' => true,
                'arms_stockpile' => true,
                'advanced_urban_planning' => true,
                'activity_center' => true,
                'vital_defense_system' => true,
            ],
        ]);

        $this->assertSame(7, $this->rules->baseOffensiveCapacity($nation));
        $this->assertSame(5, $this->rules->baseOffensiveCapacity($this->profile()));
        $this->assertSame(6, $this->rules->baseOffensiveCapacity($this->profile([
            'projects' => ['pirate_economy' => true],
        ])));
        $this->assertSame(6, $this->rules->baseOffensiveCapacity($this->profile([
            'projects' => ['advanced_pirate_economy' => true],
        ])));
    }

    #[Test]
    public function offensive_slot_math_subtracts_only_active_offensive_wars_and_reservations(): void
    {
        $nation = $this->profile([
            'activeOffensiveWars' => 4,
            'reservedOffensiveSlots' => 2,
            'projects' => [
                'pirate_economy' => true,
                'advanced_pirate_economy' => true,
            ],
        ]);

        $this->assertSame([
            'base' => 5,
            'project_modifiers' => 2,
            'active_offensive_wars' => 4,
            'reservations' => 2,
            'available' => 1,
        ], $this->rules->offensiveSlotMath($nation));
        $this->assertSame(1, $this->rules->availableOffensiveSlots($nation));

        $exhausted = $this->profile([
            'activeOffensiveWars' => 8,
            'reservedOffensiveSlots' => 3,
        ]);

        $this->assertSame(0, $this->rules->availableOffensiveSlots($exhausted));
    }

    #[Test]
    public function declaration_score_range_includes_both_boundaries_and_rejects_values_outside_them(): void
    {
        $attacker = $this->profile(['score' => 1000.0]);

        $this->assertSame([
            'minimum' => 750.0,
            'maximum' => 2500.0,
        ], $this->rules->declarationRange($attacker));
        $this->assertTrue($this->rules->isInDeclarationRange(
            $attacker,
            $this->profile(['nationId' => 2, 'score' => 750.0])
        ));
        $this->assertTrue($this->rules->isInDeclarationRange(
            $attacker,
            $this->profile(['nationId' => 2, 'score' => 2500.0])
        ));
        $this->assertFalse($this->rules->isInDeclarationRange(
            $attacker,
            $this->profile(['nationId' => 2, 'score' => 749.99])
        ));
        $this->assertFalse($this->rules->isInDeclarationRange(
            $attacker,
            $this->profile(['nationId' => 2, 'score' => 2500.01])
        ));
    }
}
