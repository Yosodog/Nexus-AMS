<?php

namespace Tests\Concerns;

use App\Models\MarketPriceSnapshot;
use App\Services\Economy\EconomyRules;

trait BuildsRaidFixtures
{
    /**
     * Store a current market snapshot where every trade resource has the same price.
     */
    protected function seedRaidMarketPrices(float $price = 100.0): MarketPriceSnapshot
    {
        $snapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'raid test market',
            'window_started_at' => now()->subWeek(),
            'window_ended_at' => now()->subMinute(),
            'calculated_at' => now()->subMinute(),
        ]);

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $snapshot->items()->create([
                'resource' => $resource,
                'acquisition_price' => $price,
                'liquidation_price' => $price,
            ]);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, float|int>  $overrides
     * @return array<string, float|int>
     */
    protected function raidResources(array $overrides = []): array
    {
        return array_replace(array_fill_keys(EconomyRules::RESOURCE_KEYS, 0), $overrides);
    }
}
