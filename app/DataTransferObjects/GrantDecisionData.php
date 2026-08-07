<?php

namespace App\DataTransferObjects;

use App\Enums\GrantDecisionReason;

final readonly class GrantDecisionData
{
    public function __construct(
        public GrantDecisionReason $reason,
        public ?string $memberExplanation = null,
        public ?string $internalNote = null,
    ) {}
}
