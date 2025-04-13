<?php

namespace App\Services;

use App\GraphQL\Models\War as WarGraphQL;
use Throwable;

class LootCalculatorService
{
    /**
     * @param TradePriceService $tradePriceService
     */
    public function __construct(protected TradePriceService $tradePriceService)
    {
    }

    /**
     * Calculate total estimated loot value using 24h average prices.
     *
     * @param WarGraphQL $war
     * @return int
     */
    public function calculateFromGraphQLWar(WarGraphQL $war): int
    {
        $value = 0;
        $prices = $this->tradePriceService->get24hAverage();

        if (empty($war->attacks) || !is_iterable($war->attacks)) {
            return 0;
        }

        foreach ($war->attacks as $attack) {
            try {
                $value += (int)($attack->money_looted ?? 0);
                $value += (int)($attack->money_stolen ?? 0);
                $value += (int)(($attack->coal_looted ?? 0) * $prices->coal);
                $value += (int)(($attack->oil_looted ?? 0) * $prices->oil);
                $value += (int)(($attack->uranium_looted ?? 0) * $prices->uranium);
                $value += (int)(($attack->iron_looted ?? 0) * $prices->iron);
                $value += (int)(($attack->bauxite_looted ?? 0) * $prices->bauxite);
                $value += (int)(($attack->lead_looted ?? 0) * $prices->lead);
                $value += (int)(($attack->gasoline_looted ?? 0) * $prices->gasoline);
                $value += (int)(($attack->munitions_looted ?? 0) * $prices->munitions);
                $value += (int)(($attack->steel_looted ?? 0) * $prices->steel);
                $value += (int)(($attack->aluminum_looted ?? 0) * $prices->aluminum);
                $value += (int)(($attack->food_looted ?? 0) * $prices->food);
            } catch (Throwable) {
                continue;
            }
        }

        return $value;
    }
}