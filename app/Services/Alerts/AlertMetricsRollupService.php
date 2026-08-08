<?php

namespace App\Services\Alerts;

use App\Models\AlertDailyMetric;
use App\Models\AlertDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AlertMetricsRollupService
{
    public function rollup(string|CarbonInterface|null $metricDate = null): int
    {
        $day = $metricDate instanceof CarbonInterface
            ? CarbonImmutable::instance($metricDate)->utc()->startOfDay()
            : CarbonImmutable::parse($metricDate ?? 'today', 'UTC')->startOfDay();
        $groups = [];

        AlertDelivery::query()
            ->whereBetween('created_at', [$day, $day->endOfDay()])
            ->with('occurrence:id,alliance_id,event_key,received_at')
            ->oldest('id')
            ->chunkById(500, function ($deliveries) use (&$groups): void {
                foreach ($deliveries as $delivery) {
                    $occurrence = $delivery->occurrence;
                    $scopeKey = $occurrence->alliance_id === null
                        ? 'global'
                        : 'alliance:'.$occurrence->alliance_id;
                    $key = implode('|', [
                        $scopeKey,
                        $occurrence->event_key,
                        $delivery->destination_kind->value,
                        $delivery->status->value,
                    ]);
                    $groups[$key] ??= [
                        'scope_key' => $scopeKey,
                        'alliance_id' => $occurrence->alliance_id,
                        'event_key' => $occurrence->event_key,
                        'destination_kind' => $delivery->destination_kind->value,
                        'outcome' => $delivery->status->value,
                        'total' => 0,
                        'latencies' => [],
                    ];
                    $groups[$key]['total']++;

                    $finishedAt = $delivery->delivered_at ?? $delivery->failed_at ?? $delivery->queued_at;
                    if ($finishedAt !== null && $occurrence->received_at !== null) {
                        $groups[$key]['latencies'][] = max(
                            0,
                            $occurrence->received_at->diffInMilliseconds($finishedAt, false),
                        );
                    }
                }
            });

        DB::transaction(function () use ($day, $groups): void {
            AlertDailyMetric::query()->whereDate('metric_date', $day->toDateString())->delete();

            foreach ($groups as $group) {
                $latencies = $group['latencies'];
                sort($latencies);

                AlertDailyMetric::query()->create([
                    'metric_date' => $day->toDateString(),
                    'scope_key' => $group['scope_key'],
                    'alliance_id' => $group['alliance_id'],
                    'event_key' => $group['event_key'],
                    'destination_kind' => $group['destination_kind'],
                    'outcome' => $group['outcome'],
                    'total' => $group['total'],
                    'latency_p50_ms' => $this->percentile($latencies, 0.50),
                    'latency_p95_ms' => $this->percentile($latencies, 0.95),
                    'latency_p99_ms' => $this->percentile($latencies, 0.99),
                ]);
            }
        }, attempts: 3);

        return count($groups);
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return $values[$index];
    }
}
