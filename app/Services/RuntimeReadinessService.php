<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NexusRuntime;
use App\Enums\ProcessHeartbeatRole;
use App\Services\World\WorldModelManifest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final readonly class RuntimeReadinessService
{
    public function __construct(
        private RuntimeBuildMetadata $build,
        private RuntimeCapabilities $capabilities,
        private Migrator $migrator,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     status: 'ready'|'not_ready',
     *     checked_at: string,
     *     checks: array<string, array<string, int|string|null>>
     * }
     */
    public function readiness(): array
    {
        $database = $this->databaseCheck();
        $cache = $this->cacheCheck();
        $runtimeContract = $this->runtimeContractCheck();
        $runtimeIdentity = $this->runtimeIdentityCheck();
        $release = $this->releaseCheck();
        $tenantSchema = $database['status'] === 'ok'
            ? $this->tenantSchemaCheck()
            : ['status' => 'unavailable', 'pending_count' => null];
        $worldViewContract = $this->worldViewContractCheck();
        $worldViews = $database['status'] === 'ok'
            ? $this->worldViewsCheck()
            : $this->unavailableWorldViewsCheck();
        $maintenance = ['status' => app()->isDownForMaintenance() ? 'active' : 'inactive'];
        $ready = $database['status'] === 'ok'
            && $cache['status'] === 'ok'
            && $runtimeContract['status'] === 'compatible'
            && $runtimeIdentity['status'] === 'compatible'
            && in_array($release['status'], ['compatible', 'not_required'], true)
            && $tenantSchema['status'] === 'current'
            && in_array($worldViewContract['status'], ['compatible', 'not_required'], true)
            && in_array($worldViews['status'], ['compatible', 'not_required'], true)
            && $maintenance['status'] === 'inactive';

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_ready',
            'checked_at' => now()->toIso8601String(),
            'checks' => [
                'database' => $database,
                'cache' => $cache,
                'maintenance' => $maintenance,
                'runtime_contract' => $runtimeContract,
                'runtime_identity' => $runtimeIdentity,
                'release' => $release,
                'tenant_schema' => $tenantSchema,
                'world_view_contract' => $worldViewContract,
                'world_views' => $worldViews,
            ],
        ];
    }

    /**
     * @return array{
     *     healthy: bool,
     *     status: 'healthy'|'unhealthy',
     *     checked_at: string,
     *     checks: array<string, array<string, int|string|null>>
     * }
     */
    public function deepHealth(): array
    {
        $readiness = $this->readiness();
        $databaseAvailable = $readiness['checks']['database']['status'] === 'ok';
        $queue = $this->heartbeatCheck(
            ProcessHeartbeatRole::Queue,
            'queue_max_age_seconds',
            $databaseAvailable,
        );
        $scheduler = $this->heartbeatCheck(
            ProcessHeartbeatRole::Scheduler,
            'scheduler_max_age_seconds',
            $databaseAvailable,
        );
        $healthy = $readiness['ready']
            && in_array($queue['status'], ['fresh', 'not_required'], true)
            && in_array($scheduler['status'], ['fresh', 'not_required'], true);

        return [
            'healthy' => $healthy,
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checked_at' => $readiness['checked_at'],
            'checks' => [
                ...$readiness['checks'],
                'queue' => $queue,
                'scheduler' => $scheduler,
            ],
        ];
    }

    /** @return array{status: 'ok'|'unavailable'} */
    private function databaseCheck(): array
    {
        try {
            return ['status' => DB::selectOne('SELECT 1 AS healthy') !== null ? 'ok' : 'unavailable'];
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** @return array{status: 'ok'|'unavailable'} */
    private function cacheCheck(): array
    {
        $key = 'runtime:readiness-probe:'.Str::lower((string) Str::ulid());
        $value = Str::random(32);

        try {
            Cache::put($key, $value, 5);
            $cached = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => is_string($cached) && hash_equals($value, $cached)
                    ? 'ok'
                    : 'unavailable',
            ];
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** @return array{status: 'compatible'|'mismatch'} */
    private function runtimeContractCheck(): array
    {
        return [
            'status' => $this->build->configuredRuntimeContract() === RuntimeBuildMetadata::RUNTIME_CONTRACT
                ? 'compatible'
                : 'mismatch',
        ];
    }

    /** @return array{status: 'compatible'|'mismatch'} */
    private function runtimeIdentityCheck(): array
    {
        $compatible = match ($this->build->runtime()) {
            NexusRuntime::Standalone => ! $this->build->managed()
                && ! $this->build->hasConfiguredTenantId(),
            NexusRuntime::HostedTenant => $this->build->managed()
                && $this->build->tenantId() !== null,
            NexusRuntime::WorldWriter => $this->build->managed()
                && ! $this->build->hasConfiguredTenantId(),
        };

        return ['status' => $compatible ? 'compatible' : 'mismatch'];
    }

    /** @return array{status: 'compatible'|'missing'|'not_required'} */
    private function releaseCheck(): array
    {
        if ($this->build->runtime() === NexusRuntime::Standalone) {
            return ['status' => 'not_required'];
        }

        return ['status' => $this->build->hasConfiguredReleaseId() ? 'compatible' : 'missing'];
    }

    /** @return array{status: 'current'|'missing'|'pending'|'unavailable', pending_count: int|null} */
    private function tenantSchemaCheck(): array
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return ['status' => 'missing', 'pending_count' => null];
            }

            $migrationFiles = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->getRepository()->getRan();
            $pendingCount = count(array_diff(array_keys($migrationFiles), $ran));

            return [
                'status' => $pendingCount === 0 ? 'current' : 'pending',
                'pending_count' => min($pendingCount, 1_000),
            ];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'pending_count' => null];
        }
    }

    /** @return array{status: 'compatible'|'mismatch'|'not_required'} */
    private function worldViewContractCheck(): array
    {
        if ($this->build->runtime() !== NexusRuntime::HostedTenant) {
            return ['status' => 'not_required'];
        }

        $contract = $this->build->configuredWorldViewContract();

        return [
            'status' => $contract >= RuntimeBuildMetadata::WORLD_VIEW_MIN
                && $contract <= RuntimeBuildMetadata::WORLD_VIEW_MAX
                    ? 'compatible'
                    : 'mismatch',
        ];
    }

    /** @return array{status: 'compatible'|'incompatible'|'missing'|'not_required'|'unavailable', missing_count: int, incompatible_count: int} */
    private function worldViewsCheck(): array
    {
        if ($this->build->runtime() !== NexusRuntime::HostedTenant) {
            return [
                'status' => 'not_required',
                'missing_count' => 0,
                'incompatible_count' => 0,
            ];
        }

        try {
            $required = array_map(strtolower(...), array_keys(WorldModelManifest::modelsByTable()));
            $views = array_values(array_filter(array_map(
                static fn (array $view): string => strtolower((string) ($view['name'] ?? '')),
                Schema::getViews(),
            )));
            $tables = array_values(array_filter(array_map(
                static fn (array $table): string => strtolower((string) ($table['name'] ?? '')),
                Schema::getTables(),
            )));
            $missingViews = array_diff($required, $views);
            $knownObjects = array_unique([...$views, ...$tables]);
            $missingObjects = array_diff($required, $knownObjects);
            $incompatibleObjects = array_diff($missingViews, $missingObjects);

            return [
                'status' => $missingObjects !== []
                    ? 'missing'
                    : ($incompatibleObjects !== [] ? 'incompatible' : 'compatible'),
                'missing_count' => count($missingObjects),
                'incompatible_count' => count($incompatibleObjects),
            ];
        } catch (Throwable) {
            return $this->unavailableWorldViewsCheck();
        }
    }

    /** @return array{status: 'not_required'|'unavailable', missing_count: 0, incompatible_count: 0} */
    private function unavailableWorldViewsCheck(): array
    {
        return [
            'status' => $this->build->runtime() === NexusRuntime::HostedTenant
                ? 'unavailable'
                : 'not_required',
            'missing_count' => 0,
            'incompatible_count' => 0,
        ];
    }

    /**
     * @return array{
     *     status: 'fresh'|'future'|'missing'|'not_required'|'release_mismatch'|'stale'|'unavailable',
     *     age_seconds: int|null,
     *     threshold_seconds: int
     * }
     */
    private function heartbeatCheck(
        ProcessHeartbeatRole $role,
        string $thresholdKey,
        bool $databaseAvailable,
    ): array {
        $threshold = $this->heartbeatThreshold($thresholdKey);

        if (! $this->capabilities->runsTenantSchedules()) {
            return [
                'status' => 'not_required',
                'age_seconds' => null,
                'threshold_seconds' => $threshold,
            ];
        }

        if (! $databaseAvailable) {
            return [
                'status' => 'unavailable',
                'age_seconds' => null,
                'threshold_seconds' => $threshold,
            ];
        }

        try {
            $heartbeat = DB::table('process_heartbeats')
                ->where('role', $role->value)
                ->first(['release_id', 'last_seen_at']);

            if ($heartbeat === null) {
                return [
                    'status' => 'missing',
                    'age_seconds' => null,
                    'threshold_seconds' => $threshold,
                ];
            }

            $lastSeenAt = CarbonImmutable::parse((string) $heartbeat->last_seen_at);
            $rawAge = now()->getTimestamp() - $lastSeenAt->getTimestamp();
            $age = max(0, min(86_400, $rawAge));

            if (! is_string($heartbeat->release_id)
                || ! hash_equals($this->build->releaseId(), $heartbeat->release_id)) {
                $status = 'release_mismatch';
            } elseif ($rawAge < -30) {
                $status = 'future';
            } else {
                $status = $age <= $threshold ? 'fresh' : 'stale';
            }

            return [
                'status' => $status,
                'age_seconds' => $age,
                'threshold_seconds' => $threshold,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'age_seconds' => null,
                'threshold_seconds' => $threshold,
            ];
        }
    }

    private function heartbeatThreshold(string $key): int
    {
        $threshold = config('nexus.health.'.$key);

        return is_int($threshold) && $threshold >= 30 && $threshold <= 3_600
            ? $threshold
            : 180;
    }
}
