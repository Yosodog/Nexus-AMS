<?php

namespace App\Jobs;

use App\Services\Alerts\AlertMetricsRollupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RollupAlertMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3_600;

    public function __construct(public readonly ?string $metricDate = null) {}

    public function handle(AlertMetricsRollupService $metrics): void
    {
        $metrics->rollup($this->metricDate);
    }

    public function uniqueId(): string
    {
        return $this->metricDate ?? now('UTC')->toDateString();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Alert metric rollup failed.', [
            'metric_date' => $this->metricDate,
            'error' => $exception?->getMessage(),
        ]);
    }
}
