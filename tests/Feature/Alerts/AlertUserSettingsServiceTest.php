<?php

namespace Tests\Feature\Alerts;

use App\Models\DiscordNotificationPreference;
use App\Models\User;
use App\Services\Alerts\AlertUserSettingsService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlertUserSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_settings_preserve_the_legacy_watchlist_opt_in_until_explicitly_saved(): void
    {
        $user = User::factory()->create();
        SettingService::setDiscordPrivateNotificationsEnabled(true);
        DiscordNotificationPreference::query()->create([
            'user_id' => $user->id,
            'category' => 'watchlists',
            'enabled' => true,
        ]);

        $settings = app(AlertUserSettingsService::class)->current($user);

        $this->assertFalse($settings->exists);
        $this->assertSame('UTC', $settings->timezone);
        $this->assertTrue($settings->discord_enabled);
        $this->assertDatabaseCount('alert_user_settings', 0);
    }

    public function test_update_persists_timezone_quiet_hours_digest_defaults_and_discord_opt_in(): void
    {
        $user = User::factory()->create();

        $settings = app(AlertUserSettingsService::class)->update($user, [
            'timezone' => 'America/Chicago',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:30',
            'default_digest_time' => '09:15',
            'default_digest_weekday' => 5,
            'discord_enabled' => true,
        ]);

        $this->assertTrue($settings->exists);
        $this->assertSame($user->id, $settings->user_id);
        $this->assertSame('America/Chicago', $settings->timezone);
        $this->assertSame('22:00:00', $settings->quiet_hours_start);
        $this->assertSame('07:30:00', $settings->quiet_hours_end);
        $this->assertSame('09:15:00', $settings->default_digest_time);
        $this->assertSame(5, $settings->default_digest_weekday);
        $this->assertTrue($settings->discord_enabled);
        $this->assertTrue(app(AlertUserSettingsService::class)->isDiscordEnabled($user));
    }

    public function test_update_rejects_invalid_timezones_and_incomplete_or_zero_length_quiet_hours(): void
    {
        $user = User::factory()->create();
        $base = [
            'timezone' => 'UTC',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'default_digest_time' => '09:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => false,
        ];

        foreach ([
            ['timezone' => 'Not/AZone'],
            ['quiet_hours_start' => '22:00', 'quiet_hours_end' => null],
            ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '22:00'],
        ] as $invalid) {
            try {
                app(AlertUserSettingsService::class)->update($user, array_replace($base, $invalid));
                $this->fail('Invalid alert settings were accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('alert_user_settings', 0);
            }
        }
    }
}
