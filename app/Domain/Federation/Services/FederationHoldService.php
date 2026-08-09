<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\ImportState;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomOperation;
use App\Services\AuditLogger;
use App\Services\Milcom\OperationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FederationHoldService
{
    public function __construct(
        private readonly FederationOperationGuard $guard,
        private readonly OperationService $operations,
        private readonly AuditLogger $audit,
    ) {}

    public function placeForResource(FederationReceivedResource $resource, string $reasonCode): int
    {
        $operationIds = $resource->versions()
            ->whereNotNull('imported_operation_id')
            ->pluck('imported_operation_id')
            ->unique()
            ->values();
        $held = 0;

        foreach ($operationIds as $operationId) {
            $operation = MilcomOperation::query()->find($operationId);

            if (! $operation instanceof MilcomOperation || $operation->federation_detached_at !== null) {
                continue;
            }

            DB::transaction(function () use ($operation, $reasonCode, &$held): void {
                $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

                if ($locked->federation_detached_at !== null) {
                    return;
                }

                $locked->forceFill([
                    'federation_action_required' => true,
                    'federation_hold_reason' => Str::limit(Str::snake($reasonCode), 64, ''),
                    'federation_held_at' => $locked->federation_held_at ?? now(),
                ])->save();
                MilcomAssignmentDelivery::query()
                    ->where('operation_id', $locked->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'failed',
                        'last_error' => FederationOperationGuard::HELD_ERROR_CODE,
                        'failed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->suppressPendingDiscordCommands($locked);
                $this->recordUnrecalledInGameDeliveries($locked);
                $held++;
            }, attempts: 5);
        }

        return $held;
    }

    public function continueIndependently(
        MilcomOperation $operation,
        string $reason,
        int $actorUserId,
    ): MilcomOperation {
        $this->assertReason($reason);

        $detached = DB::transaction(function () use ($operation, $reason): MilcomOperation {
            $locked = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);

            if (! $locked->federation_action_required) {
                throw ValidationException::withMessages(['operation' => 'This operation is not under a federation hold.']);
            }

            $locked->forceFill([
                'federation_action_required' => false,
                'federation_hold_reason' => null,
                'federation_detached_at' => now(),
                'federation_resolution_reason' => $reason,
            ])->save();
            FederationReceivedVersion::query()
                ->where('imported_operation_id', $locked->id)
                ->whereNotIn('import_state', [ImportState::SourceStale->value])
                ->update([
                    'import_state' => ImportState::SourceStale->value,
                    'updated_at' => now(),
                ]);

            return $locked;
        }, attempts: 5);

        $this->audit->success('federation', 'import.detached', $detached, [
            'operation_id' => $detached->id,
            'actor_id' => $actorUserId,
            'reason_code' => 'continue_independently',
        ]);

        return $detached;
    }

    public function retire(MilcomOperation $operation, string $reason, int $actorUserId): MilcomOperation
    {
        $this->assertReason($reason);
        $locked = MilcomOperation::query()->findOrFail($operation->id);

        if (! $locked->federation_action_required) {
            throw ValidationException::withMessages(['operation' => 'This operation is not under a federation hold.']);
        }

        $retired = $this->guard->forRetirement($locked, function () use ($locked, $actorUserId): MilcomOperation {
            $completed = $this->operations->complete($locked, $actorUserId);

            return $this->operations->archive($completed, $actorUserId);
        });
        $retired->forceFill([
            'federation_action_required' => false,
            'federation_hold_reason' => null,
            'federation_resolution_reason' => $reason,
        ])->save();

        $this->audit->success('federation', 'import.retired', $retired, [
            'operation_id' => $retired->id,
            'actor_id' => $actorUserId,
            'reason_code' => 'retire',
        ]);

        return $retired;
    }

    private function suppressPendingDiscordCommands(MilcomOperation $operation): void
    {
        $queueIds = $operation->dispatches()->whereNotNull('queue_id')->pluck('queue_id');

        foreach (DiscordQueue::query()
            ->whereIn('id', $queueIds)
            ->where('status', DiscordQueueStatus::Pending->value)
            ->lockForUpdate()
            ->get() as $queueItem) {
            $queueItem->forceFill([
                'status' => DiscordQueueStatus::Failed,
                'last_error' => [
                    'code' => 'federation_hold',
                    'message' => 'Suppressed before lease because the imported operation requires federation action.',
                ],
                'completed_at' => now(),
            ])->save();
        }

        $leasedCount = DiscordQueue::query()
            ->whereIn('id', $queueIds)
            ->where('status', DiscordQueueStatus::Processing->value)
            ->count();

        if ($leasedCount > 0) {
            $this->audit->failure('federation', 'hold.external_action_not_recalled', $operation, [
                'operation_id' => $operation->id,
                'leased_action_count' => $leasedCount,
                'reason_code' => 'already_leased',
            ]);
        }
    }

    private function recordUnrecalledInGameDeliveries(MilcomOperation $operation): void
    {
        $sendingCount = MilcomAssignmentDelivery::query()
            ->where('operation_id', $operation->id)
            ->where('status', 'sending')
            ->count();

        if ($sendingCount > 0) {
            $this->audit->failure('federation', 'hold.external_action_not_recalled', $operation, [
                'operation_id' => $operation->id,
                'leased_action_count' => $sendingCount,
                'reason_code' => 'in_game_delivery_already_leased',
            ]);
        }
    }

    private function assertReason(string $reason): void
    {
        $length = Str::length(Str::squish($reason));

        if ($length < 10 || $length > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Provide a reason between 10 and 1,000 characters.',
            ]);
        }
    }
}
