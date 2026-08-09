<?php

namespace App\Services\StaffWorkQueue;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class StaffWorkQueueSourceResult
{
    public CarbonImmutable $observedAt;

    public ?CarbonImmutable $upstreamObservedAt;

    /**
     * @param  list<StaffWorkItem>  $items
     * @param  array<string, int|float|string|bool|null>  $summary
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $items,
        DateTimeInterface $observedAt,
        ?DateTimeInterface $upstreamObservedAt = null,
        public array $summary = [],
        public bool $complete = true,
        public bool $truncated = false,
        public array $warnings = [],
    ) {
        $this->observedAt = CarbonImmutable::instance($observedAt);
        $this->upstreamObservedAt = $upstreamObservedAt
            ? CarbonImmutable::instance($upstreamObservedAt)
            : null;
    }

    /** @param  list<StaffWorkItem>  $items */
    public static function complete(array $items): self
    {
        return new self($items, now());
    }
}
