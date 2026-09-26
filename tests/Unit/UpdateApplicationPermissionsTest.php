<?php

namespace Tests\Unit;

use App\Console\Commands\UpdateApplication;
use Illuminate\Console\Command;
use RuntimeException;
use Tests\TestCase;

class UpdateApplicationPermissionsTest extends TestCase
{
    public function test_update_command_refuses_to_run_without_bypass(): void
    {
        $this->artisan('app:update')
            ->expectsOutput('app:update has been retired because in-place source updates are unsafe.')
            ->expectsOutput('Use `nexus update` or the Software updates page in the Nexus admin area.')
            ->expectsOutput('To run the legacy in-place update anyway, pass --bypass.')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_update_command_accepts_bypass_option(): void
    {
        $definition = $this->app->make(UpdateApplication::class)->getDefinition();

        $this->assertTrue($definition->hasOption('bypass'));
        $this->assertFalse($definition->getOption('bypass')->acceptValue());
    }

    public function test_update_command_refreshes_all_runtime_caches(): void
    {
        $command = new class extends UpdateApplication
        {
            /** @return list<array{0: string, 1: string}> */
            public function cacheSteps(): array
            {
                return $this->cacheRefreshSteps();
            }
        };

        $this->assertSame([
            ['config:clear', 'Clearing config cache'],
            ['cache:clear', 'Clearing application cache'],
            ['route:clear', 'Clearing route cache'],
            ['event:clear', 'Clearing event cache'],
            ['view:clear', 'Clearing compiled views'],
            ['route:cache', 'Rebuilding route cache'],
            ['event:cache', 'Rebuilding event cache'],
            ['view:cache', 'Rebuilding view cache'],
        ], $command->cacheSteps());
    }

    public function test_update_command_uses_configured_private_permissions(): void
    {
        config()->set('deployment.owner', 'deploy-user');
        config()->set('deployment.group', 'web-group');
        config()->set('deployment.directory_mode', '0750');
        config()->set('deployment.file_mode', '0640');

        $command = new class extends UpdateApplication
        {
            /** @return array<int, array{0: string, 1: string}> */
            public function commands(string $path, string $label): array
            {
                return $this->permissionCommands($path, $label);
            }
        };

        $this->assertSame([
            ["chown -R -- 'deploy-user:web-group' '/tmp/Nexus storage'", 'Setting ownership for storage'],
            ["find '/tmp/Nexus storage' -type d -exec chmod 0750 {} +", 'Setting directory permissions for storage'],
            ["find '/tmp/Nexus storage' -type f -exec chmod 0640 {} +", 'Setting file permissions for storage'],
        ], $command->commands('/tmp/Nexus storage', 'storage'));
    }

    public function test_update_command_rejects_invalid_permission_modes(): void
    {
        config()->set('deployment.owner', 'deploy-user');
        config()->set('deployment.group', 'web-group');
        config()->set('deployment.directory_mode', '0777; touch /tmp/pwned');
        config()->set('deployment.file_mode', '0640');

        $command = new class extends UpdateApplication
        {
            /** @return array<int, array{0: string, 1: string}> */
            public function commands(string $path): array
            {
                return $this->permissionCommands($path, 'storage');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid directory permission mode');

        $command->commands('/tmp/storage');
    }
}
