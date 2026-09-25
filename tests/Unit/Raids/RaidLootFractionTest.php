<?php

namespace Tests\Unit\Raids;

use App\Services\Raids\RaidLootFraction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RaidLootFractionTest extends TestCase
{
    public function test_loot_report_percentage_is_parsed(): void
    {
        $fractions = new RaidLootFraction;

        $this->assertEqualsWithDelta(0.125, $fractions->fromLootInfo('Raider won the war and looted 12.5% of the defender resources.'), 1e-9);
        $this->assertEqualsWithDelta(0.1, $fractions->fromLootInfo('Loot fraction: 10 %'), 1e-9);
    }

    #[DataProvider('invalidLootInfo')]
    public function test_invalid_loot_reports_are_ignored(?string $lootInfo): void
    {
        $this->assertNull((new RaidLootFraction)->fromLootInfo($lootInfo));
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidLootInfo(): iterable
    {
        yield 'null' => [null];
        yield 'no percentage' => ['looted $1,000,000 and 50 steel'];
        yield 'zero percent' => ['looted 0% of resources'];
        yield 'over one hundred percent' => ['looted 150% of resources'];
        yield 'percentage without loot wording' => ['infrastructure fell by 12%'];
    }

    #[DataProvider('modifierCases')]
    public function test_modifiers_follow_war_type_policies_and_projects(
        string $warType,
        ?string $winnerPolicy,
        ?string $loserPolicy,
        bool $pirateEconomy,
        bool $advancedPirateEconomy,
        float $expected,
    ): void {
        $this->assertEqualsWithDelta(
            $expected,
            (new RaidLootFraction)->fromModifiers($warType, $winnerPolicy, $loserPolicy, $pirateEconomy, $advancedPirateEconomy),
            1e-9,
        );
    }

    /** @return iterable<string, array{string, string|null, string|null, bool, bool, float}> */
    public static function modifierCases(): iterable
    {
        yield 'raid' => ['RAID', 'TURTLE', 'TURTLE', false, false, 0.10];
        yield 'ordinary' => ['ORDINARY', null, null, false, false, 0.05];
        yield 'attrition' => ['ATTRITION', null, null, false, false, 0.025];
        yield 'pirate winner' => ['RAID', 'PIRATE', null, false, false, 0.14];
        yield 'moneybags loser' => ['RAID', null, 'MONEYBAGS', false, false, 0.06];
        yield 'guardian loser' => ['RAID', null, 'GUARDIAN', false, false, 0.08];
        yield 'pirate against moneybags' => ['RAID', 'PIRATE', 'MONEYBAGS', false, false, 0.10];
        yield 'pirate economy only affects ground loot' => ['RAID', null, null, true, false, 0.10];
        yield 'advanced pirate economy' => ['RAID', null, null, true, true, 0.11];
    }
}
