<?php

namespace App\Services\Economy;

final class EconomyRules
{
    public const MODEL_VERSION = 2;

    public const NUCLEAR_FUEL_PER_THOUSAND_INFRASTRUCTURE = 3.0;

    public const NUKE_POLLUTION_TURNS = 11 * 12;

    public const NUKE_POLLUTION_MAX = 400;

    public const WORLD_SNAPSHOT_MAX_AGE_HOURS = 3;

    public const ECONOMY_CONTEXT_MAX_AGE_HOURS = 6;

    public const RESOURCE_KEYS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    public const TRADE_RESOURCES = [
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    public const POWER_FIELDS = [
        'coal_power',
        'oil_power',
        'nuclear_power',
        'wind_power',
    ];

    public const RAW_BUILDINGS = [
        'coal_mine' => 'coal',
        'oil_well' => 'oil',
        'uranium_mine' => 'uranium',
        'lead_mine' => 'lead',
        'iron_mine' => 'iron',
        'bauxite_mine' => 'bauxite',
        'farm' => 'food',
    ];

    public const MANUFACTURING_BUILDINGS = [
        'oil_refinery' => 'gasoline',
        'steel_mill' => 'steel',
        'aluminum_refinery' => 'aluminum',
        'munitions_factory' => 'munitions',
    ];

    public const SUPPORT_FIELDS = [
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
    ];

    public const MILITARY_BUILDING_FIELDS = [
        'barracks',
        'factory',
        'hangar',
        'drydock',
    ];

    public const BUILD_FIELDS = [
        ...self::POWER_FIELDS,
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'farm',
        'oil_refinery',
        'aluminum_refinery',
        'munitions_factory',
        'steel_mill',
        ...self::SUPPORT_FIELDS,
        ...self::MILITARY_BUILDING_FIELDS,
    ];

    private function __construct() {}

    public static function emptyResourceBuffer(): array
    {
        return array_fill_keys(self::RESOURCE_KEYS, 0.0);
    }

    public static function improvementCap(string $field, callable $hasProject): int
    {
        return match ($field) {
            'coal_mine', 'oil_well', 'lead_mine', 'iron_mine', 'bauxite_mine' => 10,
            'uranium_mine' => 5,
            'farm' => 20,
            'oil_refinery', 'aluminum_refinery', 'munitions_factory', 'steel_mill' => 5,
            'subway' => 1,
            'supermarket' => 4,
            'bank' => $hasProject('international_trade_center') ? 6 : 5,
            'shopping_mall' => $hasProject('telecommunications_satellite') ? 5 : 4,
            'stadium' => 3,
            'police_station' => 5,
            'hospital' => $hasProject('clinical_research_center') ? 6 : 5,
            'recycling_center' => $hasProject('recycling_initiative') ? 4 : 3,
            'barracks', 'factory', 'hangar' => 5,
            'drydock' => 3,
            default => 0,
        };
    }

    public static function isFieldAllowed(string $field, ?string $continent): bool
    {
        return match ($field) {
            'coal_mine' => in_array(strtoupper((string) $continent), ['NA', 'EU', 'AU', 'AN'], true),
            'oil_well' => in_array(strtoupper((string) $continent), ['SA', 'AF', 'AS', 'AN'], true),
            'uranium_mine' => in_array(strtoupper((string) $continent), ['NA', 'AF', 'AS', 'AN'], true),
            'lead_mine' => in_array(strtoupper((string) $continent), ['SA', 'EU', 'AU'], true),
            'iron_mine' => in_array(strtoupper((string) $continent), ['NA', 'EU', 'AS'], true),
            'bauxite_mine' => in_array(strtoupper((string) $continent), ['SA', 'AF', 'AU'], true),
            default => true,
        };
    }

    public static function powerCapacity(string $field): int
    {
        return match ($field) {
            'coal_power', 'oil_power' => 500,
            'nuclear_power' => 2000,
            'wind_power' => 250,
            default => 0,
        };
    }

    public static function buildingMoneyUpkeep(string $building, callable $hasProject): float
    {
        $base = match ($building) {
            'coal_power' => 1200.0,
            'oil_power' => 1800.0,
            'nuclear_power' => 10500.0,
            'wind_power' => 500.0,
            'coal_mine' => 400.0,
            'oil_well' => 600.0,
            'uranium_mine' => 5000.0,
            'lead_mine' => 1500.0,
            'iron_mine', 'bauxite_mine' => 1600.0,
            'farm' => 300.0,
            'oil_refinery', 'steel_mill' => 4000.0,
            'aluminum_refinery' => 2500.0,
            'munitions_factory' => 3500.0,
            'subway' => 3250.0,
            'shopping_mall' => 5400.0,
            'stadium' => 12150.0,
            'bank' => 1800.0,
            'supermarket' => 600.0,
            'police_station' => 750.0,
            'hospital' => 1000.0,
            'recycling_center' => 2500.0,
            default => 0.0,
        };

        if (
            $hasProject('green_technologies')
            && (array_key_exists($building, self::RAW_BUILDINGS) || array_key_exists($building, self::MANUFACTURING_BUILDINGS))
        ) {
            return $base * 0.9;
        }

        return $base;
    }

    public static function pollutionContribution(string $field, int $count, callable $hasProject): int
    {
        $perBuilding = match ($field) {
            'coal_power' => 8,
            'oil_power' => 6,
            'nuclear_power', 'wind_power' => 0,
            'coal_mine', 'oil_well', 'lead_mine', 'iron_mine', 'bauxite_mine' => 12,
            'uranium_mine' => 20,
            'farm' => $hasProject('green_technologies') ? 1 : 2,
            'oil_refinery', 'munitions_factory' => $hasProject('green_technologies') ? 24 : 32,
            'steel_mill', 'aluminum_refinery' => $hasProject('green_technologies') ? 30 : 40,
            'subway' => $hasProject('green_technologies') ? -70 : -45,
            'shopping_mall' => 2,
            'stadium' => 5,
            'police_station' => 1,
            'hospital' => 4,
            'recycling_center' => $hasProject('recycling_initiative') ? -75 : -70,
            default => 0,
        };

        return $perBuilding * $count;
    }

    public static function commerceContribution(string $field, int $count): int
    {
        return match ($field) {
            'subway', 'shopping_mall' => 8 * $count,
            'stadium' => 10 * $count,
            'bank' => 6 * $count,
            'supermarket' => 4 * $count,
            default => 0,
        };
    }
}
