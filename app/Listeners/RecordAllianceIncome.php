<?php

namespace App\Listeners;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceIncomeOccurred;
use App\Services\Finance\AllianceFinanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAllianceIncome implements ShouldQueue
{
    /**
     * Create a new listener instance.
     */
    public function __construct(
        private readonly AllianceFinanceService $finance,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(AllianceIncomeOccurred $event): void
    {
        try {
            $data = AllianceFinanceData::fromArray($event->payload);
            $this->finance->recordIncome($data);
        } catch (Throwable $throwable) {
            Log::error('Failed to record alliance income entry', [
                'error' => $throwable->getMessage(),
                'payload' => $event->payload,
            ]);
        }
    }
}
