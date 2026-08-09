<?php

namespace Tests\Feature;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

class RuntimeServiceProviderTest extends TestCase
{
    #[Test]
    public function standalone_is_the_safe_default_runtime(): void
    {
        $this->assertSame(NexusRuntime::Standalone->value, config('nexus.runtime'));
        $this->assertSame(NexusRuntime::Standalone, $this->app->make(NexusRuntime::class));
        $this->assertSame(
            NexusRuntime::Standalone,
            $this->app->make(RuntimeCapabilities::class)->runtime(),
        );
    }

    #[Test]
    public function configured_runtime_and_capabilities_are_singletons(): void
    {
        config(['nexus.runtime' => NexusRuntime::HostedTenant->value]);
        $this->forgetRuntimeSingletons();

        $runtime = $this->app->make(NexusRuntime::class);
        $capabilities = $this->app->make(RuntimeCapabilities::class);

        $this->assertSame(NexusRuntime::HostedTenant, $runtime);
        $this->assertSame($runtime, $this->app->make(NexusRuntime::class));
        $this->assertSame($runtime, $capabilities->runtime());
        $this->assertSame($capabilities, $this->app->make(RuntimeCapabilities::class));
    }

    #[Test]
    public function tenant_events_require_managed_hosted_runtime_and_an_explicit_flag(): void
    {
        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_events.enabled' => true,
        ]);
        $this->forgetRuntimeSingletons();
        $this->assertTrue($this->app->make(RuntimeCapabilities::class)->consumesTenantEvents());

        config(['nexus.managed' => false]);
        $this->forgetRuntimeSingletons();
        $this->assertFalse($this->app->make(RuntimeCapabilities::class)->consumesTenantEvents());

        config([
            'nexus.runtime' => NexusRuntime::Standalone->value,
            'nexus.managed' => true,
        ]);
        $this->forgetRuntimeSingletons();
        $this->assertFalse($this->app->make(RuntimeCapabilities::class)->consumesTenantEvents());
    }

    #[Test]
    public function unsupported_runtime_configuration_is_rejected(): void
    {
        config(['nexus.runtime' => 'unsupported']);
        $this->forgetRuntimeSingletons();

        $this->expectException(ValueError::class);

        $this->app->make(NexusRuntime::class);
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
