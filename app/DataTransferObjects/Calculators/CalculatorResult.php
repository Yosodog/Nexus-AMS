<?php

namespace App\DataTransferObjects\Calculators;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class CalculatorResult implements Arrayable
{
    /**
     * @param  array<string, CostBreakdown>  $breakdowns
     * @param  list<array{key: string, label: string, rate: float|null, applied: bool, note?: string}>  $modifiers
     * @param  list<string>  $assumptions
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public string $calculator,
        public array $breakdowns,
        public array $modifiers,
        public array $assumptions,
        public array $metrics = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calculator' => $this->calculator,
            'breakdowns' => collect($this->breakdowns)
                ->mapWithKeys(fn (CostBreakdown $breakdown, string $key): array => [$key => $breakdown->toArray()])
                ->all(),
            'modifiers' => $this->modifiers,
            'assumptions' => $this->assumptions,
            'metrics' => $this->metrics,
            'rounding' => CostBreakdown::ROUNDING,
        ];
    }
}
