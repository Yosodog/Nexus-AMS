<?php

namespace App\Services;

use App\Enums\NexusRuntime;

final readonly class RuntimeCapabilities
{
    public function __construct(private NexusRuntime $runtime) {}

    public function runtime(): NexusRuntime
    {
        return $this->runtime;
    }

    public function writesPublicWorld(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone, NexusRuntime::WorldWriter => true,
            NexusRuntime::HostedTenant => false,
        };
    }

    public function writesTenantPrivate(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone, NexusRuntime::HostedTenant => true,
            NexusRuntime::WorldWriter => false,
        };
    }

    public function runsPublicWorldSchedules(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone, NexusRuntime::WorldWriter => true,
            NexusRuntime::HostedTenant => false,
        };
    }

    public function runsTenantSchedules(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone, NexusRuntime::HostedTenant => true,
            NexusRuntime::WorldWriter => false,
        };
    }

    /**
     * Whether this runtime owns the Nexus application backup schedule.
     */
    public function runsPlatformBackups(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone => true,
            NexusRuntime::HostedTenant, NexusRuntime::WorldWriter => false,
        };
    }

    public function consumesTenantEvents(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::HostedTenant => true,
            NexusRuntime::Standalone, NexusRuntime::WorldWriter => false,
        };
    }

    public function sendsTenantCallbacks(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::HostedTenant => true,
            NexusRuntime::Standalone, NexusRuntime::WorldWriter => false,
        };
    }

    public function allowsInteractiveSetup(): bool
    {
        return match ($this->runtime) {
            NexusRuntime::Standalone => true,
            NexusRuntime::HostedTenant, NexusRuntime::WorldWriter => false,
        };
    }
}
