<?php

namespace Tests\Unit\Services;

use App\Enums\SystemComponent;
use App\Exceptions\UpdaterException;
use App\Services\Updater\UpdaterClient;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdaterClientTest extends TestCase
{
    public function test_component_client_rejects_core_as_an_optional_installation(): void
    {
        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('installed and updated as part of Nexus');

        app(UpdaterClient::class)->componentOperation(
            operation: 'InstallComponent',
            operationId: (string) Str::uuid(),
            component: SystemComponent::Core,
        );
    }

    public function test_component_client_rejects_a_component_update_operation(): void
    {
        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('Unsupported component operation');

        app(UpdaterClient::class)->componentOperation(
            operation: 'UpdateComponent',
            operationId: (string) Str::uuid(),
            component: SystemComponent::Subs,
        );
    }

    public function test_status_returns_sanitized_unavailable_state_when_socket_is_missing(): void
    {
        config()->set('nexus.updater.socket', '/tmp/nexus-updater-test-socket-does-not-exist');

        $status = app(UpdaterClient::class)->status();

        $this->assertSame(['available' => false, 'error_code' => 'updater_unavailable'], $status);
        $this->assertArrayNotHasKey('message', $status);
    }
}
