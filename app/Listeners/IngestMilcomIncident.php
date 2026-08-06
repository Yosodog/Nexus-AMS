<?php

namespace App\Listeners;

use App\Events\WarDeclared;
use App\Services\Milcom\IncidentService;

class IngestMilcomIncident
{
    public function __construct(
        private readonly IncidentService $incidents,
    ) {}

    public function handle(WarDeclared $event): void
    {
        if (! (bool) config('milcom.v2_enabled', false)) {
            return;
        }

        $this->incidents->ingest(
            warId: $event->warId,
            aggressorNationId: $event->attackerNationId,
            aggressorAllianceId: $event->attackerAllianceId,
            attackedNationId: $event->defenderNationId,
            attackedAllianceId: $event->defenderAllianceId,
        );
    }
}
