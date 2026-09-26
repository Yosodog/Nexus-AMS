<?php

namespace App\Services\Raids;

use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\WarSimulator\Support\WarSimModifiers;

/**
 * Determines the fraction of a loser's stockpile taken by a victory.
 */
final class RaidLootFraction
{
    private const LOOT_INFO_PATTERN = '/(?:loot|fraction)[^0-9%]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%/i';

    /**
     * Read the fraction from the attack's loot report, when it states one.
     */
    public function fromLootInfo(?string $lootInfo): ?float
    {
        if ($lootInfo === null || preg_match(self::LOOT_INFO_PATTERN, $lootInfo, $matches) !== 1) {
            return null;
        }

        $fraction = (float) $matches[1] / 100;

        return $fraction > 0.0 && $fraction < 1.0 ? $fraction : null;
    }

    /**
     * Derive the fraction from war type, policies, and the winner's loot projects.
     */
    public function fromModifiers(
        string $warType,
        ?string $winnerPolicy,
        ?string $loserPolicy,
        bool $winnerPirateEconomy,
        bool $winnerAdvancedPirateEconomy,
    ): float {
        $modifiers = WarSimModifiers::forLoot(
            $warType,
            (string) $winnerPolicy,
            (string) $loserPolicy,
            $winnerPirateEconomy,
            $winnerAdvancedPirateEconomy,
        );

        return RaidLootFormula::victoryLootFraction($modifiers->victoryLootMultiplier());
    }
}
