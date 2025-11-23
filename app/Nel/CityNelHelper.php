<?php

namespace App\Nel;

class CityNelHelper
{
    private const IMPROVEMENTS = [
        'oil_power',
        'wind_power',
        'coal_power',
        'nuclear_power',
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'farm',
        'barracks',
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'oil_refinery',
        'aluminum_refinery',
        'steel_mill',
        'munitions_factory',
        'factory',
        'hangar',
        'drydock',
    ];

    /**
     * @return array<string, callable>
     */
    public function bindings(): array
    {
        return [
            'city.improvements_count' => [$this, 'countImprovements'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function functionNames(): array
    {
        return array_keys($this->bindings());
    }

    public function countImprovements(NelEvaluationContext $context): int
    {
        $city = $context->variables['city'] ?? [];
        $total = 0;

        foreach (self::IMPROVEMENTS as $key) {
            $total += (int) ($city[$key] ?? 0);
        }

        return $total;
    }
}
