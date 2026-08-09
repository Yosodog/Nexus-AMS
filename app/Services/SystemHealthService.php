<?php

namespace App\Services;

use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Enums\ScheduledTaskRunStatus;
use App\Models\Alliance;
use App\Models\City;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPeerKey;
use App\Models\MarketPriceSnapshot;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Models\ScheduledTaskRun;
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
        ])->concat($this->scheduledTaskChecks())->concat($this->federationChecks());

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
     * Federation checks deliberately select and return only workflow metadata and timestamps.
     * No encrypted payload, key material, title, target, or instruction field is read for health.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function federationChecks(): Collection
    {
        $identityCheck = $this->federationIdentityCheck();

        if (! (bool) config('federation.enabled', false)) {
            return collect([$identityCheck]);
        }

        return collect([
            $identityCheck,
            ...$this->federationSchedulerChecks()->all(),
            $this->federationOutboxCheck(),
            $this->federationQuarantineCheck(),
            $this->federationLinkFreshnessCheck(),
            $this->federationPendingChangeCheck(),
            $this->federationHeldOperationCheck(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function federationIdentityCheck(): array
    {
        if (! (bool) config('federation.enabled', false)) {
            return $this->buildCheck(
                key: 'federation-identity',
                name: 'Federation configuration',
                description: 'Local federation identity and protocol configuration.',
                status: self::STATUS_HEALTHY,
                statusLabel: 'Disabled',
                lastActivityAt: null,
                lastActivityLabel: 'Last identity change',
                cadence: 'Only evaluated while federation is enabled',
                detail: 'Federation is disabled by the hard server gate.',
                guidance: 'Enable federation only after confirming the installation origin and key storage configuration.',
            );
        }

        $identity = FederationIdentity::query()
            ->select(['id', 'origin', 'ownership_epoch', 'enabled', 'updated_at'])
            ->first();

        if (! $identity instanceof FederationIdentity) {
            return $this->buildCheck(
                key: 'federation-identity',
                name: 'Federation configuration',
                description: 'Local federation identity and protocol configuration.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'Identity missing',
                lastActivityAt: null,
                lastActivityLabel: 'Last identity change',
                cadence: 'Required while federation is enabled',
                detail: 'The federation server gate is enabled but no local installation identity exists.',
                guidance: 'Open federation settings and enable the local identity to generate its first key generation.',
            );
        }

        $activeKeyStatus = FederationIdentityKey::query()
            ->where('identity_id', $identity->id)
            ->where('active_key', 1)
            ->value('status');

        if (! $identity->enabled) {
            return $this->buildCheck(
                key: 'federation-identity',
                name: 'Federation configuration',
                description: 'Local federation identity and protocol configuration.',
                status: self::STATUS_WARNING,
                statusLabel: 'Identity disabled',
                lastActivityAt: $identity->updated_at,
                lastActivityLabel: 'Last identity change',
                cadence: 'Required while federation is enabled',
                detail: 'The server gate is enabled but the local federation identity is disabled.',
                guidance: 'Enable the identity before accepting links or federation messages.',
            );
        }

        $activeKeyIsActive = $activeKeyStatus instanceof FederationKeyStatus
            ? $activeKeyStatus === FederationKeyStatus::Active
            : $activeKeyStatus === FederationKeyStatus::Active->value;

        if (! $activeKeyIsActive) {
            return $this->buildCheck(
                key: 'federation-identity',
                name: 'Federation configuration',
                description: 'Local federation identity and protocol configuration.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'Active key missing',
                lastActivityAt: $identity->updated_at,
                lastActivityLabel: 'Last identity change',
                cadence: 'Required while federation is enabled',
                detail: 'The enabled federation identity does not have an active signing and encryption key generation.',
                guidance: 'Review key generation status and complete administrator-led key recovery before enabling ingress.',
            );
        }

        if (! $this->federationConfigurationIsValid($identity)) {
            return $this->buildCheck(
                key: 'federation-identity',
                name: 'Federation configuration',
                description: 'Local federation identity and protocol configuration.',
                status: self::STATUS_CRITICAL,
                statusLabel: 'Configuration invalid',
                lastActivityAt: $identity->updated_at,
                lastActivityLabel: 'Last identity change',
                cadence: 'Required while federation is enabled',
                detail: 'The local origin, HTTPS policy, protocol version, or federation size limits are invalid.',
                guidance: 'Correct the deployment configuration before approving any peer or accepting federation traffic.',
            );
        }

        return $this->buildCheck(
            key: 'federation-identity',
            name: 'Federation configuration',
            description: 'Local federation identity and protocol configuration.',
            status: self::STATUS_HEALTHY,
            statusLabel: 'Valid',
            lastActivityAt: $identity->updated_at,
            lastActivityLabel: 'Last identity change',
            cadence: 'Identity and protocol configuration',
            detail: 'The local identity, active key generation, origin, and protocol limits are valid.',
            guidance: 'Keep the public origin pinned and rotate keys only through the federation workflow.',
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function federationSchedulerChecks(): Collection
    {
        if (! (bool) config('scheduler_lifecycle.enabled', true)) {
            return collect([
                $this->buildCheck(
                    key: 'federation-scheduler-lifecycle',
                    name: 'Federation scheduler lifecycle',
                    description: 'Recorded success signals for federation recovery and maintenance jobs.',
                    status: self::STATUS_UNKNOWN,
                    statusLabel: 'Lifecycle disabled',
                    lastActivityAt: null,
                    lastActivityLabel: 'Last successful completion',
                    cadence: 'Lifecycle recording is disabled',
                    detail: 'Scheduler success cannot be measured while scheduler lifecycle recording is disabled.',
                    guidance: 'Enable scheduler lifecycle recording to monitor federation recovery freshness.',
                ),
            ]);
        }

        $tasks = [
            [
                'key' => 'federation-scheduler-outbox',
                'name' => 'Federation outbox recovery',
                'identifier' => 'job:App.Jobs.SweepFederationOutboxJob',
                'cadence' => 'Every minute · stale after 5 minutes',
                'warning' => 3,
                'critical' => 5,
            ],
            [
                'key' => 'federation-scheduler-reconciliation',
                'name' => 'Federation reconciliation',
                'identifier' => 'job:App.Jobs.ReconcileFederationLinksJob',
                'cadence' => 'Every 15 minutes · stale after 30 minutes',
                'warning' => 20,
                'critical' => 30,
            ],
            [
                'key' => 'federation-scheduler-expiry',
                'name' => 'Federation expiry enforcement',
                'identifier' => 'job:App.Jobs.ExpireFederationResourcesJob',
                'cadence' => 'Every 5 minutes · stale after 15 minutes',
                'warning' => 10,
                'critical' => 15,
            ],
            [
                'key' => 'federation-scheduler-pruning',
                'name' => 'Federation message pruning',
                'identifier' => 'job:App.Jobs.PruneFederationMessagesJob',
                'cadence' => 'Daily · stale after 48 hours',
                'warning' => 36 * 60,
                'critical' => 48 * 60,
            ],
        ];

        return collect($tasks)->map(function (array $task): array {
            $lastSucceededAt = $this->latestTimestamp(
                ScheduledTaskRun::query()
                    ->where('task_identifier', $task['identifier'])
                    ->where('status', ScheduledTaskRunStatus::Success->value)
                    ->max('finished_at'),
            );

            return $this->freshnessCheck(
                key: $task['key'],
                name: $task['name'],
                description: 'Recorded success signal for a federation maintenance job.',
                lastActivityAt: $lastSucceededAt,
                cadence: $task['cadence'],
                warningAfterMinutes: $task['warning'],
                criticalAfterMinutes: $task['critical'],
                staleGuidance: 'Inspect the scheduler host, queue worker, and federation job failures.',
                lastActivityLabel: 'Last successful completion',
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function federationOutboxCheck(): array
    {
        $query = FederationOutboxMessage::query()
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Delivering->value]);
        $count = (clone $query)->count();
        $oldest = $this->latestTimestamp((clone $query)->min('created_at'));

        return $this->federationBacklogCheck(
            key: 'federation-outbox',
            name: 'Federation outbox',
            description: 'Durable peer messages waiting for delivery or transport completion.',
            count: $count,
            oldest: $oldest,
            emptyDetail: 'No federation messages are waiting for delivery.',
            presentDetail: 'Pending federation messages are awaiting delivery or transport completion.',
            cadence: 'Every minute · oldest pending message monitored',
            guidance: 'Inspect the outbox job, peer link state, retry code, and queue worker health.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function federationQuarantineCheck(): array
    {
        $query = FederationInboxMessage::query()
            ->where('status', InboxStatus::Quarantined->value);
        $count = (clone $query)->count();
        $oldest = $this->latestTimestamp((clone $query)->min('quarantined_at'))
            ?? $this->latestTimestamp((clone $query)->min('created_at'));

        return $this->federationCountCheck(
            key: 'federation-inbox-quarantine',
            name: 'Federation quarantined inbox',
            description: 'Inbound messages rejected by protocol or authorization validation.',
            count: $count,
            lastActivityAt: $oldest,
            lastActivityLabel: 'Oldest quarantined message',
            emptyDetail: 'No inbound federation messages are quarantined.',
            presentDetail: 'Quarantined federation messages require diagnostic review.',
            guidance: 'Review safe error codes and peer state without exposing decrypted content.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function federationLinkFreshnessCheck(): array
    {
        $activeLinks = FederationLink::query()
            ->where('status', FederationLinkStatus::Active->value);
        $activeCount = (clone $activeLinks)->count();
        $staleAfterMinutes = max((int) config('federation.reconciliation_interval_minutes', 15) * 2, 30);
        $staleLinks = (clone $activeLinks)->where(function ($query) use ($staleAfterMinutes): void {
            $query
                ->whereNull('last_contact_at')
                ->orWhere('last_contact_at', '<', now()->subMinutes($staleAfterMinutes));
        });
        $staleCount = (clone $staleLinks)->count();
        $oldest = $this->latestTimestamp((clone $staleLinks)->min('last_contact_at'));

        if ($activeCount === 0) {
            return $this->buildCheck(
                key: 'federation-links',
                name: 'Federation peer links',
                description: 'Contact freshness for active bilateral federation links.',
                status: self::STATUS_HEALTHY,
                statusLabel: 'No active peers',
                lastActivityAt: null,
                lastActivityLabel: 'Oldest active peer contact',
                cadence: "Every {$staleAfterMinutes} minutes · stale threshold",
                detail: 'No active federation peer links are configured.',
                guidance: 'Approve a bilateral link before enabling coalition capabilities.',
            );
        }

        return $this->federationCountCheck(
            key: 'federation-links',
            name: 'Federation peer links',
            description: 'Contact freshness for active bilateral federation links.',
            count: $staleCount,
            lastActivityAt: $oldest,
            lastActivityLabel: 'Oldest stale peer contact',
            emptyDetail: "All {$activeCount} active federation peer links contacted within the expected window.",
            presentDetail: "{$staleCount} of {$activeCount} active federation peer links are stale or have no contact baseline.",
            guidance: 'Inspect reconciliation scheduling, peer availability, and the pinned endpoint.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function federationPendingChangeCheck(): array
    {
        $pendingLocalKeys = FederationIdentityKey::query()
            ->where('status', FederationKeyStatus::Pending->value)
            ->count();
        $pendingPeerKeys = FederationPeerKey::query()
            ->where('status', FederationKeyStatus::Pending->value)
            ->count();
        $pendingEndpoints = FederationLinkInvitation::query()
            ->whereIn('direction', ['endpoint_inbound', 'endpoint_outbound'])
            ->whereIn('status', [
                FederationWorkflowStatus::Pending->value,
                FederationWorkflowStatus::Approved->value,
            ])
            ->count();
        $count = $pendingLocalKeys + $pendingPeerKeys + $pendingEndpoints;

        return $this->federationCountCheck(
            key: 'federation-pending-changes',
            name: 'Federation key and endpoint changes',
            description: 'Administrator-led key rotations and endpoint proposals awaiting completion.',
            count: $count,
            lastActivityAt: null,
            lastActivityLabel: 'Pending change baseline',
            emptyDetail: 'No federation key or pinned-endpoint changes are waiting for approval.',
            presentDetail: "{$count} federation key or pinned-endpoint changes are awaiting approval or activation.",
            guidance: 'Complete or explicitly reject pending changes; discovery never changes a pinned endpoint automatically.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function federationHeldOperationCheck(): array
    {
        $query = MilcomOperation::query()->where('federation_action_required', true);
        $count = (clone $query)->count();
        $oldest = $this->latestTimestamp((clone $query)->min('federation_held_at'))
            ?? $this->latestTimestamp((clone $query)->min('updated_at'));

        return $this->federationCountCheck(
            key: 'federation-held-imports',
            name: 'Federation-held operations',
            description: 'Local Milcom operations frozen pending an officer resolution after remote invalidation.',
            count: $count,
            lastActivityAt: $oldest,
            lastActivityLabel: 'Oldest held operation',
            emptyDetail: 'No imported Milcom operations are under a federation action hold.',
            presentDetail: "{$count} imported Milcom operations require an officer resolution.",
            guidance: 'Review the hold and either continue independently or retire the local operation with an audit reason.',
        );
    }

    private function federationConfigurationIsValid(FederationIdentity $identity): bool
    {
        $parts = parse_url((string) $identity->origin);
        $limits = config('federation.limits', []);
        $resourceSchemas = config('federation.resource_schemas', []);
        $ports = array_map('intval', (array) config('federation.network.allowed_ports', []));

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && in_array((int) ($parts['port'] ?? 443), $ports, true)
            && (bool) config('federation.network.require_https', true)
            && (string) config('federation.protocol_version', '') === '1.0'
            && is_array($resourceSchemas)
            && isset($resourceSchemas['milcom.war-plan-snapshot'])
            && is_array($limits)
            && (int) ($limits['outer_request_bytes'] ?? 0) > 0
            && (int) ($limits['decrypted_payload_bytes'] ?? 0) > 0
            && (int) ($limits['targets_per_publication'] ?? 0) > 0
            && (int) ($limits['recipient_instructions_characters'] ?? 0) > 0
            && (int) $identity->ownership_epoch > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function federationBacklogCheck(
        string $key,
        string $name,
        string $description,
        int $count,
        ?Carbon $oldest,
        string $emptyDetail,
        string $presentDetail,
        string $cadence,
        string $guidance,
    ): array {
        if ($count === 0 || $oldest === null) {
            return $this->buildCheck(
                key: $key,
                name: $name,
                description: $description,
                status: self::STATUS_HEALTHY,
                statusLabel: 'Clear',
                lastActivityAt: $oldest,
                lastActivityLabel: 'Oldest pending item',
                cadence: $cadence,
                detail: $emptyDetail,
                guidance: $guidance,
            );
        }

        $ageMinutes = $oldest->isFuture() ? 0 : (int) $oldest->diffInMinutes(now());
        [$status, $statusLabel] = match (true) {
            $ageMinutes >= 30 => [self::STATUS_CRITICAL, 'Stale'],
            $ageMinutes >= 5 => [self::STATUS_WARNING, 'Behind'],
            default => [self::STATUS_HEALTHY, 'Current'],
        };

        return $this->buildCheck(
            key: $key,
            name: $name,
            description: $description,
            status: $status,
            statusLabel: $statusLabel,
            lastActivityAt: $oldest,
            lastActivityLabel: 'Oldest pending item',
            cadence: $cadence,
            detail: "{$count} item(s) are pending. {$presentDetail}",
            guidance: $guidance,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function federationCountCheck(
        string $key,
        string $name,
        string $description,
        int $count,
        ?Carbon $lastActivityAt,
        string $lastActivityLabel,
        string $emptyDetail,
        string $presentDetail,
        string $guidance,
    ): array {
        return $this->buildCheck(
            key: $key,
            name: $name,
            description: $description,
            status: $count > 0 ? self::STATUS_WARNING : self::STATUS_HEALTHY,
            statusLabel: $count > 0 ? 'Needs review' : 'Clear',
            lastActivityAt: $lastActivityAt,
            lastActivityLabel: $lastActivityLabel,
            cadence: 'Payload-free metadata check',
            detail: $count > 0 ? $presentDetail : $emptyDetail,
            guidance: $guidance,
        );
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
