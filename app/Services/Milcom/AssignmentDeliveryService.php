<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Exceptions\StaleGenerationException;
use App\Jobs\SendMilcomAssignmentMessageJob;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomOperation;
use App\Services\PWMessageService;
use App\Support\PWBBCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AssignmentDeliveryService
{
    public function __construct(
        private readonly PWMessageService $messages,
        private readonly MilcomEventRecorder $events,
    ) {}

    /** @return array{queued:int, already_queued:int, already_sent:int} */
    public function queueInGame(
        MilcomOperation $operation,
        int $generationVersion,
        int $actorUserId,
    ): array {
        $result = DB::transaction(function () use ($operation, $generationVersion, $actorUserId): array {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if ((int) $locked->generation_version !== $generationVersion) {
                throw new StaleGenerationException($generationVersion, (int) $locked->generation_version);
            }

            if ($locked->type !== OperationType::Plan || $locked->status !== OperationStatus::Active) {
                throw ValidationException::withMessages([
                    'operation' => 'Finalize the wave before sending assignments in-game.',
                ]);
            }

            $assignments = $locked->assignmentsThroughObjectives()
                ->whereIn('milcom_assignments.status', [
                    AssignmentStatus::Approved->value,
                    AssignmentStatus::Dispatched->value,
                    AssignmentStatus::Engaged->value,
                ])
                ->with([
                    'friendlyNation:id,nation_name,leader_name',
                    'objective.target:id,nation_name,leader_name',
                    'objective.assignments' => fn ($query) => $query
                        ->whereNotIn('status', [AssignmentStatus::Released->value, AssignmentStatus::Failed->value])
                        ->orderBy('rank')
                        ->orderBy('id'),
                    'objective.assignments.friendlyNation:id,nation_name,leader_name',
                ])
                ->orderBy('milcom_assignments.id')
                ->get();

            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages([
                    'operation' => 'There are no approved assignments to send.',
                ]);
            }

            $deliveryIds = [];
            $alreadyQueued = 0;
            $alreadySent = 0;

            foreach ($assignments as $assignment) {
                $payload = $this->messagePayload($locked, $assignment);
                $delivery = MilcomAssignmentDelivery::query()->firstOrCreate(
                    [
                        'assignment_id' => $assignment->id,
                        'channel' => 'in_game',
                    ],
                    [
                        'operation_id' => $locked->id,
                        'status' => 'pending',
                        'dedupe_key' => "milcom-assignment:{$assignment->id}:in-game",
                        'subject' => $payload['subject'],
                        'payload_snapshot' => $payload,
                        'queued_at' => now(),
                    ],
                );

                if ($delivery->status === 'sent') {
                    $alreadySent++;

                    continue;
                }

                if (! $delivery->wasRecentlyCreated && $delivery->status === 'pending') {
                    $alreadyQueued++;

                    continue;
                }

                $delivery->forceFill([
                    'status' => 'pending',
                    'subject' => $payload['subject'],
                    'payload_snapshot' => $payload,
                    'last_error' => null,
                    'failed_at' => null,
                    'queued_at' => now(),
                ])->save();
                $deliveryIds[] = (int) $delivery->id;
            }

            $this->events->record(
                eventType: 'operation.in_game_assignments_queued',
                source: 'officer',
                operationId: $locked->id,
                actorUserId: $actorUserId,
                payload: [
                    'queued' => count($deliveryIds),
                    'already_queued' => $alreadyQueued,
                    'already_sent' => $alreadySent,
                ],
            );

            return [
                'delivery_ids' => $deliveryIds,
                'already_queued' => $alreadyQueued,
                'already_sent' => $alreadySent,
            ];
        }, attempts: 5);

        foreach ($result['delivery_ids'] as $deliveryId) {
            SendMilcomAssignmentMessageJob::dispatch($deliveryId);
        }

        return [
            'queued' => count($result['delivery_ids']),
            'already_queued' => $result['already_queued'],
            'already_sent' => $result['already_sent'],
        ];
    }

    public function deliver(int $deliveryId): void
    {
        $delivery = MilcomAssignmentDelivery::query()->findOrFail($deliveryId);

        if ($delivery->status === 'sent') {
            return;
        }

        $payload = $delivery->payload_snapshot;
        $delivery->forceFill([
            'status' => 'pending',
            'attempts' => (int) $delivery->attempts + 1,
            'last_error' => null,
        ])->save();

        if (! $this->messages->sendMessage(
            (int) $payload['nation_id'],
            (string) $delivery->subject,
            (string) $payload['message'],
        )) {
            $delivery->forceFill([
                'status' => 'failed',
                'last_error' => 'Politics & War rejected the message or could not be reached.',
                'failed_at' => now(),
            ])->save();

            throw new RuntimeException('Politics & War assignment delivery failed.');
        }

        $delivery->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'failed_at' => null,
            'last_error' => null,
        ])->save();

        $this->events->record(
            eventType: 'assignment.in_game_sent',
            source: 'system',
            operationId: $delivery->operation_id,
            objectiveId: $delivery->assignment?->objective_id,
            assignmentId: $delivery->assignment_id,
            payload: ['delivery_id' => $delivery->id],
        );
    }

    /** @return array{nation_id:int, subject:string, message:string} */
    private function messagePayload(MilcomOperation $operation, MilcomAssignment $assignment): array
    {
        $target = $assignment->objective->target;
        $member = $assignment->friendlyNation;
        $wave = (int) data_get($operation->metadata, 'wave', 1);
        $team = $assignment->objective->assignments
            ->map(fn (MilcomAssignment $teamAssignment): string => '- '.$this->nationLink(
                (int) $teamAssignment->friendly_nation_id,
                $teamAssignment->friendlyNation?->nation_name ?? 'Unknown nation',
            ))
            ->implode("\n");
        $deadline = $assignment->objective->deadline_at?->toDayDateTimeString() ?? 'No deadline set';
        $reason = PWBBCode::escapeText((string) ($assignment->objective->war_reason ?: $operation->default_war_reason ?: 'Alliance operation'));
        $message = implode("\n", [
            'You have a new war target for '.PWBBCode::escapeText($operation->name)." (Wave {$wave}).",
            '',
            'Target: '.$this->nationLink((int) $target->id, $target->nation_name ?? 'Unknown target'),
            'War type: '.PWBBCode::escapeText((string) $assignment->objective->war_type),
            'Reason: '.$reason,
            'Declare by: '.PWBBCode::escapeText($deadline),
            '',
            'Your team:',
            $team,
            '',
            '[link='.route('admin.milcom.plans.show', ['operation' => $operation->id, 'objective' => $assignment->objective_id]).']Open this wave in Nexus[/link]',
        ]);

        return [
            'nation_id' => (int) $member->id,
            'subject' => str(PWBBCode::escapeText('War target: '.($target->nation_name ?? 'Alliance operation')))->limit(80)->toString(),
            'message' => $message,
        ];
    }

    private function nationLink(int $nationId, string $label): string
    {
        return '[link=https://politicsandwar.com/nation/id='.$nationId.']'.PWBBCode::escapeText($label).'[/link]';
    }
}
