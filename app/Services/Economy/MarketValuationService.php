<?php

namespace App\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Models\MarketPriceSnapshot;
use App\Services\TradePriceService;
use Carbon\CarbonImmutable;

final class MarketValuationService
{
    public function __construct(private readonly TradePriceService $tradePriceService) {}

    /**
     * @throws ProfitabilityPricingUnavailable
     */
    public function current(?int $snapshotId = null): MarketPriceSet
    {
        $snapshot = MarketPriceSnapshot::query()
            ->with('items')
            ->when(
                $snapshotId !== null,
                fn ($query) => $query->whereKey($snapshotId),
                fn ($query) => $query
                    ->where('calculated_at', '<=', now()->addMinutes(5))
                    ->latest('calculated_at')
                    ->latest('id')
            )
            ->first();

        if ($snapshot !== null) {
            $ageSeconds = now()->getTimestamp() - $snapshot->calculated_at->getTimestamp();

            if ($ageSeconds >= -300 && $ageSeconds <= 48 * 3600) {
                return $this->fromSnapshot($snapshot, max(0, $ageSeconds) > 6 * 3600);
            }
        }

        if ($snapshotId !== null) {
            throw new ProfitabilityPricingUnavailable(
                "Market price snapshot {$snapshotId} is missing or too old to use."
            );
        }

        return $this->aggregateFallback();
    }

    /**
     * @throws ProfitabilityPricingUnavailable
     */
    private function fromSnapshot(MarketPriceSnapshot $snapshot, bool $stale): MarketPriceSet
    {
        $items = $snapshot->items->keyBy('resource');
        $acquisition = [];
        $liquidation = [];
        $fallbackResources = [];

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $item = $items->get($resource);

            if (
                $item === null
                || (float) $item->acquisition_price <= 0
                || (float) $item->liquidation_price <= 0
            ) {
                throw new ProfitabilityPricingUnavailable("The market snapshot is missing {$resource} prices.");
            }

            $acquisition[$resource] = (float) $item->acquisition_price;
            $liquidation[$resource] = (float) $item->liquidation_price;

            if ($item->acquisition_fallback || $item->liquidation_fallback) {
                $fallbackResources[] = $resource;
            }
        }

        return new MarketPriceSet(
            acquisitionPrices: $acquisition,
            liquidationPrices: $liquidation,
            snapshotId: (int) $snapshot->id,
            calculatedAt: CarbonImmutable::instance($snapshot->calculated_at),
            stale: $stale,
            fallbackResources: array_values(array_unique($fallbackResources)),
            basis: (string) $snapshot->basis,
        );
    }

    /**
     * @throws ProfitabilityPricingUnavailable
     */
    private function aggregateFallback(): MarketPriceSet
    {
        $average = $this->tradePriceService->get24hAverage();
        $prices = [];

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $price = (float) ($average->{$resource} ?? 0.0);

            if ($price < 1 || $price > 1_000_000) {
                throw new ProfitabilityPricingUnavailable("No usable price is available for {$resource}.");
            }

            $prices[$resource] = $price;
        }

        return new MarketPriceSet(
            acquisitionPrices: $prices,
            liquidationPrices: $prices,
            stale: true,
            fallbackResources: EconomyRules::TRADE_RESOURCES,
            basis: '24-hour aggregate fallback prices',
        );
    }
}
