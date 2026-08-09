<?php

namespace App\Providers;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Services\World\WorldModelManifest;
use App\Services\World\WorldWriteGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class NexusRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NexusRuntime::class,
            static fn (): NexusRuntime => NexusRuntime::from(Config::string('nexus.runtime')),
        );
        $this->app->singleton(
            RuntimeCapabilities::class,
            static fn (Application $application): RuntimeCapabilities => new RuntimeCapabilities(
                $application->make(NexusRuntime::class),
                (bool) Config::get('nexus.managed')
                    && (bool) Config::get('nexus.tenant_events.enabled'),
            ),
        );
    }

    public function boot(NexusRuntime $runtime): void
    {
        if ($runtime !== NexusRuntime::HostedTenant) {
            return;
        }

        foreach (WorldModelManifest::models() as $modelClass) {
            $modelClass::observe(WorldWriteGuard::class);
        }
    }
}
