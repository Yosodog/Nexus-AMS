<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WarStateChanged;
use App\Jobs\ReconcileRaidPredictionJob;
use App\Services\RuntimeCapabilities;

final class ReconcileRaidPredictionOnWarStateChanged
{
    public function __construct(private readonly RuntimeCapabilities $runtimeCapabilities) {}

    public function handle(WarStateChanged $event): void
    {
        if (! $this->runtimeCapabilities->writesTenantPrivate()) {
            return;
        }

        ReconcileRaidPredictionJob::dispatch($event->warId)->afterCommit();
    }
}
