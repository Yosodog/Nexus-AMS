<?php

namespace App\DataTransferObjects\Raids;

/**
 * The attacking nation's state used by raid valuation.
 */
final readonly class RaidAttacker
{
    /**
     * @param  array<string, float>|null  $resources  12 resource keys, or null when private
     * @param  array<string, int>  $militaryResearch  keyed by MilitaryCostCalculator::RESEARCH_FIELDS
     */
    public function __construct(
        public int $nationId,
        public float $score,
        public int $allianceId,
        public string $warPolicy,
        public string $domesticPolicy,
        public int $soldiers,
        public int $tanks,
        public int $aircraft,
        public int $ships,
        public ?array $resources,
        public bool $privateResources,
        public bool $pirateEconomy,
        public bool $advancedPirateEconomy,
        public bool $governmentSupportAgency,
        public bool $bureauOfDomesticAffairs,
        public array $militaryResearch,
        public int $offensiveWars,
        public int $offensiveCapacity,
        public int $vacationModeTurns = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nation_id' => $this->nationId,
            'score' => $this->score,
            'alliance_id' => $this->allianceId,
            'war_policy' => $this->warPolicy,
            'domestic_policy' => $this->domesticPolicy,
            'soldiers' => $this->soldiers,
            'tanks' => $this->tanks,
            'aircraft' => $this->aircraft,
            'ships' => $this->ships,
            'resources' => $this->resources,
            'private_resources' => $this->privateResources,
            'pirate_economy' => $this->pirateEconomy,
            'advanced_pirate_economy' => $this->advancedPirateEconomy,
            'government_support_agency' => $this->governmentSupportAgency,
            'bureau_of_domestic_affairs' => $this->bureauOfDomesticAffairs,
            'military_research' => $this->militaryResearch,
            'offensive_wars' => $this->offensiveWars,
            'offensive_capacity' => $this->offensiveCapacity,
            'vacation_mode_turns' => $this->vacationModeTurns,
        ];
    }
}
