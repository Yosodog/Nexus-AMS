<?php

namespace Database\Migrations\Support\AuditRuleMigration;

final class LegacyFieldCatalog
{
    /** @var list<string> */
    private const NATION_NUMBERS = [
        'nation.id',
        'nation.alliance_id',
        'nation.num_cities',
        'nation.score',
        'nation.population',
        'nation.projects_count',
        'nation.wars_won',
        'nation.wars_lost',
        'nation.offensive_wars_count',
        'nation.defensive_wars_count',
        'nation.gni',
        'nation.gdp',
        'nation.commendations',
        'nation.denouncements',
        'nation.money',
        'nation.coal',
        'nation.oil',
        'nation.uranium',
        'nation.iron',
        'nation.bauxite',
        'nation.lead',
        'nation.gasoline',
        'nation.munitions',
        'nation.steel',
        'nation.aluminum',
        'nation.food',
        'nation.credits',
        'nation.soldiers',
        'nation.tanks',
        'nation.aircraft',
        'nation.ships',
        'nation.missiles',
        'nation.nukes',
        'nation.spies',
        'nation.account_credits',
        'nation.mmr_score',
    ];

    /** @var list<string> */
    private const NATION_TEXT = [
        'nation.alliance_position',
        'nation.nation_name',
        'nation.leader_name',
        'nation.continent',
        'nation.war_policy',
        'nation.domestic_policy',
        'nation.color',
    ];

    /** @var list<string> */
    private const CITY_NATION_NUMBERS = [
        'nation.id',
        'nation.score',
        'nation.num_cities',
    ];

    /** @var list<string> */
    private const CITY_NATION_TEXT = [
        'nation.nation_name',
        'nation.leader_name',
        'nation.color',
    ];

    /** @var list<string> */
    private const CITY_NUMBERS = [
        'city.id',
        'city.infrastructure',
        'city.land',
        'city.oil_power',
        'city.wind_power',
        'city.coal_power',
        'city.nuclear_power',
        'city.coal_mine',
        'city.oil_well',
        'city.uranium_mine',
        'city.farm',
        'city.barracks',
        'city.police_station',
        'city.hospital',
        'city.recycling_center',
        'city.subway',
        'city.supermarket',
        'city.bank',
        'city.shopping_mall',
        'city.stadium',
        'city.lead_mine',
        'city.iron_mine',
        'city.bauxite_mine',
        'city.oil_refinery',
        'city.aluminum_refinery',
        'city.steel_mill',
        'city.munitions_factory',
        'city.factory',
        'city.hangar',
        'city.drydock',
    ];

    /** @var list<string> */
    private const MILITARY_PER_CITY_FIELDS = [
        'nation.soldiers',
        'nation.tanks',
        'nation.aircraft',
        'nation.ships',
        'nation.missiles',
        'nation.nukes',
        'nation.spies',
    ];

    /** @var list<string> */
    private const PROJECTS = [
        'Ironworks',
        'Bauxiteworks',
        'Arms Stockpile',
        'Emergency Gasoline Reserve',
        'Mass Irrigation',
        'International Trade Center',
        'Missile Launch Pad',
        'Nuclear Research Facility',
        'Iron Dome',
        'Vital Defense System',
        'Central Intelligence Agency',
        'Center for Civil Engineering',
        'Propaganda Bureau',
        'Uranium Enrichment Program',
        'Urban Planning',
        'Advanced Urban Planning',
        'Space Program',
        'Spy Satellite',
        'Moon Landing',
        'Pirate Economy',
        'Recycling Initiative',
        'Telecommunications Satellite',
        'Green Technologies',
        'Arable Land Agency',
        'Clinical Research Center',
        'Specialized Police Training Program',
        'Advanced Engineering Corps',
        'Government Support Agency',
        'Research and Development Center',
        'Activity Center',
        'Metropolitan Planning',
        'Military Salvage',
        'Fallout Shelter',
        'Bureau of Domestic Affairs',
        'Advanced Pirate Economy',
        'Mars Landing',
        'Surveillance Network',
        'Guiding Satellite',
        'Nuclear Launch Facility',
    ];

    /**
     * @return array{field: string, type: 'number'|'text'|'boolean'|'datetime'}|null
     */
    public function describe(string $legacyPath, string $targetType): ?array
    {
        if ($legacyPath === 'nation.last_active' && $targetType === 'nation') {
            return ['field' => 'nation.last_activity', 'type' => 'datetime'];
        }

        if ($legacyPath === 'city.powered' && $targetType === 'city') {
            return ['field' => 'city.powered', 'type' => 'boolean'];
        }

        if ($legacyPath === 'city.name' && $targetType === 'city') {
            return ['field' => 'city.name', 'type' => 'text'];
        }

        if ($targetType === 'nation') {
            if (in_array($legacyPath, self::NATION_NUMBERS, true)) {
                return ['field' => $legacyPath, 'type' => 'number'];
            }

            if (in_array($legacyPath, self::NATION_TEXT, true)) {
                return ['field' => $legacyPath, 'type' => 'text'];
            }
        }

        if ($targetType === 'city') {
            if (in_array($legacyPath, [...self::CITY_NATION_NUMBERS, ...self::CITY_NUMBERS], true)) {
                return ['field' => $legacyPath, 'type' => 'number'];
            }

            if (in_array($legacyPath, self::CITY_NATION_TEXT, true)) {
                return ['field' => $legacyPath, 'type' => 'text'];
            }
        }

        return null;
    }

    public function isKnownLegacyPath(string $legacyPath): bool
    {
        return in_array($legacyPath, [
            ...self::NATION_NUMBERS,
            ...self::NATION_TEXT,
            ...self::CITY_NATION_NUMBERS,
            ...self::CITY_NATION_TEXT,
            ...self::CITY_NUMBERS,
            'nation.last_active',
            'nation.account_discord_id',
            'nation.project_bits',
            'city.name',
            'city.powered',
        ], true);
    }

    public function perCityField(string $legacyPath, string $targetType): ?string
    {
        if ($targetType !== 'nation' || ! in_array($legacyPath, self::MILITARY_PER_CITY_FIELDS, true)) {
            return null;
        }

        return $legacyPath.'_per_city';
    }

    public function isKnownProject(string $project): bool
    {
        return in_array($project, self::PROJECTS, true);
    }
}
