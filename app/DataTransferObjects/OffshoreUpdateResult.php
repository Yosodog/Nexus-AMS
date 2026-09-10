<?php

namespace App\DataTransferObjects;

use App\Models\Offshore;

final readonly class OffshoreUpdateResult
{
    public function __construct(
        public Offshore $offshore,
        public bool $allianceIdChanged,
        public bool $directDepositDisabled,
        public int $queuedDisenrollments,
    ) {}
}
