<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;

class AccountStatementRow
{
    /**
     * @param  array<string, float>  $resources
     */
    public function __construct(
        public readonly CarbonImmutable $occurredAt,
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly string $direction,
        public readonly string $referenceId,
        public readonly string $sourceRecordId,
        public readonly ?string $description,
        public readonly array $resources,
    ) {}
}
