<?php

namespace App\Services\Raids;

use App\DataTransferObjects\MarketPriceSet;
use App\Services\Economy\EconomyRules;

/**
 * Resource-map helpers shared by the raid estimator, valuation, and backtests.
 */
final class RaidResources
{
    /** @var list<string> */
    public const RAW = ['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'food'];

    /** @var list<string> */
    public const MANUFACTURED = ['gasoline', 'munitions', 'steel', 'aluminum'];

    /**
     * @return array<string, float>
     */
    public static function empty(): array
    {
        return array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0);
    }

    /**
     * Value a resource map at liquidation (sell-side) prices. Unpriced resources count as zero.
     *
     * @param  array<string, float|int>  $resources
     */
    public static function liquidationValue(array $resources, MarketPriceSet $prices): float
    {
        return self::value($resources, $prices->liquidationPricesWithMoney());
    }

    /**
     * Value a resource map at acquisition (buy-side) prices. Unpriced resources count as zero.
     *
     * @param  array<string, float|int>  $resources
     */
    public static function acquisitionValue(array $resources, MarketPriceSet $prices): float
    {
        return self::value($resources, ['money' => 1.0] + $prices->acquisitionPrices);
    }

    /**
     * @param  array<string, float|int>  $resources
     * @return array<string, float>
     */
    public static function rounded(array $resources): array
    {
        return array_map(fn (float|int $amount): float => round((float) $amount, 2), $resources);
    }

    /**
     * @param  array<string, float|int>  $resources
     * @param  array<string, float>  $priceMap
     */
    private static function value(array $resources, array $priceMap): float
    {
        $value = 0.0;

        foreach ($resources as $resource => $amount) {
            $value += (float) $amount * (float) ($priceMap[$resource] ?? 0.0);
        }

        return round($value, 2);
    }
}
