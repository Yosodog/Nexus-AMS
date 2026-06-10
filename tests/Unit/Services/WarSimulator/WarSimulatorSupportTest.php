<?php

namespace Tests\Unit\Services\WarSimulator;

use App\Services\WarSimulator\Support\PercentileCalculator;
use App\Services\WarSimulator\Support\WarSimModifiers;
use App\Services\WarSimulator\Support\WarSimRng;
use Tests\UnitTestCase;

class WarSimulatorSupportTest extends UnitTestCase
{
    public function test_percentile_summary_interpolates_sorted_values(): void
    {
        $summary = PercentileCalculator::summarize([10, 30, 20, 40]);

        $this->assertEqualsWithDelta(25.0, $summary['mean'], 0.00001);
        $this->assertEqualsWithDelta(13.0, $summary['p10'], 0.00001);
        $this->assertEqualsWithDelta(25.0, $summary['p50'], 0.00001);
        $this->assertEqualsWithDelta(37.0, $summary['p90'], 0.00001);
    }

    public function test_seeded_rng_is_reproducible_and_clamps_invalid_ranges(): void
    {
        $first = new WarSimRng(123);
        $second = new WarSimRng(123);

        $this->assertSame($first->nextFloat(0, 1), $second->nextFloat(0, 1));
        $this->assertSame(5.0, $first->nextFloat(5, 5));
        $this->assertSame(7.0, $first->nextFloat(7, 3));
    }

    public function test_modifiers_multiply_loot_and_infrastructure_factors(): void
    {
        $modifiers = new WarSimModifiers(
            warTypeInfraFactor: 0.5,
            warTypeLootFactor: 1.0,
            attackerLootPolicyFactor: 1.4,
            defenderLootPolicyFactor: 0.6,
            attackerInfraPolicyFactor: 1.1,
            defenderInfraPolicyFactor: 0.9,
            attackerBlitzFactor: 1.1,
            defenderBlitzFactor: 1.0,
            attackerTankStrengthFactor: 1.0,
            defenderTankStrengthFactor: 1.0,
            attackerCasualtyFactor: 1.0,
            defenderCasualtyFactor: 1.0,
        );

        $this->assertEqualsWithDelta(0.84, $modifiers->lootMultiplier(), 0.00001);
        $this->assertEqualsWithDelta(0.5445, $modifiers->infraMultiplier(), 0.00001);
    }
}
