<?php

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberTimelineCategory;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class MemberTimelineItem
{
    /**
     * @param  'system'|'staff'|'member'  $actorKind
     * @param  'neutral'|'pending'|'active'|'success'|'warning'|'failure'  $statusIntent
     */
    public function __construct(
        public string $sourceKey,
        public string $deduplicationKey,
        public MemberTimelineCategory $category,
        public CarbonImmutable $occurredAt,
        public string $actorKind,
        public string $actorLabel,
        public string $summary,
        public string $statusLabel,
        public string $statusIntent,
        public string $statusIcon,
        public ?string $sourceUrl = null,
        public ?string $sourceLabel = null,
        public int $sourcePriority = 50,
    ) {
        if (! in_array($this->actorKind, ['system', 'staff', 'member'], true)) {
            throw new InvalidArgumentException("Unsupported timeline actor kind [{$this->actorKind}].");
        }

        if (! in_array($this->statusIntent, ['neutral', 'pending', 'active', 'success', 'warning', 'failure'], true)) {
            throw new InvalidArgumentException("Unsupported timeline status intent [{$this->statusIntent}].");
        }
    }

    public function actorKindLabel(): string
    {
        return match ($this->actorKind) {
            'staff' => 'Staff action',
            'member' => 'Member action',
            default => 'Automated update',
        };
    }
}
