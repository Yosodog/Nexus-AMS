<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WarAttackRecorded;
use App\Jobs\RecordRaidOutcomeAttackJob;
use App\Services\RuntimeCapabilities;

final class CaptureRaidOutcomeOnAttackRecorded
{
    public function __construct(private readonly RuntimeCapabilities $runtimeCapabilities) {}

    public function handle(WarAttackRecorded $event): void
    {
        if (! $this->runtimeCapabilities->writesTenantPrivate()) {
            return;
        }

        RecordRaidOutcomeAttackJob::dispatch(
            $event->attackId,
            $event->warId,
        )->afterCommit();
    }
}
