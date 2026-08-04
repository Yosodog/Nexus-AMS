<?php

namespace App\Jobs;

use App\Models\MilcomAssignmentDelivery;
use App\Services\Milcom\AssignmentDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(AssignmentDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
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
