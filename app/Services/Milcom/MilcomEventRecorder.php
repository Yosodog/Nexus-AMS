<?php

namespace App\Services\Milcom;

use App\Models\MilcomEvent;

class MilcomEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        string $source = 'system',
        ?int $operationId = null,
        ?int $objectiveId = null,
        ?int $incidentId = null,
        ?int $assignmentId = null,
        ?int $actorUserId = null,
        array $payload = [],
    ): MilcomEvent {
        return MilcomEvent::query()->create([
            'operation_id' => $operationId,
            'objective_id' => $objectiveId,
            'incident_id' => $incidentId,
            'assignment_id' => $assignmentId,
            'actor_user_id' => $actorUserId,
            'source' => $source,
            'event_type' => $eventType,
            'payload' => $payload !== [] ? $payload : null,
            'occurred_at' => now(),
        ]);
    }
}
