<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDelivery;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Alerts\AlertUserSettingsService;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\TestCase;

class AlertTestDeliveryServiceTest extends TestCase
{
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_saved_test_is_immediate_and_persists_web_and_discord_outcomes(): void
    {
        Carbon::setTestNow('2026-08-09 04:30:00');
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => '123456789012345678',
        ]);
        app(AlertUserSettingsService::class)->update($user, [
            'timezone' => 'America/Chicago',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'default_digest_time' => '09:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => true,
        ]);
        $subscription = app(AlertSubscriptionService::class)->createForUser($user, [
            'type' => 'nation',
            'target_id' => $nation->id,
            'events' => ['city_count_changed'],
            'delivery_mode' => 'daily',
            'discord_enabled' => true,
        ]);

        $occurrence = app(AlertSubscriptionService::class)->test($user, $subscription);

        $this->assertTrue($occurrence->is_test);
        $this->assertSame('nation.city_count.changed', $occurrence->event_key);
        $this->assertCount(2, $occurrence->deliveries);
        $web = $occurrence->deliveries->firstWhere('destination_kind', AlertDestinationKind::Web);
        $discord = $occurrence->deliveries->firstWhere('destination_kind', AlertDestinationKind::DiscordDm);
        $this->assertSame(AlertDeliveryStatus::Delivered, $web?->status);
        $this->assertSame(AlertDeliveryStatus::Queued, $discord?->status);
        $this->assertSame(AlertDeliveryMode::Immediate, $discord?->delivery_mode);
        $this->assertNull($discord?->scheduled_at);
        $this->assertDatabaseCount('discord_queue', 1);
        $this->assertNull($subscription->refresh()->last_triggered_at);
        $this->assertNull($subscription->last_observed_state);
    }

    public function test_saved_test_persists_discord_suppression_when_the_member_has_not_opted_in(): void
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => '123456789012345679',
        ]);
        $subscription = app(AlertSubscriptionService::class)->createForUser($user, [
            'type' => 'nation',
            'target_id' => $nation->id,
            'events' => ['beige_exited'],
            'discord_enabled' => true,
        ]);

        $occurrence = app(AlertSubscriptionService::class)->test($user, $subscription);
        $discord = AlertDelivery::query()
            ->where('alert_occurrence_id', $occurrence->id)
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->sole();

        $this->assertSame(AlertDeliveryStatus::Suppressed, $discord->status);
        $this->assertSame('user_discord_disabled', $discord->reason_code);
        $this->assertDatabaseCount('discord_queue', 0);
    }
}
