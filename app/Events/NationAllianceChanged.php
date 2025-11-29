<?php

namespace App\Events;

use App\Models\Nation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NationAllianceChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Nation $nation,
        public readonly ?int $oldAllianceId,
        public readonly ?string $oldAlliancePosition,
        public readonly ?int $newAllianceId,
        public readonly ?string $newAlliancePosition
    ) {}

    /**
     * Determine if the alliance ID actually changed.
     */
    public function changedAlliance(): bool
    {
        return $this->oldAllianceId !== $this->newAllianceId;
    }
}
