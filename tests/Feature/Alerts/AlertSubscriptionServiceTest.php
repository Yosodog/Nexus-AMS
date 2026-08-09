<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\TradePrice;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionEvaluator;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\AllianceMembershipService;
use App\Services\Discord\PrivateNotificationService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlertSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_offshore_members_can_create_alerts_but_applicants_cannot(): void
    {
        $primaryAlliance = Alliance::factory()->create();
        $offshoreAlliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $primaryAlliance->id]);
        Offshore::query()->create([
            'name' => 'Test offshore',
            'alliance_id' => $offshoreAlliance->id,
            'enabled' => true,
        ]);
        app(AllianceMembershipService::class)->refresh();

        $member = $this->eligibleUser($offshoreAlliance->id);
        $subscription = app(AlertSubscriptionService::class)->createForUser($member, [
            'type' => 'nation',
            'target_id' => $member->nation_id,
            'events' => ['beige_exited'],
            'cooldown_minutes' => 60,
        ]);

        $this->assertSame($member->id, $subscription->user_id);
        $this->assertSame('nation', $subscription->target_type);
        $this->assertSame($member->nation_id, $subscription->target_id);
        $this->assertSame([], $subscription->filter_config);
        $this->assertFalse($subscription->discord_enabled);
        $this->assertNotNull($subscription->active_fingerprint);
        $this->assertSame(
            ['nation.beige.exited'],
            $subscription->events->pluck('event_key')->all(),
        );
        $this->assertDatabaseHas('alert_subscriptions', [
            'user_id' => $member->id,
            'type' => 'nation',
            'is_active' => true,
        ]);

        $applicant = $this->eligibleUser($offshoreAlliance->id, 'APPLICANT');
        $this->expectException(AuthorizationException::class);
        app(AlertSubscriptionService::class)->createForUser($applicant, [
            'type' => 'market',
            'resource' => 'steel',
            'direction' => 'below',
            'threshold' => 3000,
        ]);
    }

    public function test_active_fingerprints_prevent_duplicates_and_are_released_when_paused(): void
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $user = $this->eligibleUser($alliance->id);
        $service = app(AlertSubscriptionService::class);
        $input = [
            'type' => 'market',
            'resource' => 'steel',
            'direction' => 'above',
            'threshold' => 4000,
            'delivery_mode' => 'weekly',
            'discord_enabled' => true,
            'rearm_percent' => 2.5,
            'timezone' => 'America/Chicago',
        ];
        $original = $service->createForUser($user, $input);

        $this->assertSame(['market.price.crossed'], $original->events->pluck('event_key')->all());
        $this->assertSame('weekly', $original->delivery_mode->value);
        $this->assertTrue($original->discord_enabled);
        $this->assertSame('2.50', $original->rearm_percent);
        $this->assertSame('America/Chicago', $original->timezone);

        try {
            $service->createForUser($user, $input);
            $this->fail('A duplicate active subscription was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('alert_subscriptions', 1);
        }

        $paused = $service->setActive($user, $original, false);
        $this->assertFalse($paused->is_active);
        $this->assertSame('paused', $paused->status->value);
        $this->assertNull($paused->active_fingerprint);

        $replacement = $service->createForUser($user, $input);
        $this->assertNotSame($original->id, $replacement->id);

        $this->expectException(ValidationException::class);
        $service->setActive($user, $paused, true);
    }

    public function test_stable_catalog_event_keys_are_accepted_while_legacy_config_stays_compatible(): void
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $user = $this->eligibleUser($alliance->id);

        $subscription = app(AlertSubscriptionService::class)->createForUser($user, [
            'type' => 'nation',
            'target_id' => $user->nation_id,
            'events' => ['nation.city_count.changed'],
        ]);

        $this->assertSame(['city_count_changed'], $subscription->config['events']);
        $this->assertSame(
            ['nation.city_count.changed'],
            $subscription->events->pluck('event_key')->all(),
        );
    }

    public function test_market_threshold_is_edge_triggered_and_uses_private_notification_preferences(): void
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $user = $this->eligibleUser($alliance->id);
        SettingService::setDiscordPrivateNotificationsEnabled(true);
        app(PrivateNotificationService::class)->updatePreferences($user, ['watchlists' => true]);

        $subscription = AlertSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'market',
            'name' => 'Steel spike',
            'config' => ['resource' => 'steel', 'direction' => 'above', 'threshold' => 4000],
            'is_active' => true,
            'cooldown_minutes' => 60,
        ]);
        $this->tradePrice(3500, now()->subMinute());
        $evaluator = app(AlertSubscriptionEvaluator::class);

        $this->assertFalse($evaluator->evaluate($subscription));
        $this->assertFalse($subscription->refresh()->last_condition);
        $this->assertDatabaseCount('discord_queue', 0);

        $this->tradePrice(4500, now());
        $this->assertTrue($evaluator->evaluate($subscription->refresh()));
        $this->assertDatabaseCount('discord_queue', 1);
        $queued = DiscordQueue::query()->firstOrFail();
        $this->assertSame('watchlist_triggered', $queued->payload['event_type']);
        $this->assertSame('/user/alerts', $queued->payload['deep_link_path']);

        $this->assertFalse($evaluator->evaluate($subscription->refresh()));
        $this->assertDatabaseCount('discord_queue', 1);
    }

    private function eligibleUser(int $allianceId, string $position = 'MEMBER'): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
        ]);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => (string) fake()->unique()->numerify('##################'),
        ]);

        return $user;
    }

    private function tradePrice(int $steel, \DateTimeInterface $createdAt): TradePrice
    {
        return TradePrice::query()->create([
            'date' => $createdAt,
            'coal' => 1000,
            'oil' => 1000,
            'uranium' => 1000,
            'iron' => 1000,
            'bauxite' => 1000,
            'lead' => 1000,
            'gasoline' => 1000,
            'munitions' => 1000,
            'steel' => $steel,
            'aluminum' => 1000,
            'food' => 1000,
            'credits' => 1000,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
