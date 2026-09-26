<?php

namespace App\DataTransferObjects\Raids;

/**
 * Expected return of raiding one target, as shown to members and frozen at declaration.
 */
final readonly class RaidValuation
{
    /**
     * @param  array<string, float>  $components  gross_loot, nation_loot, ground_loot, bank_loot, bounty,
     *                                            consumables, military_losses, infrastructure_losses, counter_risk
     * @param  array<string, float>  $lootResources
     * @param  array<string, float>  $costResources
     * @param  array{resources: array<string, float>, value: float, low_value: float, high_value: float, evidence_kind: string, evidence_at: string|null, evidence_age_hours: float, retention: float, activity_bucket: string}  $stockpile
     * @param  list<string>  $plan
     * @param  list<string>  $assumptions
     */
    public function __construct(
        public float $expectedNet,
        public float $expectedNetLow,
        public float $expectedNetHigh,
        public float $grossLoot,
        public float $winProbability,
        public float $victoryProbability,
        public float $beigeShare,
        public int $expectedAttacks,
        public float $durationHours,
        public string $confidence,
        public float $counterProbability,
        public array $components,
        public array $lootResources,
        public array $costResources,
        public array $stockpile,
        public array $plan,
        public array $assumptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'expected_net' => $this->expectedNet,
            'expected_net_low' => $this->expectedNetLow,
            'expected_net_high' => $this->expectedNetHigh,
            'gross_loot' => $this->grossLoot,
            'win_probability' => $this->winProbability,
            'victory_probability' => $this->victoryProbability,
            'beige_share' => $this->beigeShare,
            'expected_attacks' => $this->expectedAttacks,
            'duration_hours' => $this->durationHours,
            'confidence' => $this->confidence,
            'counter_probability' => $this->counterProbability,
            'components' => $this->components,
            'loot_resources' => $this->lootResources,
            'cost_resources' => $this->costResources,
            'stockpile' => $this->stockpile,
            'plan' => $this->plan,
            'assumptions' => $this->assumptions,
        ];
    }
}
