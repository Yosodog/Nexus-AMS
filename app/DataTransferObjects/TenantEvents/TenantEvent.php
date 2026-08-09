<?php

declare(strict_types=1);

namespace App\DataTransferObjects\TenantEvents;

use App\Enums\TenantEventType;
use Carbon\CarbonImmutable;

final readonly class TenantEvent
{
    /** @param list<int> $matchedAllianceIds */
    public function __construct(
        public string $deliveryId,
        public string $eventId,
        public int $contractVersion,
        public string $tenantId,
        public TenantEventType $type,
        public int $subjectId,
        public array $matchedAllianceIds,
        public CarbonImmutable $occurredAt,
        public string $traceId,
        public string $bodyDigest,
        public string $transportNonce,
        public CarbonImmutable $publishedAt,
    ) {}

    public function subjectKey(): string
    {
        return match ($this->type) {
            TenantEventType::WarDeclared => 'war:'.$this->subjectId,
        };
    }
}
