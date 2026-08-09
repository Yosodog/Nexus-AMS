<?php

namespace App\Jobs;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomOperation;
use App\Services\Milcom\AssignmentDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendMilcomAssignmentMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function uniqueId(): string
    {
        return "milcom-assignment-delivery:{$this->deliveryId}";
    }

    public function handle(
        AssignmentDeliveryService $deliveries,
        FederationOperationGuard $federationGuard,
    ): void {
        $shouldDeliver = DB::transaction(function () use ($federationGuard): bool {
            $deliveryReference = MilcomAssignmentDelivery::query()->findOrFail($this->deliveryId);
            $operation = MilcomOperation::query()
                ->lockForUpdate()
                ->findOrFail($deliveryReference->operation_id);
            $delivery = MilcomAssignmentDelivery::query()
                ->lockForUpdate()
                ->findOrFail($this->deliveryId);

            if ($delivery->status === 'sent') {
                return false;
            }

            if ($delivery->status === 'sending') {
                $delivery->forceFill([
                    'status' => 'failed',
                    'last_error' => 'Politics & War delivery outcome is uncertain; manual retry is required.',
                    'failed_at' => now(),
                    'updated_at' => now(),
                ])->save();

                return false;
            }

            if ($federationGuard->isHeld($operation)) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'last_error' => FederationOperationGuard::HELD_ERROR_CODE,
                    'failed_at' => now(),
                    'updated_at' => now(),
                ])->save();

                return false;
            }

            $federationGuard->assertMutable($operation, 'in_game_delivery_send');

            return $delivery->status === 'pending';
        }, attempts: 3);

        if ($shouldDeliver) {
            $deliveries->deliver($this->deliveryId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MilcomAssignmentDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', '!=', 'sent')
            ->update([
                'status' => 'failed',
                'last_error' => $exception?->getMessage() ?? 'Assignment delivery failed.',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
