<?php

namespace Tests\Unit\Services;

use App\Services\SystemManagementService;
use App\Services\Updater\UpdaterClient;
use Tests\TestCase;

class SystemManagementServiceTest extends TestCase
{
    public function test_snapshot_uses_the_root_updater_as_the_live_source_of_truth(): void
    {
        $this->app->instance(UpdaterClient::class, new class extends UpdaterClient
        {
            public function status(): array
            {
                return [
                    'available' => true,
                    'installed' => true,
                    'components' => [['component_id' => 'nexus-core', 'installed' => true]],
                    'operations' => [['id' => 'operation-from-root']],
                    'cleanup' => ['reclaimable_bytes' => 42, 'entries' => ['nexus-core/v0.9.0']],
                ];
            }

            public function checkUpdates(): array
            {
                return [
                    'channel' => 'stable',
                    'update_available' => true,
                    'components' => [['component_id' => 'nexus-core', 'from_release' => 'v1.0.0', 'to_release' => 'v1.0.1']],
                ];
            }
        });

        $snapshot = app(SystemManagementService::class)->snapshot();

        $this->assertSame('v1.0.1', $snapshot['updates']['components'][0]['to_release']);
        $this->assertSame('nexus-core', $snapshot['components'][0]['component_id']);
        $this->assertSame('operation-from-root', $snapshot['operations'][0]['id']);
        $this->assertSame(42, $snapshot['cleanup']['reclaimable_bytes']);
    }
}
