<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Http\Controllers\AlertSubscriptionController;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Alerts\AlertUserSettingsService;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\TestCase;

class AlertSubscriptionWebTest extends TestCase
{
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();

        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $this->user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->user->id,
            'discord_id' => '123456789012345678',
            'unlinked_at' => null,
        ]);

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::put('/user/alerts/settings', [AlertSubscriptionController::class, 'updateSettings'])
                ->name('user.alerts.settings.update');
            Route::put('/user/alerts/{alertSubscription}', [AlertSubscriptionController::class, 'update'])
                ->name('user.alerts.update');
            Route::patch('/user/alerts/activity/{alertDelivery}/read', [AlertSubscriptionController::class, 'markActivityRead'])
                ->name('user.alerts.activity.read');
        });
    }

    public function test_alert_center_renders_settings_subscription_state_and_canonical_activity(): void
    {
        app(AlertUserSettingsService::class)->update($this->user, [
            'timezone' => 'America/Chicago',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'default_digest_time' => '09:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => true,
        ]);
        app(AlertSubscriptionService::class)->createForUser($this->user, [
            'type' => 'market',
            'name' => 'Cheap steel',
            'resource' => 'steel',
            'direction' => 'below',
            'threshold' => 3000,
            'discord_enabled' => true,
        ]);
        $occurrence = AlertOccurrence::factory()->create([
            'audience_user_id' => $this->user->id,
            'event_key' => 'market.price.crossed',
            'payload' => [
                'resource' => 'steel',
                'direction' => 'below',
                'threshold' => 3000,
                'price' => 2900,
                'observed_at' => now()->toIso8601String(),
            ],
        ]);
        AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'recipient_user_id' => $this->user->id,
            'destination_kind' => AlertDestinationKind::Web,
            'status' => AlertDeliveryStatus::Delivered,
        ]);

        $this->actingAs($this->user)
            ->get('/user/alerts')
            ->assertOk()
            ->assertSee('Alert center')
            ->assertSee('Cheap steel')
            ->assertSee('America/Chicago')
            ->assertSee('Market price crossed')
            ->assertSee('Discord delivery is enabled');
    }

    public function test_member_can_preview_and_test_a_draft_without_saving_a_subscription(): void
    {
        app(AlertUserSettingsService::class)->update($this->user, [
            'timezone' => 'UTC',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'default_digest_time' => '09:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => true,
        ]);
        $payload = [
            'type' => 'nation',
            'name' => 'City watch',
            'target_id' => $this->user->nation_id,
            'events' => ['nation.city_count.changed'],
            'cooldown_minutes' => 60,
            'delivery_mode' => 'immediate',
            'discord_enabled' => '1',
        ];

        $this->actingAs($this->user)
            ->post('/user/alerts', [...$payload, 'submit_action' => 'preview'])
            ->assertRedirect('/user/alerts')
            ->assertSessionHas('alert-preview', fn (array $preview): bool => $preview['name'] === 'City watch');
        $this->assertDatabaseCount('alert_subscriptions', 0);
        $this->assertDatabaseCount('alert_occurrences', 0);

        $this->actingAs($this->user)
            ->post('/user/alerts', [...$payload, 'submit_action' => 'test'])
            ->assertRedirect('/user/alerts')
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseCount('alert_subscriptions', 0);
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertDatabaseHas('alert_occurrences', ['is_test' => true]);
    }

    public function test_member_can_update_delivery_preferences_and_quiet_hours_without_resetting_a_baseline(): void
    {
        $subscription = app(AlertSubscriptionService::class)->createForUser($this->user, [
            'type' => 'nation',
            'name' => 'City watch',
            'target_id' => $this->user->nation_id,
            'events' => ['nation.city_count.changed'],
            'discord_enabled' => true,
        ]);
        $subscription->forceFill([
            'last_observed_state' => ['cities' => 10],
            'last_condition' => true,
            'last_evaluated_at' => now(),
        ])->save();

        $this->actingAs($this->user)
            ->put('/user/alerts/'.$subscription->id, [
                'type' => 'nation',
                'name' => 'Renamed city watch',
                'target_id' => $this->user->nation_id,
                'events' => ['nation.city_count.changed'],
                'cooldown_minutes' => 90,
                'delivery_mode' => 'weekly',
                'discord_enabled' => '1',
            ])
            ->assertRedirect('/user/alerts')
            ->assertSessionHas('alert-message', 'Alert updated.');

        $this->assertSame(['cities' => 10], $subscription->refresh()->last_observed_state);
        $this->assertSame('weekly', $subscription->delivery_mode->value);

        $this->actingAs($this->user)
            ->put('/user/alerts/settings', [
                'timezone' => 'America/New_York',
                'quiet_hours_start' => '23:00',
                'quiet_hours_end' => '07:00',
                'default_digest_time' => '08:30',
                'default_digest_weekday' => 2,
                'discord_enabled' => '1',
            ])
            ->assertRedirect('/user/alerts')
            ->assertSessionHas('alert-message', 'Alert delivery preferences updated.');

        $this->assertDatabaseHas('alert_user_settings', [
            'user_id' => $this->user->id,
            'timezone' => 'America/New_York',
            'discord_enabled' => true,
        ]);
    }

    public function test_member_cannot_update_another_members_subscription_or_activity(): void
    {
        $otherUser = User::factory()->create();
        $otherSubscription = AlertSubscription::factory()->create(['user_id' => $otherUser->id]);
        $otherDelivery = AlertDelivery::factory()->create([
            'recipient_user_id' => $otherUser->id,
            'destination_kind' => AlertDestinationKind::Web,
        ]);

        $this->actingAs($this->user)
            ->put('/user/alerts/'.$otherSubscription->id, [
                'type' => 'market',
                'resource' => 'steel',
                'direction' => 'above',
                'threshold' => 4000,
            ])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->patch('/user/alerts/activity/'.$otherDelivery->id.'/read', ['read' => true])
            ->assertForbidden();
    }
}
