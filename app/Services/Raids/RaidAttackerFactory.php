<?php

namespace App\Services\Raids;

use App\DataTransferObjects\Raids\RaidAttacker;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Models\RaidTargetProfile;
use App\Services\AllianceMembershipService;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\RuntimeCapabilities;

/**
 * Loads the attacking nation's current state for raid valuation.
 */
final class RaidAttackerFactory
{
    private const UNITS = ['soldiers', 'tanks', 'aircraft', 'ships'];

    public function __construct(
        private AllianceMembershipService $membership,
        private RuntimeCapabilities $capabilities,
    ) {}

    public function forNation(int $nationId): RaidAttacker
    {
        $nation = Nation::query()
            ->select('nations.*')
            ->addSelect(collect(self::UNITS)->mapWithKeys(fn (string $unit): array => [
                'military_'.$unit => NationMilitary::query()->select($unit)->whereColumn('nation_id', 'nations.id')->limit(1),
            ])->all())
            ->findOrFail($nationId);
        $projects = $nation->projects;
        $military = $nation->getAttribute('military_soldiers') === null
            ? RaidTargetProfile::query()->find($nationId)?->only(self::UNITS)
            : collect(self::UNITS)->mapWithKeys(fn (string $unit): array => [$unit => $nation->getAttribute('military_'.$unit)])->all();
        $resources = $this->resources($nation);
        $offensiveCapacity = (int) config('milcom.game_rules.base_offensive_slots');

        foreach ((array) config('milcom.game_rules.offensive_slot_projects') as $project => $modifier) {
            if ((bool) ($projects[$project] ?? false)) {
                $offensiveCapacity += (int) $modifier;
            }
        }

        return new RaidAttacker(
            nationId: (int) $nation->id,
            score: (float) $nation->score,
            allianceId: (int) $nation->alliance_id,
            warPolicy: (string) $nation->war_policy,
            domesticPolicy: (string) $nation->domestic_policy,
            soldiers: max(0, (int) ($military['soldiers'] ?? 0)),
            tanks: max(0, (int) ($military['tanks'] ?? 0)),
            aircraft: max(0, (int) ($military['aircraft'] ?? 0)),
            ships: max(0, (int) ($military['ships'] ?? 0)),
            resources: $resources,
            privateResources: $resources === null,
            pirateEconomy: (bool) ($projects['pirate_economy'] ?? false),
            advancedPirateEconomy: (bool) ($projects['advanced_pirate_economy'] ?? false),
            governmentSupportAgency: (bool) ($projects['government_support_agency'] ?? false),
            bureauOfDomesticAffairs: (bool) ($projects['bureau_of_domestic_affairs'] ?? false),
            militaryResearch: collect(MilitaryCostCalculator::RESEARCH_FIELDS)
                ->mapWithKeys(fn (string $field): array => [$field => (int) $nation->getAttribute($field.'_research')])
                ->all(),
            offensiveWars: max(0, (int) $nation->offensive_wars_count),
            offensiveCapacity: $offensiveCapacity,
            vacationModeTurns: max(0, (int) $nation->vacation_mode_turns),
        );
    }

    /**
     * Member stockpiles are private tenant data; other nations' resources are unknown.
     *
     * @return array<string, float>|null
     */
    private function resources(Nation $nation): ?array
    {
        if (! $this->capabilities->writesTenantPrivate() || ! $this->membership->contains($nation->alliance_id)) {
            return null;
        }

        $resources = NationResources::query()->where('nation_id', $nation->id)->latest('updated_at')->first();

        return $resources === null
            ? null
            : collect(EconomyRules::RESOURCE_KEYS)
                ->mapWithKeys(fn (string $resource): array => [$resource => (float) $resources->getAttribute($resource)])
                ->all();
    }
}
