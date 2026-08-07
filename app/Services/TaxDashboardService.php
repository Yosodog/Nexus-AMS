<?php

namespace App\Services;

use App\Models\Taxes;
use App\Models\TaxImportCheckpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TaxDashboardService
{
    public const PERIOD_DAYS = 30;

    public const EXPECTED_SYNC_INTERVAL_MINUTES = 60;

    public const STALE_AFTER_MINUTES = 120;

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
    ) {}

    /**
     * @return array{
     *     period: array{
     *         starts_at: Carbon,
     *         ends_at: Carbon,
     *         record_count: int,
     *         total_money: float,
     *         average_daily_money: float,
     *         resource_totals: array<string, float>,
     *         latest_recorded_at: Carbon|null
     *     },
     *     trend: array{
     *         previous_starts_at: Carbon,
     *         previous_ends_at: Carbon,
     *         previous_money: float,
     *         delta_money: float,
     *         percent_change: float|null,
     *         direction: 'up'|'down'|'flat'|'new'
     *     },
     *     freshness: array{
     *         status: 'fresh'|'stale'|'missing'|'failed',
     *         oldest_successful_at: Carbon|null,
     *         latest_attempted_at: Carbon|null,
     *         expected_interval_minutes: int,
     *         stale_after_minutes: int,
     *         exceptions: array<int, array{
     *             alliance_id: int|null,
     *             type: 'stale'|'missing'|'failed',
     *             message: string,
     *             occurred_at: Carbon|null
     *         }>
     *     },
     *     ledger_filters: array{
     *         from: string,
     *         to: string,
     *         direction: string,
     *         categories: array<int, string>
     *     }
     * }
     */
    public function getDashboard(): array
    {
        $now = now();
        $periodStartsAt = $now->copy()->subDays(self::PERIOD_DAYS - 1)->startOfDay();
        $periodEndsAt = $now->copy()->endOfDay();
        $previousEndsAt = $periodStartsAt->copy()->subSecond();
        $previousStartsAt = $previousEndsAt->copy()->subDays(self::PERIOD_DAYS - 1)->startOfDay();

        $period = $this->aggregatePeriod($periodStartsAt, $periodEndsAt);
        $previousMoney = (float) Taxes::query()
            ->whereBetween('date', [$previousStartsAt, $previousEndsAt])
            ->sum('money');
        $deltaMoney = $period['total_money'] - $previousMoney;

        return [
            'period' => [
                'starts_at' => $periodStartsAt,
                'ends_at' => $periodEndsAt,
                ...$period,
            ],
            'trend' => [
                'previous_starts_at' => $previousStartsAt,
                'previous_ends_at' => $previousEndsAt,
                'previous_money' => $previousMoney,
                'delta_money' => $deltaMoney,
                'percent_change' => $this->percentChange($period['total_money'], $previousMoney),
                'direction' => $this->trendDirection($period['total_money'], $previousMoney),
            ],
            'freshness' => $this->freshness($now),
            'ledger_filters' => [
                'from' => $periodStartsAt->toDateString(),
                'to' => $periodEndsAt->toDateString(),
                'direction' => 'income',
                'categories' => ['tax'],
            ],
        ];
    }

    /**
     * @return array{
     *     record_count: int,
     *     total_money: float,
     *     average_daily_money: float,
     *     resource_totals: array<string, float>,
     *     latest_recorded_at: Carbon|null
     * }
     */
    private function aggregatePeriod(Carbon $startsAt, Carbon $endsAt): array
    {
        $resourceColumns = PWHelperService::resources(false);
        $aggregateColumns = collect(['money', ...$resourceColumns])
            ->map(static fn (string $resource): string => "SUM(`{$resource}`) as `{$resource}`")
            ->implode(', ');

        $aggregate = Taxes::query()
            ->whereBetween('date', [$startsAt, $endsAt])
            ->selectRaw("COUNT(*) as record_count, MAX(`date`) as latest_recorded_at, {$aggregateColumns}")
            ->first();

        $totalMoney = (float) ($aggregate?->money ?? 0);

        return [
            'record_count' => (int) ($aggregate?->record_count ?? 0),
            'total_money' => $totalMoney,
            'average_daily_money' => $totalMoney / self::PERIOD_DAYS,
            'resource_totals' => collect($resourceColumns)
                ->mapWithKeys(static fn (string $resource): array => [
                    $resource => (float) ($aggregate?->{$resource} ?? 0),
                ])
                ->all(),
            'latest_recorded_at' => $aggregate?->latest_recorded_at === null
                ? null
                : Carbon::parse($aggregate->latest_recorded_at),
        ];
    }

    private function percentChange(float $currentMoney, float $previousMoney): ?float
    {
        if ($previousMoney === 0.0) {
            return $currentMoney === 0.0 ? 0.0 : null;
        }

        return (($currentMoney - $previousMoney) / abs($previousMoney)) * 100;
    }

    /**
     * @return 'up'|'down'|'flat'|'new'
     */
    private function trendDirection(float $currentMoney, float $previousMoney): string
    {
        if ($previousMoney === 0.0 && $currentMoney > 0.0) {
            return 'new';
        }

        return match (true) {
            $currentMoney > $previousMoney => 'up',
            $currentMoney < $previousMoney => 'down',
            default => 'flat',
        };
    }

    /**
     * @return array{
     *     status: 'fresh'|'stale'|'missing'|'failed',
     *     oldest_successful_at: Carbon|null,
     *     latest_attempted_at: Carbon|null,
     *     expected_interval_minutes: int,
     *     stale_after_minutes: int,
     *     exceptions: array<int, array{
     *         alliance_id: int|null,
     *         type: 'stale'|'missing'|'failed',
     *         message: string,
     *         occurred_at: Carbon|null
     *     }>
     * }
     */
    private function freshness(Carbon $now): array
    {
        $allianceIds = $this->membershipService->getAllianceIds()->values();

        if ($allianceIds->isEmpty()) {
            return [
                'status' => 'missing',
                'oldest_successful_at' => null,
                'latest_attempted_at' => null,
                'expected_interval_minutes' => self::EXPECTED_SYNC_INTERVAL_MINUTES,
                'stale_after_minutes' => self::STALE_AFTER_MINUTES,
                'exceptions' => [[
                    'alliance_id' => null,
                    'type' => 'missing',
                    'message' => 'No alliance is configured for tax collection.',
                    'occurred_at' => null,
                ]],
            ];
        }

        $checkpoints = TaxImportCheckpoint::query()
            ->whereIn('alliance_id', $allianceIds)
            ->get()
            ->keyBy(static fn (TaxImportCheckpoint $checkpoint): int => (int) $checkpoint->alliance_id);
        $staleBefore = $now->copy()->subMinutes(self::STALE_AFTER_MINUTES);
        $exceptions = collect();

        foreach ($allianceIds as $allianceId) {
            $checkpoint = $checkpoints->get($allianceId);

            if (! $checkpoint || $checkpoint->last_succeeded_at === null) {
                $exceptions->push([
                    'alliance_id' => $allianceId,
                    'type' => 'missing',
                    'message' => 'No successful tax sync has been recorded.',
                    'occurred_at' => $checkpoint?->last_attempted_at,
                ]);

                continue;
            }

            if ($checkpoint->latestAttemptFailed()) {
                $exceptions->push([
                    'alliance_id' => $allianceId,
                    'type' => 'failed',
                    'message' => 'The latest tax sync attempt failed.',
                    'occurred_at' => $checkpoint->last_failed_at,
                ]);

                continue;
            }

            if ($checkpoint->last_succeeded_at->lt($staleBefore)) {
                $exceptions->push([
                    'alliance_id' => $allianceId,
                    'type' => 'stale',
                    'message' => 'The latest successful tax sync is overdue.',
                    'occurred_at' => $checkpoint->last_succeeded_at,
                ]);
            }
        }

        $status = match (true) {
            $exceptions->contains('type', 'failed') => 'failed',
            $exceptions->contains('type', 'missing') => 'missing',
            $exceptions->contains('type', 'stale') => 'stale',
            default => 'fresh',
        };

        return [
            'status' => $status,
            'oldest_successful_at' => $this->oldestDate($checkpoints->pluck('last_succeeded_at')),
            'latest_attempted_at' => $this->latestDate($checkpoints->pluck('last_attempted_at')),
            'expected_interval_minutes' => self::EXPECTED_SYNC_INTERVAL_MINUTES,
            'stale_after_minutes' => self::STALE_AFTER_MINUTES,
            'exceptions' => $exceptions->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Carbon|null>  $dates
     */
    private function oldestDate(Collection $dates): ?Carbon
    {
        return $dates
            ->filter()
            ->sortBy(static fn (Carbon $date): int => $date->timestamp)
            ->first();
    }

    /**
     * @param  Collection<int, Carbon|null>  $dates
     */
    private function latestDate(Collection $dates): ?Carbon
    {
        return $dates
            ->filter()
            ->sortByDesc(static fn (Carbon $date): int => $date->timestamp)
            ->first();
    }
}
