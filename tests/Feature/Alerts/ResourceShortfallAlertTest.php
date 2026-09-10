<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDelivery;
use App\Models\AlertUserSetting;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\DiscordConnection;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Models\NationResources;
use App\Models\User;
use App\Services\Alerts\AlertScheduledDeliveryDispatcher;
use App\Services\Alerts\ResourceShortfallAlertService;
use App\Services\AllianceMembershipService;
use App\Services\Economy\EconomyRules;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\TestCase;

class ResourceShortfallAlertTest extends TestCase
{
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    private User $user;

    private Nation $nation;

    private AlertUserSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 18:00:00');
        $this->configureDiscordQueueV2();
        $capabilities = [
            'capabilities' => [
                'relay.proof.v2',
                'queue.connection-context.v1',
                ResourceShortfallAlertService::DISCORD_CAPABILITY,
            ],
            'supported_queue_actions' => ['ALERT_DELIVERY_V1'],
        ];
        config(['services.discord.capabilities' => $capabilities]);
        DiscordConnection::query()->update(['capabilities' => $capabilities]);
        SettingService::setDiscordPrivateNotificationsEnabled(true);

        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $this->nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $this->user = User::factory()->verified()->create(['nation_id' => $this->nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->user->id,
            'discord_id' => '234567890123456789',
            'unlinked_at' => null,
        ]);
        $this->settings = AlertUserSetting::factory()->for($this->user)->create([
            'discord_enabled' => true,
            'resource_shortfall_alerts_enabled' => true,
        ]);
        $this->writeCurrentInputs(0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function alerts_require_member_opt_in_and_the_global_discord_setting(): void
    {
        $alerts = app(ResourceShortfallAlertService::class);
        $this->settings->forceFill(['resource_shortfall_alerts_enabled' => false])->save();
        $this->assertNull($alerts->evaluate($this->settings->load('user')));

        $this->settings->forceFill(['resource_shortfall_alerts_enabled' => true])->save();
        SettingService::setDiscordPrivateNotificationsEnabled(false);
        $this->assertNull($alerts->evaluate($this->settings));

        $this->assertDatabaseCount('alert_occurrences', 0);
    }

    #[Test]
    public function opted_in_alerts_are_combined_deduplicated_and_cool_down_from_delivery(): void
    {
        $alerts = app(ResourceShortfallAlertService::class);
        $occurrence = $alerts->evaluate($this->settings->load('user'));

        $this->assertNotNull($occurrence);
        $this->assertSame(['coal'], collect($occurrence->payload['shortfalls'])->pluck('resource')->all());
        $this->assertFalse($occurrence->payload['action_available']);
        $discordDelivery = AlertDelivery::query()
            ->where('alert_occurrence_id', $occurrence->id)
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->sole();
        $this->assertSame(AlertDeliveryStatus::Queued, $discordDelivery->status);
        $this->assertNull($alerts->evaluate($this->settings));

        $discordDelivery->forceFill([
            'status' => AlertDeliveryStatus::Delivered,
            'delivered_at' => now(),
        ])->save();
        Carbon::setTestNow(now()->addHours(47));
        $this->writeCurrentInputs(0);
        $this->assertNull($alerts->evaluate($this->settings));

        Carbon::setTestNow(now()->addHours(2));
        $this->writeCurrentInputs(0);
        $this->assertNotNull($alerts->evaluate($this->settings));
        $this->assertDatabaseCount('alert_occurrences', 2);
    }

    #[Test]
    public function quiet_hour_delivery_is_suppressed_when_the_shortfall_resolves(): void
    {
        Carbon::setTestNow('2026-09-10 23:00:00');
        $this->writeCurrentInputs(0);
        $this->settings->forceFill([
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
        ])->save();

        $occurrence = app(ResourceShortfallAlertService::class)->evaluate($this->settings->load('user'));
        $delivery = AlertDelivery::query()
            ->where('alert_occurrence_id', $occurrence->id)
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->sole();
        $this->assertSame(AlertDeliveryStatus::Scheduled, $delivery->status);
        $this->assertSame('2026-09-11 07:00:00', $delivery->scheduled_at->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-09-11 07:00:00');
        $this->writeCurrentInputs(200);
        app(AlertScheduledDeliveryDispatcher::class)->dispatchDue();

        $this->assertSame(AlertDeliveryStatus::Suppressed, $delivery->fresh()->status);
        $this->assertSame('shortfall_resolved', $delivery->fresh()->reason_code);

        $this->writeCurrentInputs(0);
        $this->assertNotNull(
            app(ResourceShortfallAlertService::class)->evaluate($this->settings),
        );
    }

    private function writeCurrentInputs(float $coalOnHand): void
    {
        $resources = collect(PWHelperService::resources(includeCredits: true))
            ->mapWithKeys(fn (string $resource): array => [$resource => 0])
            ->all();
        NationResources::query()->updateOrCreate(
            ['nation_id' => $this->nation->id],
            [...$resources, 'coal' => $coalOnHand, 'updated_at' => now()],
        );
        NationProfitabilitySnapshot::query()->updateOrCreate(
            ['nation_id' => $this->nation->id],
            [
                'alliance_id' => $this->nation->alliance_id,
                'model_version' => EconomyRules::MODEL_VERSION,
                'leader_name' => $this->nation->leader_name,
                'nation_name' => $this->nation->nation_name,
                'cities' => $this->nation->num_cities,
                'resource_profit_per_day' => [
                    ...array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0),
                    'coal' => -120.0,
                ],
                'calculated_at' => now(),
            ],
        );
    }
}
