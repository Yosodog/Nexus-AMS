<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Enums\NexusRuntime;
use App\Enums\ProcessHeartbeatRole;
use App\Services\ProcessHeartbeatRecorder;
use App\Services\RuntimeCapabilities;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class InternalRuntimeHealthTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '01JZ0000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_api_token' => 'internal-test-token',
            'nexus.runtime' => NexusRuntime::Standalone->value,
            'nexus.managed' => false,
            'nexus.tenant_id' => null,
            'nexus.release_id' => 'test-release',
            'nexus.runtime_contract' => 1,
            'nexus.world_view_contract' => 0,
            'nexus.build.application_version' => '2026.8.0',
            'nexus.build.image_digest' => 'sha256:'.str_repeat('a', 64),
            'nexus.build.commit' => str_repeat('b', 40),
            'nexus.health.queue_max_age_seconds' => 180,
            'nexus.health.scheduler_max_age_seconds' => 180,
        ]);
    }

    public function test_internal_runtime_endpoints_require_the_tenant_service_token(): void
    {
        foreach (['build', 'readiness', 'health'] as $endpoint) {
            $this->getJson("/api/internal/v1/{$endpoint}")->assertUnauthorized();
            $this->withToken('wrong-token')
                ->getJson("/api/internal/v1/{$endpoint}")
                ->assertUnauthorized();
        }
    }

    public function test_authenticated_build_metadata_is_bounded_and_secret_free(): void
    {
        config([
            'database.connections.sqlite.password' => 'database-secret-value',
            'nexus.build.application_version' => "invalid\nversion",
            'nexus.build.image_digest' => 'sha256:not-a-digest',
            'nexus.build.commit' => 'commit-secret-value',
        ]);

        $response = $this->authorizedGet('/api/internal/v1/build')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('contract_version', 1)
            ->assertJsonPath('application', 'nexus-ams')
            ->assertJsonPath('application_version', 'unknown')
            ->assertJsonPath('image_digest', 'unknown')
            ->assertJsonPath('commit', 'unknown')
            ->assertJsonPath('release_id', 'test-release')
            ->assertJsonPath('runtime_mode', 'standalone')
            ->assertJsonPath('runtime_contract', 1)
            ->assertJsonPath('tenant_schema', 41)
            ->assertJsonPath('world_view_contract', null)
            ->assertJsonPath('capabilities', []);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('database-secret-value', $response->getContent());
        $this->assertStringNotContainsString('commit-secret-value', $response->getContent());
        $this->assertStringNotContainsString('internal-test-token', $response->getContent());
    }

    public function test_hosted_build_metadata_advertises_bootstrap_capability_only_in_hosted_mode(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        config([
            'nexus.managed' => true,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.world_view_contract' => 3,
        ]);

        $this->authorizedGet('/api/internal/v1/build')
            ->assertOk()
            ->assertJsonPath('runtime_mode', NexusRuntime::HostedTenant->value)
            ->assertJsonPath('capabilities', ['platform-bootstrap-v1']);

        $this->configureRuntime(NexusRuntime::WorldWriter);

        $this->authorizedGet('/api/internal/v1/build')
            ->assertOk()
            ->assertJsonPath('capabilities', []);
    }

    public function test_standalone_readiness_reports_core_compatibility_without_requiring_heartbeats(): void
    {
        $this->authorizedGet('/api/internal/v1/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('runtime_mode', 'standalone')
            ->assertJsonPath('tenant_id', null)
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.maintenance.status', 'inactive')
            ->assertJsonPath('checks.runtime_contract.status', 'compatible')
            ->assertJsonPath('checks.runtime_identity.status', 'compatible')
            ->assertJsonPath('checks.release.status', 'not_required')
            ->assertJsonPath('checks.tenant_schema.status', 'current')
            ->assertJsonPath('checks.world_view_contract.status', 'not_required')
            ->assertJsonPath('checks.world_views.status', 'not_required');

        $this->get('/up')->assertOk();
    }

    public function test_deep_health_requires_independent_fresh_queue_and_scheduler_heartbeats(): void
    {
        $this->authorizedGet('/api/internal/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.queue.status', 'missing')
            ->assertJsonPath('checks.scheduler.status', 'missing');

        $this->travelTo('2026-08-08 21:00:00');
        $recorder = app(ProcessHeartbeatRecorder::class);
        $recorder->record(ProcessHeartbeatRole::Queue);
        $recorder->record(ProcessHeartbeatRole::Scheduler);

        $this->authorizedGet('/api/internal/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.queue.status', 'fresh')
            ->assertJsonPath('checks.scheduler.status', 'fresh');

        $this->travelTo('2026-08-08 21:03:01');
        $this->authorizedGet('/api/internal/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue.status', 'stale')
            ->assertJsonPath('checks.queue.age_seconds', 181)
            ->assertJsonPath('checks.scheduler.status', 'stale');
    }

    public function test_deep_health_rejects_heartbeats_from_a_different_release(): void
    {
        app(ProcessHeartbeatRecorder::class)->record(ProcessHeartbeatRole::Queue);
        app(ProcessHeartbeatRecorder::class)->record(ProcessHeartbeatRole::Scheduler);
        config(['nexus.release_id' => 'next-release']);

        $this->authorizedGet('/api/internal/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue.status', 'release_mismatch')
            ->assertJsonPath('checks.scheduler.status', 'release_mismatch');
    }

    public function test_readiness_detects_pending_schema_and_cache_failure_without_error_details(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_08_08_203652_create_process_heartbeats_table')
            ->delete();

        $this->authorizedGet('/api/internal/v1/readiness')
            ->assertStatus(503)
            ->assertJsonPath('checks.tenant_schema.status', 'pending')
            ->assertJsonPath('checks.tenant_schema.pending_count', 1);

        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('cache-secret-detail'));

        $response = $this->authorizedGet('/api/internal/v1/readiness')
            ->assertStatus(503)
            ->assertJsonPath('checks.cache.status', 'unavailable');
        $this->assertStringNotContainsString('cache-secret-detail', $response->getContent());
    }

    public function test_readiness_sanitizes_database_probe_failures(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT 1 AS healthy')
            ->andThrow(new RuntimeException('database-host-secret-detail'));

        $response = $this->authorizedGet('/api/internal/v1/readiness')
            ->assertStatus(503)
            ->assertJsonPath('checks.database.status', 'unavailable')
            ->assertJsonPath('checks.tenant_schema.status', 'unavailable');

        $this->assertStringNotContainsString('database-host-secret-detail', $response->getContent());
    }

    public function test_hosted_readiness_rejects_physical_world_tables_even_with_valid_identity(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        config([
            'nexus.managed' => true,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.world_view_contract' => 3,
        ]);

        $this->authorizedGet('/api/internal/v1/readiness')
            ->assertStatus(503)
            ->assertJsonPath('runtime_mode', 'hosted-tenant')
            ->assertJsonPath('tenant_id', self::TENANT_ID)
            ->assertJsonPath('checks.runtime_identity.status', 'compatible')
            ->assertJsonPath('checks.release.status', 'compatible')
            ->assertJsonPath('checks.world_view_contract.status', 'compatible')
            ->assertJsonPath('checks.world_views.status', 'incompatible')
            ->assertJsonPath('checks.world_views.incompatible_count', 11);
    }

    public function test_hosted_readiness_rejects_each_configured_compatibility_mismatch(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        config([
            'nexus.managed' => false,
            'nexus.tenant_id' => 'not-an-immutable-id',
            'nexus.release_id' => 'unknown',
            'nexus.runtime_contract' => 2,
            'nexus.world_view_contract' => 2,
        ]);

        $this->authorizedGet('/api/internal/v1/readiness')
            ->assertStatus(503)
            ->assertJsonPath('tenant_id', null)
            ->assertJsonPath('checks.runtime_contract.status', 'mismatch')
            ->assertJsonPath('checks.runtime_identity.status', 'mismatch')
            ->assertJsonPath('checks.release.status', 'missing')
            ->assertJsonPath('checks.world_view_contract.status', 'mismatch');
    }

    public function test_world_writer_health_does_not_require_tenant_process_heartbeats(): void
    {
        $this->configureRuntime(NexusRuntime::WorldWriter);
        config([
            'nexus.managed' => true,
            'nexus.tenant_id' => null,
            'nexus.release_id' => 'writer-release',
        ]);

        $this->authorizedGet('/api/internal/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('runtime_mode', 'world-writer')
            ->assertJsonPath('checks.queue.status', 'not_required')
            ->assertJsonPath('checks.scheduler.status', 'not_required');
    }

    public function test_readiness_remains_authenticated_and_reports_maintenance_mode(): void
    {
        $maintenance = app(MaintenanceMode::class);
        $maintenance->activate(['time' => now()->getTimestamp()]);

        try {
            $this->getJson('/api/internal/v1/readiness')->assertUnauthorized();

            $this->authorizedGet('/api/internal/v1/readiness')
                ->assertStatus(503)
                ->assertJsonPath('checks.maintenance.status', 'active');
        } finally {
            $maintenance->deactivate();
        }
    }

    private function authorizedGet(string $uri): TestResponse
    {
        return $this->withToken('internal-test-token')->getJson($uri);
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
