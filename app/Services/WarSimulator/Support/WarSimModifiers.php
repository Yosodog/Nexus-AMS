<?php

namespace App\Services\WarSimulator\Support;

/**
 * Shared modifiers for the single-action and sequential war simulators.
 *
 * The public nation page records the Pirate policy's forty-percent loot
 * adjustment. Policy combinations are applied additively here so each side's
 * published adjustment is preserved without compounding unrelated policies.
 *
 * @see https://politicsandwar.com/nation/id%3D668790
 */
final readonly class WarSimModifiers
{
    public function __construct(
        public float $warTypeInfraFactor,
        public float $warTypeLootFactor,
        public float $attackerLootPolicyFactor,
        public float $defenderLootPolicyFactor,
        public float $attackerInfraPolicyFactor,
        public float $defenderInfraPolicyFactor,
        public float $attackerBlitzFactor,
        public float $defenderBlitzFactor,
        public float $attackerTankStrengthFactor,
        public float $defenderTankStrengthFactor,
        public float $attackerCasualtyFactor,
        public float $defenderCasualtyFactor,
        public float $attackerGroundLootProjectFactor = 1.0,
        public float $attackerVictoryLootProjectFactor = 1.0,
        public float $attackerBankLootProjectFactor = 1.0,
    ) {}

    /**
     * Build the loot-only modifiers for a war; non-loot factors are neutral.
     */
    public static function forLoot(
        string $warType,
        string $attackerPolicy,
        string $defenderPolicy,
        bool $pirateEconomy,
        bool $advancedPirateEconomy,
    ): self {
        return new self(
            warTypeInfraFactor: 1.0,
            warTypeLootFactor: match (strtoupper($warType)) {
                'ATTRITION' => 0.25,
                'RAID' => 1.0,
                default => 0.5,
            },
            attackerLootPolicyFactor: strtoupper($attackerPolicy) === 'PIRATE' ? 1.4 : 1.0,
            defenderLootPolicyFactor: match (strtoupper($defenderPolicy)) {
                'MONEYBAGS' => 0.6,
                'GUARDIAN' => 0.8,
                default => 1.0,
            },
            attackerInfraPolicyFactor: 1.0,
            defenderInfraPolicyFactor: 1.0,
            attackerBlitzFactor: 1.0,
            defenderBlitzFactor: 1.0,
            attackerTankStrengthFactor: 1.0,
            defenderTankStrengthFactor: 1.0,
            attackerCasualtyFactor: 1.0,
            defenderCasualtyFactor: 1.0,
            attackerGroundLootProjectFactor: ($pirateEconomy ? 1.05 : 1.0) * ($advancedPirateEconomy ? 1.05 : 1.0),
            attackerVictoryLootProjectFactor: $advancedPirateEconomy ? 1.10 : 1.0,
            attackerBankLootProjectFactor: $advancedPirateEconomy ? 1.10 : 1.0,
        );
    }

    public function lootMultiplier(): float
    {
        return $this->warTypeLootFactor
            * $this->lootPolicyMultiplier()
            * $this->attackerGroundLootProjectFactor;
    }

    public function victoryLootMultiplier(): float
    {
        return $this->warTypeLootFactor
            * $this->lootPolicyMultiplier()
            * $this->attackerVictoryLootProjectFactor;
    }

    public function bankLootMultiplier(): float
    {
        return $this->warTypeLootFactor
            * $this->lootPolicyMultiplier()
            * $this->attackerBankLootProjectFactor;
    }

    /**
     * Combine policy adjustments against one loot pool before applying
     * project-specific multipliers.
     */
    private function lootPolicyMultiplier(): float
    {
        return max(
            0.0,
            1.0
                + ($this->attackerLootPolicyFactor - 1.0)
                + ($this->defenderLootPolicyFactor - 1.0),
        );
    }

    public function infraMultiplier(): float
    {
        return $this->warTypeInfraFactor
            * $this->attackerInfraPolicyFactor
            * $this->defenderInfraPolicyFactor
            * $this->attackerBlitzFactor;
    }
}
