<?php

namespace App\DataTransferObjects;

use App\Exceptions\ProfitabilityPricingUnavailable;
use Carbon\CarbonImmutable;

final readonly class MarketPriceSet
{
    public const BASIS = '7-day completed market prices (side-specific)';

    /**
     * @param  array<string, float>  $acquisitionPrices
     * @param  array<string, float>  $liquidationPrices
     * @param  list<string>  $fallbackResources
     */
    public function __construct(
        public array $acquisitionPrices,
        public array $liquidationPrices,
        public ?int $snapshotId = null,
        public ?CarbonImmutable $calculatedAt = null,
        public bool $stale = false,
        public array $fallbackResources = [],
        public string $basis = self::BASIS,
    ) {}

    /**
     * @param  array<string, float|int>  $prices
     */
    public static function symmetric(array $prices, string $basis = 'symmetric resource prices'): self
    {
        $normalized = collect($prices)
            ->mapWithKeys(fn (float|int $price, string $resource): array => [$resource => (float) $price])
            ->all();
        $normalized['money'] = 1.0;

        return new self($normalized, $normalized, basis: $basis);
    }

    public function priceFor(string $resource, float $amount): float
    {
        if ($resource === 'money') {
            return 1.0;
        }

        $prices = $amount >= 0 ? $this->liquidationPrices : $this->acquisitionPrices;
        $price = (float) ($prices[$resource] ?? 0.0);

        if ($price <= 0) {
            throw new ProfitabilityPricingUnavailable("No usable price is available for {$resource}.");
        }

        return $price;
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    public function convert(array $resources): float
    {
        $converted = 0.0;

        foreach ($resources as $resource => $amount) {
            if ((float) $amount === 0.0) {
                continue;
            }

            $converted += (float) $amount * $this->priceFor($resource, (float) $amount);
        }

        return $converted;
    }

    /**
     * @return array<string, float>
     */
    public function liquidationPricesWithMoney(): array
    {
        return ['money' => 1.0] + $this->liquidationPrices;
    }
}
