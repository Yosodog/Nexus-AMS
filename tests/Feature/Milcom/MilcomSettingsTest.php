<?php

namespace Tests\Feature\Milcom;

use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomSettingsTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v1_enabled', false);
        config()->set('milcom.v2_enabled', true);
    }

    public function test_v2_settings_page_exposes_current_war_alert_configuration(): void
    {
        SettingService::setDiscordWarAlertChannelId('123456789012345678');
        SettingService::setDiscordWarAlertEnabled(true);
        $manager = $this->createMilcomManager();

        $this->actingAs($manager)
            ->get(route('admin.milcom.settings'))
            ->assertOk()
            ->assertSee('War declaration alerts')
            ->assertSee('name="war_alert_channel_id"', false)
            ->assertSee('value="123456789012345678"', false)
            ->assertSee('name="war_alert_enabled"', false);
    }

    public function test_v2_settings_endpoint_persists_war_alert_configuration(): void
    {
        $this->authenticateMilcomManager();

        $this->postJson('/api/v1/milcom/settings', $this->settingsPayload([
            'war_alert_channel_id' => '223456789012345678',
            'war_alert_enabled' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('data.settings.war_alert_channel_id', '223456789012345678')
            ->assertJsonPath('data.settings.war_alert_enabled', true);

        $this->assertSame('223456789012345678', SettingService::getDiscordWarAlertChannelId());
        $this->assertTrue(SettingService::isDiscordWarAlertEnabled());
    }

    public function test_v2_settings_reject_an_invalid_war_alert_channel_id(): void
    {
        SettingService::setDiscordWarAlertChannelId('323456789012345678');
        $this->authenticateMilcomManager();

        $this->postJson('/api/v1/milcom/settings', $this->settingsPayload([
            'war_alert_channel_id' => 'not-a-channel',
            'war_alert_enabled' => true,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['war_alert_channel_id']);

        $this->assertSame('323456789012345678', SettingService::getDiscordWarAlertChannelId());
    }

    public function test_v2_settings_endpoint_can_disable_and_clear_war_alerts(): void
    {
        SettingService::setDiscordWarAlertChannelId('423456789012345678');
        SettingService::setDiscordWarAlertEnabled(true);
        $this->authenticateMilcomManager();

        $this->postJson('/api/v1/milcom/settings', $this->settingsPayload())
            ->assertOk()
            ->assertJsonPath('data.settings.war_alert_channel_id', null)
            ->assertJsonPath('data.settings.war_alert_enabled', false);

        $this->assertSame('', SettingService::getDiscordWarAlertChannelId());
        $this->assertFalse(SettingService::isDiscordWarAlertEnabled());
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'forum_id' => null,
            'defense_role_id' => null,
            'forum_tag_ids' => [],
            'counter_monitoring_enabled' => true,
            'default_war_type' => 'ORDINARY',
            'default_war_reason' => 'Alliance operations',
            'war_alert_channel_id' => null,
            'war_alert_enabled' => false,
        ], $overrides);
    }
}
