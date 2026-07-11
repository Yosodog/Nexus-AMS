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
