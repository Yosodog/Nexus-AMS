<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

final readonly class ScheduledTaskFreshness
{
    public function __construct(
        public string $taskIdentifier,
        public string $label,
        public int $maximumAgeMinutes,
        public ?CarbonImmutable $lastSucceededAt,
        public ?CarbonImmutable $expectedBy,
        public bool $isOverdue,
    ) {}
}
