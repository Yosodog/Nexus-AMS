<?php

namespace App\Providers;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
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
        $this->app->singleton(RuntimeCapabilities::class);
    }

    /**
     * Resolve the runtime during boot so invalid configuration fails before work starts.
     */
    public function boot(NexusRuntime $runtime): void {}
}
