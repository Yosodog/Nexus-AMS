<?php

namespace App\Services;

use App\Models\Alliance;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Models\Taxes;
use App\Models\TaxImportCheckpoint;
use App\Models\War;
use App\Services\Scheduling\ScheduledTaskFreshness;
use App\Services\Scheduling\ScheduledTaskFreshnessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SystemHealthService
{
    private const STATUS_HEALTHY = 'healthy';

    private const STATUS_WARNING = 'warning';

    private const STATUS_CRITICAL = 'critical';

    private const STATUS_UNKNOWN = 'unknown';

    private readonly ScheduledTaskFreshnessService $scheduledTaskFreshnessService;

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly PWHealthService $pwHealthService,
        ?ScheduledTaskFreshnessService $scheduledTaskFreshnessService = null,
    ) {
        $this->scheduledTaskFreshnessService = $scheduledTaskFreshnessService
            ?? app(ScheduledTaskFreshnessService::class);
    }

    /**
     * @return array{
     *     status: string,
     *     headline: string,
     *     summary: string,
     *     checked_at: Carbon,
     *     counts: array{healthy: int, warning: int, critical: int, unknown: int},
     *     checks: array<int, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $checks = collect([
            $this->pwApiCheck(),
            $this->taxImportCheck(),
            $this->freshnessCheck(
                key: 'nations',
                name: 'Nations',
                description: 'Most recent nation snapshot written by rolling syncs or subscription updates.',
                lastActivityAt: $this->latestTimestamp(Nation::query()->max('updated_at')),
                cadence: 'Daily rolling sync · stale after 50 hours',
                warningAfterMinutes: 26 * 60,
                criticalAfterMinutes: 50 * 60,
                staleGuidance: 'Check the rolling nation batch, queue workers, and P&W availability.',
            ),
            $this->freshnessCheck(
                key: 'alliances',
                name: 'Alliances',
                description: 'Most recent alliance snapshot persisted by the scheduled alliance sync.',
                lastActivityAt: $this->latestTimestamp(Alliance::query()->max('updated_at')),
                cadence: 'Twice daily · stale after 25 hours',
                warningAfterMinutes: 13 * 60,
                criticalAfterMinutes: 25 * 60,
                staleGuidance: 'Inspect the alliance sync batch and run it manually if the scheduler is healthy.',
            ),
            $this->freshnessCheck(
                key: 'wars',
                name: 'Wars',
                description: 'Most recent war snapshot received from the hourly sync or subscriptions.',
                lastActivityAt: $this->latestTimestamp(War::query()->max('updated_at')),
                cadence: 'Hourly · stale after 3 hours',
                warningAfterMinutes: 90,
                criticalAfterMinutes: 180,
                staleGuidance: 'Inspect the war sync batch, subscription ingestion, and queue workers.',
            ),
            $this->freshnessCheck(
                key: 'cities',
                name: 'Cities',
                description: 'Most recent city snapshot received from P&W subscription activity.',
                lastActivityAt: $this->latestTimestamp(City::query()->max('updated_at')),
                cadence: 'Event driven · stale after 48 hours',
                warningAfterMinutes: 24 * 60,
                criticalAfterMinutes: 48 * 60,
                staleGuidance: 'Confirm subscription ingestion is active and city update jobs are processing.',
            ),
            $this->freshnessCheck(
                key: 'market-prices',
                name: 'Market prices',
                description: 'Latest completed-trade price snapshot used by economy calculations.',
                lastActivityAt: $this->latestTimestamp(MarketPriceSnapshot::query()->max('calculated_at')),
                cadence: 'Hourly · stale after 3 hours',
                warningAfterMinutes: 90,
                criticalAfterMinutes: 180,
                staleGuidance: 'Review the market-prices refresh command and its upstream trade ingestion.',
            ),
            $this->freshnessCheck(
                key: 'profitability',
                name: 'Profitability snapshots',
                description: 'Latest member profitability calculation built from current nation and market data.',
                lastActivityAt: $this->latestTimestamp(NationProfitabilitySnapshot::query()->max('calculated_at')),
                cadence: 'Hourly · stale after 3 hours',
                warningAfterMinutes: 90,
                criticalAfterMinutes: 180,
                staleGuidance: 'Verify market prices are current, then inspect the profitability refresh command.',
            ),
        ])->concat($this->scheduledTaskChecks());

        $counts = [
            self::STATUS_HEALTHY => $checks->where('status', self::STATUS_HEALTHY)->count(),
            self::STATUS_WARNING => $checks->where('status', self::STATUS_WARNING)->count(),
            self::STATUS_CRITICAL => $checks->where('status', self::STATUS_CRITICAL)->count(),
            self::STATUS_UNKNOWN => $checks->where('status', self::STATUS_UNKNOWN)->count(),
        ];
        $status = $this->overallStatus($counts);

        return [
            'status' => $status,
            'headline' => match ($status) {
                self::STATUS_CRITICAL => 'Stale data needs attention',
                self::STATUS_WARNING => 'Some data is falling behind',
                self::STATUS_UNKNOWN => 'Health needs baseline data',
                default => 'Core data is current',
            },
            'summary' => $this->summary($counts),
            'checked_at' => now(),
            'counts' => $counts,
            'checks' => $checks->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function scheduledTaskChecks(): Collection
    {
        return $this->scheduledTaskFreshnessService
            ->snapshot()
            ->values()
            ->map(function (ScheduledTaskFreshness $freshness): array {
                $lastSucceededAt = $this->latestTimestamp($freshness->lastSucceededAt);
                $expectedBy = $this->latestTimestamp($freshness->expectedBy);
                $status = match (true) {
                    $lastSucceededAt === null => self::STATUS_UNKNOWN,
                    $freshness->isOverdue => self::STATUS_CRITICAL,
                    default => self::STATUS_HEALTHY,
                };

                return $this->buildCheck(
                    key: 'scheduler-'.str_replace(':', '-', $freshness->taskIdentifier),
                    name: $freshness->label,
                    description: 'Successful completion of a critical scheduled task.',
                    status: $status,
                    statusLabel: match ($status) {
                        self::STATUS_CRITICAL => 'Overdue',
                        self::STATUS_UNKNOWN => 'Never succeeded',
                        default => 'Current',
                    },
                    lastActivityAt: $lastSucceededAt,
                    lastActivityLabel: 'Last successful completion',
                    cadence: "Must succeed within {$freshness->maximumAgeMinutes} minutes",
                    detail: match ($status) {
                        self::STATUS_CRITICAL => 'The task has missed its configured success window.',
                        self::STATUS_UNKNOWN => 'No successful lifecycle record is available yet.',
                        default => 'The latest successful run is within its configured window.',
                    },
                    guidance: 'Inspect the task lifecycle failures and scheduler host before running the task manually.',
                    secondaryAt: $expectedBy,
                    secondaryLabel: 'Expected by',
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function pwApiCheck(): array
    {
        $checkedAt = $this->latestTimestamp($this->pwHealthService->lastCheckedAt());

        if ($checkedAt === null) {
            return $this->buildCheck(
                key: 'pw-api',
                name: 'Scheduler & P&W API',
                description: 'Minute-by-minute scheduler heartbeat and authenticated P&W API probe.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'No heartbeat',
                lastActivityAt: null,
                lastActivityLabel: 'Last successful probe',
                cadence: 'Every minute · stale after 5 minutes',
                detail: 'No scheduler heartbeat is available in the health cache.',
                guidance: 'Confirm the scheduler is running and the shared cache is reachable.',
            );
        }

        if ($this->pwHealthService->isDown()) {
            return $this->buildCheck(
                key: 'pw-api',
                name: 'Scheduler & P&W API',
                description: 'Minute-by-minute scheduler heartbeat and authenticated P&W API probe.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'API unavailable',
                lastActivityAt: $checkedAt,
                lastActivityLabel: 'Last probe',
                cadence: 'Every minute · stale after 5 minutes',
                detail: 'The latest authenticated P&W API probe failed.',
                guidance: 'Check P&W availability, API credentials, and recent application logs.',
            );
        }

        return $this->freshnessCheck(
            key: 'pw-api',
            name: 'Scheduler & P&W API',
            description: 'Minute-by-minute scheduler heartbeat and authenticated P&W API probe.',
            lastActivityAt: $checkedAt,
            cadence: 'Every minute · stale after 5 minutes',
            warningAfterMinutes: 2,
            criticalAfterMinutes: 5,
            staleGuidance: 'Confirm the scheduler is running and the shared cache is reachable.',
            lastActivityLabel: 'Last successful probe',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taxImportCheck(): array
    {
        $allianceIds = $this->membershipService->getAllianceIds()
            ->map(fn ($allianceId): int => (int) $allianceId)
            ->filter(fn (int $allianceId): bool => $allianceId > 0)
            ->unique()
            ->values();

        if ($allianceIds->isEmpty()) {
            return $this->buildCheck(
                key: 'taxes',
                name: 'Tax records',
                description: 'Per-alliance tax feed polling and durable tax imports.',
                status: self::STATUS_UNKNOWN,
                statusLabel: 'Not configured',
                lastActivityAt: null,
                lastActivityLabel: 'Last successful poll',
                cadence: 'Hourly at :15 · stale after 3 hours',
                detail: 'No alliance IDs are configured for tax collection.',
                guidance: 'Configure the primary alliance before relying on tax imports.',
            );
        }

        $checkpoints = TaxImportCheckpoint::query()
            ->whereIn('alliance_id', $allianceIds)
            ->get()
            ->keyBy('alliance_id');
        $missingFeeds = $allianceIds->filter(
            fn (int $allianceId): bool => $checkpoints->get($allianceId)?->last_succeeded_at === null
        )->count();
        $failedFeeds = $checkpoints->filter(
            fn (TaxImportCheckpoint $checkpoint): bool => $checkpoint->latestAttemptFailed()
        )->count();
        $oldestSuccess = $this->oldestTimestamp($checkpoints->pluck('last_succeeded_at'));
        $lastImportedAt = $this->latestTimestamp($checkpoints->max('last_imported_at'));
        $latestTaxRecordAt = $this->latestTimestamp(
            Taxes::query()->whereIn('receiver_id', $allianceIds)->max('date')
        );
        $feedCount = $allianceIds->count();

        if ($failedFeeds > 0) {
            return $this->buildCheck(
                key: 'taxes',
                name: 'Tax records',
                description: 'Per-alliance tax feed polling and durable tax imports.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'Import failed',
                lastActivityAt: $oldestSuccess,
                lastActivityLabel: 'Oldest successful poll',
                cadence: 'Hourly at :15 · stale after 3 hours',
                detail: "{$failedFeeds} of {$feedCount} configured alliance feeds failed their latest attempt.",
                guidance: 'Review tax import logs and credentials before retrying the collector.',
                secondaryAt: $lastImportedAt ?? $latestTaxRecordAt,
                secondaryLabel: $lastImportedAt !== null ? 'Last tax record imported' : 'Newest stored tax record',
            );
        }

        $check = $this->freshnessCheck(
            key: 'taxes',
            name: 'Tax records',
            description: 'Per-alliance tax feed polling and durable tax imports.',
            lastActivityAt: $oldestSuccess,
            cadence: 'Hourly at :15 · stale after 3 hours',
            warningAfterMinutes: 90,
            criticalAfterMinutes: 180,
            staleGuidance: 'Check the scheduler, P&W credentials, and tax collector logs.',
            lastActivityLabel: 'Oldest successful poll',
            secondaryAt: $lastImportedAt ?? $latestTaxRecordAt,
            secondaryLabel: $lastImportedAt !== null ? 'Last tax record imported' : 'Newest stored tax record',
        );

        if ($missingFeeds > 0) {
            if ($check['status'] !== self::STATUS_CRITICAL) {
                $check['status'] = self::STATUS_WARNING;
                $check['status_label'] = 'Missing feed';
            }

            $check['detail'] = "{$missingFeeds} of {$feedCount} configured alliance feeds have no health baseline yet. {$check['detail']}";
        } else {
            $check['detail'] = "All {$feedCount} configured alliance feeds have reported a successful poll. {$check['detail']}";
        }

        return $check;
    }

    /**
     * @return array<string, mixed>
     */
    private function freshnessCheck(
        string $key,
        string $name,
        string $description,
        ?Carbon $lastActivityAt,
        string $cadence,
        int $warningAfterMinutes,
        int $criticalAfterMinutes,
        string $staleGuidance,
        string $lastActivityLabel = 'Last update',
        ?Carbon $secondaryAt = null,
        ?string $secondaryLabel = null,
    ): array {
        if ($lastActivityAt === null) {
            return $this->buildCheck(
                key: $key,
                name: $name,
                description: $description,
                status: self::STATUS_UNKNOWN,
                statusLabel: 'No data',
                lastActivityAt: null,
                lastActivityLabel: $lastActivityLabel,
                cadence: $cadence,
                detail: 'No completed activity is recorded yet.',
                guidance: $staleGuidance,
                secondaryAt: $secondaryAt,
                secondaryLabel: $secondaryLabel,
            );
        }

        $ageMinutes = $lastActivityAt->isFuture()
            ? 0
            : (int) $lastActivityAt->diffInMinutes(now());
        [$status, $statusLabel, $detail] = match (true) {
            $ageMinutes >= $criticalAfterMinutes => [
                self::STATUS_CRITICAL,
                'Stale',
                'This signal exceeds the stale-data threshold.',
            ],
            $ageMinutes >= $warningAfterMinutes => [
                self::STATUS_WARNING,
                'Behind',
                'This signal is older than its expected update window.',
            ],
            default => [
                self::STATUS_HEALTHY,
                'Current',
                'Activity is within the expected update window.',
            ],
        };

        return $this->buildCheck(
            key: $key,
            name: $name,
            description: $description,
            status: $status,
            statusLabel: $statusLabel,
            lastActivityAt: $lastActivityAt,
            lastActivityLabel: $lastActivityLabel,
            cadence: $cadence,
            detail: $detail,
            guidance: $staleGuidance,
            secondaryAt: $secondaryAt,
            secondaryLabel: $secondaryLabel,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCheck(
        string $key,
        string $name,
        string $description,
        string $status,
        string $statusLabel,
        ?Carbon $lastActivityAt,
        string $lastActivityLabel,
        string $cadence,
        string $detail,
        string $guidance,
        ?Carbon $secondaryAt = null,
        ?string $secondaryLabel = null,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'status_label' => $statusLabel,
            'last_activity_at' => $lastActivityAt,
            'last_activity_label' => $lastActivityLabel,
            'secondary_at' => $secondaryAt,
            'secondary_label' => $secondaryLabel,
            'cadence' => $cadence,
            'detail' => $detail,
            'guidance' => $guidance,
        ];
    }

    private function latestTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function oldestTimestamp(Collection $values): ?Carbon
    {
        return $values
            ->filter()
            ->map(fn ($value): Carbon => $this->latestTimestamp($value))
            ->sortBy(fn (Carbon $value): int => $value->getTimestamp())
            ->first();
    }

    /**
     * @param  array{healthy: int, warning: int, critical: int, unknown: int}  $counts
     */
    private function overallStatus(array $counts): string
    {
        return match (true) {
            $counts[self::STATUS_CRITICAL] > 0 => self::STATUS_CRITICAL,
            $counts[self::STATUS_WARNING] > 0 => self::STATUS_WARNING,
            $counts[self::STATUS_UNKNOWN] > 0 => self::STATUS_UNKNOWN,
            default => self::STATUS_HEALTHY,
        };
    }

    /**
     * @param  array{healthy: int, warning: int, critical: int, unknown: int}  $counts
     */
    private function summary(array $counts): string
    {
        if ($counts[self::STATUS_CRITICAL] > 0) {
            return $counts[self::STATUS_CRITICAL].' critical and '
                .$counts[self::STATUS_WARNING].' warning checks need review.';
        }

        if ($counts[self::STATUS_WARNING] > 0) {
            return $counts[self::STATUS_WARNING].' checks are outside their expected update window.';
        }

        if ($counts[self::STATUS_UNKNOWN] > 0) {
            return $counts[self::STATUS_UNKNOWN].' checks do not have enough data to establish health yet.';
        }

        return 'All monitored pipelines are within their expected update windows.';
    }
}
