<?php

namespace Tests\Feature\Admin;

use App\Enums\SystemComponent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Updater\UpdaterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class SoftwareUpdatesTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_diagnostic_admin_can_view_software_page_when_updater_is_unavailable(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->get(route('admin.settings.software'))
            ->assertOk()
            ->assertSee('Software')
            ->assertSee('Nexus updater is unavailable')
            ->assertSee('Cleanup old deployments');
    }

    public function test_component_inventory_and_fixed_actions_come_from_the_root_updater(): void
    {
        $this->bindUpdater();
        $admin = $this->createAdmin(['view-diagnostic-info', 'manage-system']);

        $this->actingAs($admin)
            ->get(route('admin.settings.software'))
            ->assertOk()
            ->assertSee('Nexus Subs')
            ->assertSee('Restart')
            ->assertDontSee('Update component');
    }

    public function test_admin_without_manage_system_permission_cannot_start_an_update(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->postJson(route('admin.settings.software.updates.start'), ['confirmation' => 1])
            ->assertForbidden();
    }

    public function test_update_requires_confirmation_and_rejects_caller_selected_targets(): void
    {
        $admin = $this->createAdmin(['manage-system']);

        $this->actingAs($admin)
            ->postJson(route('admin.settings.software.updates.start'), [
                'target_release_id' => 'v9.9.9',
                'url' => 'https://attacker.example/release',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation', 'target_release_id', 'url']);
    }

    public function test_authorized_admin_can_start_update_without_a_step_up_flow(): void
    {
        $this->bindUpdater();
        $admin = $this->createAdmin(['manage-system']);

        $this->actingAs($admin)
            ->postJson(route('admin.settings.software.updates.start'), ['confirmation' => 1])
            ->assertAccepted()
            ->assertJsonPath('id', '550e8400-e29b-41d4-a716-446655440000');

        $audit = AuditLog::query()->latest('occurred_at')->firstOrFail();
        $this->assertSame('system_management', $audit->category);
        $this->assertSame('update', $audit->action);
    }

    public function test_discord_credentials_are_not_flashed_or_written_to_audit_history(): void
    {
        $this->bindUpdater();
        $admin = $this->createAdmin(['manage-system']);
        $token = 'discord-secret-that-must-not-persist';

        $this->actingAs($admin)
            ->from(route('admin.settings.software'))
            ->post(route('admin.settings.software.components.action', [
                'component' => 'nexus-discord',
                'action' => 'install',
            ]), [
                'confirmation' => 1,
                'configuration' => [
                    'bot_token' => $token,
                    'client_id' => '12345678901234567',
                    'guild_id' => '98765432109876543',
                ],
            ])
            ->assertRedirect(route('admin.settings.software'));

        $this->assertArrayNotHasKey('configuration', session('_old_input', []));
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $this->assertStringNotContainsString(
            $token,
            json_encode(AuditLog::query()->latest('occurred_at')->firstOrFail()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_subs_install_rejects_submitted_configuration(): void
    {
        $admin = $this->createAdmin(['manage-system']);

        $this->actingAs($admin)
            ->postJson(route('admin.settings.software.components.action', [
                'component' => 'nexus-subs',
                'action' => 'install',
            ]), [
                'confirmation' => 1,
                'configuration' => ['politics_war_api_token' => 'should-not-be-accepted'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration']);
    }

    public function test_retired_app_update_command_does_not_run_deployment_commands(): void
    {
        $this->artisan('app:update')
            ->assertExitCode(1)
            ->expectsOutputToContain('nexus update');
    }

    /** @param list<string> $permissions */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    private function bindUpdater(): void
    {
        $this->app->instance(UpdaterClient::class, new class extends UpdaterClient
        {
            public function status(): array
            {
                return [
                    'available' => true,
                    'installed' => true,
                    'installed_release_id' => 'v1.0.0',
                    'previous_release_id' => 'v0.9.0',
                    'updater_version' => 'v1.0.0',
                    'channel' => 'stable',
                    'profile' => 'full',
                    'components' => [[
                        'component_id' => 'nexus-subs',
                        'label' => 'Nexus Subs',
                        'required' => false,
                        'installed' => true,
                        'enabled' => true,
                        'release_id' => 'v1.0.0',
                        'health_state' => 'healthy',
                        'configuration_state' => 'ready',
                        'available_actions' => ['restart', 'disable'],
                    ]],
                    'operations' => [],
                    'cleanup' => ['reclaimable_bytes' => 0, 'entries' => []],
                ];
            }

            public function checkUpdates(): array
            {
                return [
                    'installed_release' => 'v1.0.0',
                    'latest_release' => 'v1.0.1',
                    'update_available' => true,
                    'releases' => [['release_id' => 'v1.0.1']],
                    'release_notes' => 'A safe release.',
                ];
            }

            public function update(string $operationId): array
            {
                return ['operation_id' => '550e8400-e29b-41d4-a716-446655440000', 'status' => 'accepted'];
            }

            public function componentOperation(
                string $operation,
                string $operationId,
                SystemComponent $component,
                array $configuration = [],
            ): array {
                return ['operation_id' => $operationId, 'status' => 'accepted'];
            }
        });
    }
}
