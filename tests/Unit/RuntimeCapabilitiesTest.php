<?php

namespace Tests\Unit;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RuntimeCapabilitiesTest extends TestCase
{
    #[Test]
    #[DataProvider('runtimeCapabilityProvider')]
    public function every_runtime_exposes_its_explicit_capability_matrix(
        NexusRuntime $runtime,
        array $expectedCapabilities,
    ): void {
        $capabilities = new RuntimeCapabilities($runtime);

        $this->assertSame($runtime, $capabilities->runtime());
        $this->assertSame($expectedCapabilities, [
            'writes_public_world' => $capabilities->writesPublicWorld(),
            'writes_tenant_private' => $capabilities->writesTenantPrivate(),
            'runs_public_world_schedules' => $capabilities->runsPublicWorldSchedules(),
            'runs_tenant_schedules' => $capabilities->runsTenantSchedules(),
            'runs_platform_backups' => $capabilities->runsPlatformBackups(),
            'consumes_tenant_events' => $capabilities->consumesTenantEvents(),
            'sends_tenant_callbacks' => $capabilities->sendsTenantCallbacks(),
            'allows_interactive_setup' => $capabilities->allowsInteractiveSetup(),
        ]);
    }

    #[Test]
    public function capability_matrix_covers_every_runtime_case(): void
    {
        $matrixRuntimes = array_map(
            static fn (array $matrix): NexusRuntime => $matrix[0],
            array_values(iterator_to_array(self::runtimeCapabilityProvider())),
        );

        $this->assertSame(NexusRuntime::cases(), $matrixRuntimes);
    }

    /**
     * @return iterable<string, array{NexusRuntime, array<string, bool>}>
     */
    public static function runtimeCapabilityProvider(): iterable
    {
        yield 'standalone' => [NexusRuntime::Standalone, [
            'writes_public_world' => true,
            'writes_tenant_private' => true,
            'runs_public_world_schedules' => true,
            'runs_tenant_schedules' => true,
            'runs_platform_backups' => true,
            'consumes_tenant_events' => false,
            'sends_tenant_callbacks' => false,
            'allows_interactive_setup' => true,
        ]];

        yield 'hosted tenant' => [NexusRuntime::HostedTenant, [
            'writes_public_world' => false,
            'writes_tenant_private' => true,
            'runs_public_world_schedules' => false,
            'runs_tenant_schedules' => true,
            'runs_platform_backups' => false,
            'consumes_tenant_events' => true,
            'sends_tenant_callbacks' => true,
            'allows_interactive_setup' => false,
        ]];

        yield 'temporary world writer' => [NexusRuntime::WorldWriter, [
            'writes_public_world' => true,
            'writes_tenant_private' => false,
            'runs_public_world_schedules' => true,
            'runs_tenant_schedules' => false,
            'runs_platform_backups' => false,
            'consumes_tenant_events' => false,
            'sends_tenant_callbacks' => false,
            'allows_interactive_setup' => false,
        ]];
    }
}
