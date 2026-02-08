<?php

namespace App\Services\Inactivity;

use Carbon\CarbonInterface;

class InactivityActionContext
{
    public function __construct(
        public CarbonInterface $now,
        public CarbonInterface $lastActiveAt,
        public int $thresholdHours,
        public ?string $accountsUrl,
        public bool $directDepositEnabled,
        public bool $wasDirectDepositEnrolled,
        public bool $autoEnrolledDirectDeposit = false,
    ) {}
}
