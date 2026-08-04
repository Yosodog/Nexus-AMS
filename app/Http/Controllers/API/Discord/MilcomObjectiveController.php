<?php

namespace App\Http\Controllers\API\Discord;

use App\Domain\Milcom\Enums\DispatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\AttachMilcomObjectiveRoomRequest;
use App\Models\MilcomDispatch;
use App\Models\MilcomObjective;
use App\Services\Milcom\MilcomEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MilcomObjectiveController extends Controller
{
    public function __construct(private readonly MilcomEventRecorder $events) {}

    public function show(MilcomObjective $objective): JsonResponse
    {
        return response()->json([
            'data' => [
                'objective' => [
                    'id' => $objective->id,
                    'operation_id' => $objective->operation_id,
                    'status' => $objective->status->value,
                    'discord_channel_id' => $objective->discord_channel_id,
                    'dispatch_version' => $objective->dispatch_version,
                ],
            ],
            'meta' => ['contract_version' => 2],
            'links' => [],
        ]);
    }

    public function attachRoom(AttachMilcomObjectiveRoomRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated): array {
            $objective = MilcomObjective::query()
                ->lockForUpdate()
                ->findOrFail((int) $validated['objective_id']);
            $dispatch = MilcomDispatch::query()
                ->lockForUpdate()
                ->findOrFail((int) $validated['dispatch_id']);

            if ((int) $dispatch->objective_id !== (int) $objective->id) {
                abort(422, 'The dispatch does not belong to this Milcom objective.');
            }

            if ((int) $dispatch->dispatch_version !== (int) $objective->dispatch_version) {
                abort(409, 'This Discord room callback belongs to a superseded dispatch version.');
            }

            if (! in_array($dispatch->status, [
                DispatchStatus::Pending,
                DispatchStatus::Queued,
                DispatchStatus::Sent,
            ], true)) {
                abort(409, 'This Discord dispatch can no longer attach a room.');
            }

            $channelId = (string) $validated['discord_channel_id'];
            $existingChannel = trim((string) $objective->discord_channel_id);
            $existingDispatchChannel = trim((string) $dispatch->external_channel_id);

            if (($existingChannel !== '' && $existingChannel !== $channelId)
                || ($existingDispatchChannel !== '' && $existingDispatchChannel !== $channelId)) {
                abort(409, 'A different Discord room is already attached.');
            }

            $changed = $existingChannel === '' || $existingDispatchChannel === '';
            $approvalToRoomMs = $dispatch->queued_at?->diffInMilliseconds(now())
                ?? $dispatch->created_at?->diffInMilliseconds(now())
                ?? 0;

            $objective->forceFill(['discord_channel_id' => $channelId])->save();
            $dispatch->forceFill([
                'status' => DispatchStatus::Sent,
                'external_channel_id' => $channelId,
                'sent_at' => $dispatch->sent_at ?? now(),
                'failed_at' => null,
                'errors' => null,
            ])->save();

            if ($changed) {
                $this->events->record(
                    eventType: 'objective.discord_room_attached',
                    source: 'discord',
                    operationId: $objective->operation_id,
                    objectiveId: $objective->id,
                    payload: [
                        'dispatch_id' => $dispatch->id,
                        'discord_channel_id' => $channelId,
                        'approval_to_room_ms' => $approvalToRoomMs,
                    ],
                );
            }

            return [
                'operation_id' => $objective->operation_id,
                'objective_id' => $objective->id,
                'dispatch_id' => $dispatch->id,
                'discord_channel_id' => $channelId,
                'attached' => true,
                'idempotent_replay' => ! $changed,
                'approval_to_room_ms' => $approvalToRoomMs,
            ];
        }, attempts: 5);

        $context = [
            'operation_id' => $result['operation_id'],
            'objective_id' => $result['objective_id'],
            'dispatch_id' => $result['dispatch_id'],
            'discord_channel_id' => $result['discord_channel_id'],
            'approval_to_room_ms' => $result['approval_to_room_ms'],
            'budget_ms' => 15_000,
            'idempotent_replay' => $result['idempotent_replay'],
        ];
        Log::info('Milcom Discord room attached.', $context);

        if (! $result['idempotent_replay'] && $result['approval_to_room_ms'] > 15_000) {
            Log::warning('Milcom Discord room attachment exceeded latency budget.', $context);
        }

        return response()->json([
            'data' => $result,
            'meta' => ['contract_version' => 2],
            'links' => [],
        ]);
    }
}
