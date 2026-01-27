<?php

namespace App\Services\WarSimulator\Support;

final class WarSimModifiers
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
    ) {}

    public function lootMultiplier(): float
    {
        return $this->warTypeLootFactor
            * $this->attackerLootPolicyFactor
            * $this->defenderLootPolicyFactor;
    }

    public function infraMultiplier(): float
    {
        return $this->warTypeInfraFactor
            * $this->attackerInfraPolicyFactor
            * $this->defenderInfraPolicyFactor
            * $this->attackerBlitzFactor;
    }
}
