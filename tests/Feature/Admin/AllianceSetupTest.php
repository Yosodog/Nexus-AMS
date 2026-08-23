<?php

namespace Tests\Feature\Admin;

use App\Enums\AllianceSetupStatus;
use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Enums\NexusRuntime;
use App\Models\Alliance;
use App\Models\AuditLog;
use App\Models\DiscordConnection;
use App\Models\Nation;
use App\Models\Setting;
use App\Models\User;
use App\Services\AllianceSetup\AllianceSetupStateStore;
use App\Services\PWHealthService;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use Database\Seeders\AllianceSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AllianceSetupTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'services.pw.alliance_id' => 777,
            'services.pw.api_key' => 'api-secret-value',
            'services.pw.mutation_key' => 'mutation-secret-value',
        ]);
    }

    #[Test]
    public function fresh_seed_initializes_setup_while_a_missing_key_grandfathers_legacy_installations(): void
    {
        $store = app(AllianceSetupStateStore::class);

        $this->assertTrue($store->read()->legacy);

        $this->seed(AllianceSetupSeeder::class);
        $state = $store->read();

        $this->assertTrue($state->stored);
        $this->assertSame(AllianceSetupStatus::NotStarted, $state->status);
        $this->assertFalse($store->initializeFresh());
    }

    #[Test]
    public function locked_state_changes_preserve_the_first_intro_actor_while_a_later_actor_starts_setup(): void
    {
        $store = app(AllianceSetupStateStore::class);
        $store->initializeFresh();
        $store->acknowledgeIntro(10, false);
        $state = $store->acknowledgeIntro(20, true);

        $this->assertSame(10, $state->introAcknowledgedBy);
        $this->assertSame(20, $state->startedBy);
        $this->assertSame(AllianceSetupStatus::InProgress, $state->status);
    }

    #[Test]
    public function eligible_admin_can_acknowledge_the_intro_without_completing_setup(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)
            ->post(route('admin.setup.intro'), ['intent' => 'later'])
            ->assertRedirect(route('admin.dashboard'));

        $state = app(AllianceSetupStateStore::class)->read();
        $this->assertTrue($state->introAcknowledged());
        $this->assertSame(AllianceSetupStatus::NotStarted, $state->status);
        $this->assertTrue($state->isIncomplete());
        $this->assertDatabaseHas('audit_logs', ['category' => 'alliance_setup', 'action' => 'setup_deferred']);
    }

    #[Test]
    public function dashboard_prompt_is_one_time_but_incomplete_banner_persists(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Set up your alliance workspace')
            ->assertSee('Setup incomplete');

        $this->actingAs($admin)->post(route('admin.setup.intro'), ['intent' => 'later']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Set up your alliance workspace')
            ->assertSee('Setup incomplete');
    }

    #[Test]
    public function legacy_admin_can_voluntarily_start_and_resume_setup(): void
    {
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.setup.index'))
            ->assertOk()
            ->assertSee('grandfathered');

        $this->actingAs($admin)->post(route('admin.setup.start'))->assertRedirect(route('admin.setup.platform'));
        $this->actingAs($admin)->post(route('admin.setup.advance', 'platform'))->assertRedirect(route('admin.setup.security'));

        $this->assertSame('security', app(AllianceSetupStateStore::class)->read()->currentStep->value);
    }

    #[Test]
    public function corrupt_state_remains_untouched_until_an_audited_reset(): void
    {
        Setting::query()->create(['key' => AllianceSetupStateStore::SETTING_KEY, 'value' => '{broken']);
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.setup.index'))
            ->assertOk()
            ->assertSee('Setup metadata needs recovery');
        $this->assertSame('{broken', Setting::query()->where('key', AllianceSetupStateStore::SETTING_KEY)->value('value'));

        $this->actingAs($admin)->post(route('admin.setup.reset'))->assertRedirect(route('admin.setup.platform'));

        $this->assertFalse(app(AllianceSetupStateStore::class)->read()->corrupt);
        $this->assertDatabaseHas('audit_logs', ['action' => 'setup_metadata_reset']);
    }

    #[Test]
    public function setup_requires_diagnostic_permission(): void
    {
        $admin = $this->createVerifiedAdmin();

        $this->actingAs($admin)->get(route('admin.setup.index'))->assertForbidden();
    }

    #[Test]
    public function setup_is_unavailable_to_world_writer(): void
    {
        $this->app->instance(RuntimeCapabilities::class, new RuntimeCapabilities(NexusRuntime::WorldWriter));
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.setup.index'))->assertNotFound();
    }

    #[Test]
    public function platform_page_reports_presence_without_exposing_credentials(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();

        $response = $this->actingAs($admin)->get(route('admin.setup.platform'));

        $response->assertOk()
            ->assertSee('Politics &amp; War API credential', false)
            ->assertSee('Configured')
            ->assertDontSee('api-secret-value')
            ->assertDontSee('mutation-secret-value');
    }

    #[Test]
    public function hosted_view_explains_cloud_ownership_without_rendering_environment_key_names(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        config()->set([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);
        $this->app->instance(NexusRuntime::class, NexusRuntime::HostedTenant);
        $capabilities = new RuntimeCapabilities(NexusRuntime::HostedTenant);
        $this->app->instance(RuntimeCapabilities::class, $capabilities);
        $this->app->instance(RuntimeBuildMetadata::class, new RuntimeBuildMetadata(NexusRuntime::HostedTenant, $capabilities));
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.setup.platform'))
            ->assertOk()
            ->assertSee('Nexus Cloud managed')
            ->assertSee('Deployment credentials are managed by Nexus Cloud')
            ->assertDontSee('PW_API_KEY');
    }

    #[Test]
    public function missing_credentials_and_alliance_data_are_required_blockers(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        config()->set(['services.pw.api_key' => null, 'services.pw.mutation_key' => null]);
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->post(route('admin.setup.complete'))->assertSessionHasErrors('setup');
        $this->actingAs($admin)->get(route('admin.setup.review'))
            ->assertOk()
            ->assertSee('Politics &amp; War API credential', false)
            ->assertSee('Primary alliance data');
    }

    #[Test]
    public function stale_data_and_optional_configuration_do_not_block_completion(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();
        $alliance = Alliance::factory()->create(['id' => 777, 'updated_at' => now()->subDays(3)]);
        Nation::factory()->create(['alliance_id' => $alliance->id, 'updated_at' => now()->subDays(3)]);

        $this->actingAs($admin)->get(route('admin.setup.review'))
            ->assertOk()
            ->assertSee('Alliance data may be stale')
            ->assertSee('Discord is not connected');
        $this->actingAs($admin)->post(route('admin.setup.complete'))->assertRedirect(route('admin.setup.index'));
    }

    #[Test]
    public function explicit_pw_outage_blocks_completion_but_unknown_health_is_advisory(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();
        $this->createReadyAllianceData();
        Cache::put(PWHealthService::CACHE_KEY_STATUS, false, 600);

        $this->actingAs($admin)->post(route('admin.setup.complete'))
            ->assertSessionHasErrors('setup');
        $this->assertSame(AllianceSetupStatus::NotStarted, app(AllianceSetupStateStore::class)->read()->status);

        Cache::forget(PWHealthService::CACHE_KEY_STATUS);
        $this->actingAs($admin)->post(route('admin.setup.complete'))
            ->assertRedirect(route('admin.setup.index'));

        $this->assertSame(AllianceSetupStatus::Completed, app(AllianceSetupStateStore::class)->read()->status);
        $audit = AuditLog::query()->where('action', 'setup_completed')->firstOrFail();
        $this->assertContains('pw_health_unknown', $audit->context['outstanding_warnings']);
        $this->assertStringNotContainsString('secret', json_encode($audit->context, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function discord_defer_disables_enforcement_and_connected_setup_never_renders_connection_key_material(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();
        config()->set([
            'services.discord.guild_id' => '123456789',
            'services.discord.relay_protocol_version' => 2,
            'services.discord.relay_current_public_key' => 'public-key-material-not-for-page',
        ]);

        $this->actingAs($admin)->get(route('admin.setup.discord'))
            ->assertOk()
            ->assertSee('Configured fallback', false)
            ->assertDontSee('public-key-material-not-for-page');

        $this->actingAs($admin)->post(route('admin.setup.discord.update'), [
            'configure_now' => '0',
            'verification_required' => '1',
            'private_notifications_enabled' => '1',
        ])->assertRedirect(route('admin.setup.recruitment'));

        $this->assertDatabaseHas('settings', ['key' => 'require_discord_verification', 'value' => '0']);
        $this->assertDatabaseHas('settings', ['key' => 'discord_private_notifications_enabled', 'value' => '0']);
    }

    #[Test]
    public function discord_readiness_distinguishes_active_missing_and_invalid_connections(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        $admin = $this->diagnosticAdmin();

        $this->actingAs($admin)->get(route('admin.setup.discord'))
            ->assertOk()
            ->assertSee('Discord not connected');

        config()->set([
            'services.discord.guild_id' => '123456789',
            'services.discord.relay_protocol_version' => 1,
        ]);
        $this->actingAs($admin)->post(route('admin.setup.discord.update'), ['configure_now' => '1'])
            ->assertSessionHasErrors('configure_now');

        config()->set('services.discord.guild_id', null);
        DiscordConnection::query()->create([
            'id' => '11111111-1111-4111-8111-111111111111',
            'mode' => DiscordConnectionMode::OfficialShared,
            'state' => DiscordConnectionState::Active,
            'application_id' => '223456789012345678',
            'guild_id' => '123456789012345678',
            'generation' => 1,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => 'public-key-material',
            'capability_version' => 1,
            'capabilities' => [],
            'v1_reader_enabled' => false,
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.setup.discord'))
            ->assertOk()
            ->assertSee('Discord connected')
            ->assertSee('Accepted connection')
            ->assertDontSee('public-key-material');
    }

    #[Test]
    public function recruitment_validation_preserves_advanced_mappings(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
        Setting::query()->create(['key' => 'applications_discord_member_role_id', 'value' => 'role-unchanged']);
        $admin = $this->diagnosticAdmin(['manage-applications']);

        $this->actingAs($admin)->post(route('admin.setup.recruitment.update'), [
            'applications_enabled' => '1',
            'approved_position_id' => '0',
            'approval_message' => '',
        ])->assertSessionHasErrors(['approved_position_id', 'approval_message']);

        $this->actingAs($admin)->post(route('admin.setup.recruitment.update'), [
            'applications_enabled' => '1',
            'approved_position_id' => '3',
            'approval_message' => 'Welcome aboard.',
        ])->assertRedirect(route('admin.setup.review'));

        $this->assertDatabaseHas('settings', ['key' => 'applications_discord_member_role_id', 'value' => 'role-unchanged']);
        $this->assertDatabaseHas('settings', ['key' => 'applications_approved_position_id', 'value' => '3']);
    }

    /** @param list<string> $extraPermissions */
    private function diagnosticAdmin(array $extraPermissions = []): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(800000, 899999)]);

        return $this->grantPermissions($admin, ['view-diagnostic-info', ...$extraPermissions]);
    }

    private function createReadyAllianceData(): void
    {
        $alliance = Alliance::factory()->create(['id' => 777]);
        Nation::factory()->create(['alliance_id' => $alliance->id]);
    }
}
