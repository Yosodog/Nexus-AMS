<?php

namespace Tests\Unit\Support\Database;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Support\Database\WorldSchema;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Tests\TestCase;

class WorldSchemaTest extends TestCase
{
    public function test_hosted_runtime_skips_a_classified_world_schema_callback(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        $callbackInvoked = false;

        WorldSchema::create('nations', function (Blueprint $table) use (&$callbackInvoked): void {
            $callbackInvoked = true;
        });

        $this->assertFalse($callbackInvoked);
    }

    public function test_world_schema_helper_rejects_an_unclassified_table(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('World schema helper received an unclassified table.');

        WorldSchema::create('tenant_private_records', function (Blueprint $table): void {});
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }
}
