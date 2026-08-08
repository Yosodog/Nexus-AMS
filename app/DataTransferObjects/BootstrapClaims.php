<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\TenantBootstrapAction;
use Carbon\CarbonImmutable;

final readonly class BootstrapClaims
{
    public function __construct(
        public string $tenantId,
        public string $cloudUserId,
        public TenantBootstrapAction $action,
        public string $releaseId,
        public int $allianceId,
        public int $nationId,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public string $claimsDigest,
    ) {}
}
