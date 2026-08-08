<?php

namespace App\Services\Alerts;

use App\Models\AlertDailyMetric;
use App\Models\AlertDeliveryBatch;
use App\Models\AlertOccurrence;

class AlertRetentionService
{
    public function prune(): int
    {
        $cutoff = now()->subDays(30);
        $deleted = 0;

        AlertOccurrence::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($occurrences) use (&$deleted): void {
                $ids = $occurrences->modelKeys();
                $deleted += AlertOccurrence::query()->whereKey($ids)->delete();
            });

        AlertDeliveryBatch::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('deliveries')
            ->chunkById(500, function ($batches) use (&$deleted): void {
                $deleted += AlertDeliveryBatch::query()->whereKey($batches->modelKeys())->delete();
            });

        AlertDailyMetric::query()
            ->where('metric_date', '<', now()->subMonths(13)->toDateString())
            ->chunkById(500, function ($metrics) use (&$deleted): void {
                $deleted += AlertDailyMetric::query()->whereKey($metrics->modelKeys())->delete();
            });

        return $deleted;
    }
}
