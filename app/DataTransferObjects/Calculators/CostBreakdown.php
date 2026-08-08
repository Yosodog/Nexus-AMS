<?php

namespace App\DataTransferObjects\Calculators;

use App\DataTransferObjects\MarketPriceSet;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class CostBreakdown implements Arrayable
{
    public const ROUNDING = 'Money and resources are rounded to 0.01 with half-up rounding; market totals are rounded after conversion.';

    /**
     * @param  array<string, float>  $resources
     */
    private function __construct(
        public float $money,
        public array $resources,
        public ?float $marketValue,
        public string $valuationBasis,
    ) {}

    /**
     * @param  array<string, float|int>  $resources
     */
    public static function acquisition(float|int $money, array $resources, ?MarketPriceSet $prices): self
    {
        $normalized = self::normalizeResources($resources);

        return new self(
            (float) $money,
            $normalized,
            self::valueAcquisition((float) $money, $normalized, $prices),
            'acquisition',
        );
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    public static function liquidation(float|int $money, array $resources, ?MarketPriceSet $prices): self
    {
        $normalized = self::normalizeResources($resources);

        return new self(
            (float) $money,
            $normalized,
            self::valueLiquidation((float) $money, $normalized, $prices),
            'liquidation',
        );
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    public static function net(float|int $money, array $resources, ?MarketPriceSet $prices): self
    {
        $normalized = self::normalizeResources($resources);
        $marketValue = $prices === null
            ? null
            : $prices->convert(['money' => (float) $money] + $normalized);

        return new self((float) $money, $normalized, $marketValue, 'side-specific net');
    }

    /**
     * @return array{money: float, resources: array<string, float>, market_value: float|null, valuation_basis: string}
     */
    public function toArray(): array
    {
        return [
            'money' => self::round($this->money),
            'resources' => collect($this->resources)
                ->mapWithKeys(fn (float $amount, string $resource): array => [$resource => self::round($amount)])
                ->all(),
            'market_value' => $this->marketValue === null ? null : self::round($this->marketValue),
            'valuation_basis' => $this->valuationBasis,
        ];
    }

    public static function round(float|int $value): float
    {
        return round((float) $value, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  array<string, float|int>  $resources
     * @return array<string, float>
     */
    private static function normalizeResources(array $resources): array
    {
        return collect($resources)
            ->mapWithKeys(fn (float|int $amount, string $resource): array => [$resource => (float) $amount])
            ->reject(fn (float $amount): bool => abs($amount) < 0.000000001)
            ->all();
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private static function valueAcquisition(float $money, array $resources, ?MarketPriceSet $prices): ?float
    {
        if ($prices === null) {
            return null;
        }

        return collect($resources)->reduce(
            fn (float $total, float|int $amount, string $resource): float => $total
                + ((float) $amount * $prices->priceFor($resource, -(float) $amount)),
            $money,
        );
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private static function valueLiquidation(float $money, array $resources, ?MarketPriceSet $prices): ?float
    {
        if ($prices === null) {
            return null;
        }

        return collect($resources)->reduce(
            fn (float $total, float|int $amount, string $resource): float => $total
                + ((float) $amount * $prices->priceFor($resource, (float) $amount)),
            $money,
        );
    }
}
