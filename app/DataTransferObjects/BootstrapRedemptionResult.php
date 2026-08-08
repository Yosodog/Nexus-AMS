<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\BootstrapRedemptionMode;

final readonly class BootstrapRedemptionResult
{
    public function __construct(
        public int $redemptionId,
        public int $localUserId,
        public BootstrapRedemptionMode $mode,
    ) {}
}
