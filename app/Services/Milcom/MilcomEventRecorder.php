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
        ?string $deduplicationKey = null,
    ): MilcomEvent {
        $attributes = [
            'operation_id' => $operationId,
            'objective_id' => $objectiveId,
            'incident_id' => $incidentId,
            'assignment_id' => $assignmentId,
            'actor_user_id' => $actorUserId,
            'source' => $source,
            'event_type' => $eventType,
            'payload' => $payload !== [] ? $payload : null,
            'occurred_at' => now(),
        ];

        if ($deduplicationKey === null) {
            return MilcomEvent::query()->create($attributes);
        }

        return MilcomEvent::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            $attributes,
        );
    }
}
