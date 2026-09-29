<?php

namespace Tests\Feature\Alerts;

use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Enums\NexusRuntime;
use App\Jobs\EvaluateWarMapAlertsJob;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use App\Models\WarMapAlertState;
use App\Services\AllianceMembershipService;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\DiscordQueueLeaseService;
use App\Services\Discord\PrivateNotificationService;
use App\Services\RuntimeCapabilities;
use App\Services\SettingService;
use App\Services\War\WarMapAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\TestCase;

class WarMapAlertTest extends TestCase
{
    use ConfiguresDiscordQueueV2;
    use RefreshDatabase;

    private User $user;

    private Nation $nation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configureDiscordQueueV2();
        SettingService::setDiscordPrivateNotificationsEnabled(true);
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();
        $this->nation = Nation::factory()->create(['alliance_id' => $alliance->id]);
        $this->user = $this->member($this->nation);
    }

    public function test_initial_max_sends_once_and_elapsed_time_does_not_repeat_it(): void
    {
        $war = $this->war();
        $this->assertTrue($this->evaluate($war));
        $this->assertFalse($this->evaluate($war));
        $this->travel(25)->hours();
        $war->touch();
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 1);
        $state = WarMapAlertState::query()->sole();
        $this->assertFalse($state->armed);
        $this->assertSame(1, $state->notification_sequence);
        $payload = DiscordQueue::query()->sole()->payload;
        $this->assertSame('war_map_maxed', $payload['event_type']);
        $this->assertSame('/defense/war-stats', $payload['deep_link_path']);
        $this->assertSame((int) $war->id, $payload['subject']['id']);
        $this->assertSame(12, $payload['summary']['current_maps']);
        $this->assertSame(12, $payload['summary']['maximum_maps']);
    }

    public function test_only_an_observed_drop_and_refill_rearms_the_war(): void
    {
        $war = $this->war();
        $this->assertTrue($this->evaluate($war));
        $war->update(['att_points' => 9]);
        $this->assertFalse($this->evaluate($war));
        $this->assertFalse($this->evaluate($war));
        $this->assertTrue(WarMapAlertState::query()->sole()->armed);
        $war->update(['att_points' => 12]);
        $this->assertTrue($this->evaluate($war));
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 2);
        $this->assertSame(2, WarMapAlertState::query()->sole()->notification_sequence);
        $this->assertCount(2, DiscordQueue::query()->pluck('dedupe_key')->unique());
    }

    public function test_old_snapshots_cannot_rearm_a_newer_notification(): void
    {
        $war = $this->war(['att_points' => 9]);
        $oldSnapshot = $war->fresh();
        $this->travel(1)->minutes();
        $war->update(['att_points' => 12]);
        $this->assertTrue($this->evaluate($war));
        $this->assertFalse(app(WarMapAlertService::class)->evaluate($this->user->fresh(), $oldSnapshot));
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_wars_and_both_member_sides_are_independent(): void
    {
        $opponent = Nation::factory()->create(['alliance_id' => $this->nation->alliance_id]);
        $defender = $this->member($opponent);
        $war = $this->war(['def_id' => $opponent->id, 'def_points' => 9]);
        $otherWar = $this->war();
        $this->assertTrue($this->evaluate($war));
        $this->assertTrue($this->evaluate($otherWar));
        $this->assertFalse(app(WarMapAlertService::class)->evaluate($defender, $war));
        $war->update(['def_points' => 12]);
        $this->assertTrue(app(WarMapAlertService::class)->evaluate($defender, $war->fresh()));
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 3);
        $this->assertDatabaseCount('war_map_alert_states', 3);
    }

    public function test_preferences_default_on_and_preserve_explicit_opt_outs(): void
    {
        $notifications = app(PrivateNotificationService::class);
        $this->assertTrue($notifications->canSend($this->user, WarMapAlertService::CATEGORY));
        $this->assertTrue($notifications->preferencesFor($this->user)[WarMapAlertService::CATEGORY]);
        $this->assertFalse($notifications->canSend($this->user, 'loans'));
        $this->user->load('discordNotificationPreferences');
        $notifications->updatePreferences($this->user, []);
        $this->assertFalse($notifications->canSend($this->user, WarMapAlertService::CATEGORY));
        $this->assertFalse($notifications->preferencesFor($this->user)[WarMapAlertService::CATEGORY]);
        $this->assertFalse($this->evaluate($this->war()));
        $this->assertDatabaseCount('discord_queue', 0);
    }

    #[DataProvider('notificationSwitches')]
    public function test_disabled_notifications_still_observe_refills_without_resetting_history(string $switch): void
    {
        $war = $this->war();
        $this->assertTrue($this->evaluate($war));
        $this->setNotifications($switch, false);
        $this->assertFalse($this->evaluate($war));
        $this->setNotifications($switch, true);
        $this->assertFalse($this->evaluate($war));
        $this->setNotifications($switch, false);
        $war->update(['att_points' => 9]);
        $this->assertFalse($this->evaluate($war));
        $war->update(['att_points' => 12]);
        $this->assertFalse($this->evaluate($war));
        $this->setNotifications($switch, true);
        $this->assertTrue($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 2);
    }

    public static function notificationSwitches(): array
    {
        return [['preference'], ['master']];
    }

    #[DataProvider('invalidWarStates')]
    public function test_invalid_or_inactive_war_observations_do_not_send_or_rearm(string $condition): void
    {
        $war = $this->war();
        $this->assertTrue($this->evaluate($war));
        $war->att_points = 9;
        match ($condition) {
            'ended' => $war->end_date = now(),
            'expired' => $war->turns_left = 0,
            'stale' => $war->updated_at = now()->subMinutes(91),
            'future' => $war->updated_at = now()->addMinutes(1),
            'missing_timestamp' => $war->updated_at = null,
            'missing_maps' => $war->att_points = null,
            'negative_maps' => $war->att_points = -1,
            'excess_maps' => $war->att_points = 13,
            'fractional_maps' => $war->att_points = 9.5,
            'nonnumeric_maps' => $war->att_points = 'unknown',
            'unrelated_nation' => $war->att_id = 999999999,
        };
        $this->assertFalse(app(WarMapAlertService::class)->evaluate($this->user->fresh(), $war));
        $this->assertFalse(WarMapAlertState::query()->sole()->armed);
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public static function invalidWarStates(): array
    {
        return array_map(fn (string $condition): array => [$condition], [
            'ended', 'expired', 'stale', 'future', 'missing_timestamp', 'missing_maps',
            'negative_maps', 'excess_maps', 'fractional_maps', 'nonnumeric_maps', 'unrelated_nation',
        ]);
    }

    public function test_freshness_boundary_and_missing_opponent_name(): void
    {
        $war = $this->war(['def_id' => 999999999, 'updated_at' => now()->subMinutes(90)]);
        $this->assertTrue($this->evaluate($war));
        $this->assertSame('Nation #999999999', DiscordQueue::query()->sole()->payload['summary']['opponent_name']);
    }

    #[DataProvider('ineligibleMembers')]
    public function test_ineligible_members_do_not_receive_alerts(string $condition): void
    {
        $war = $this->war();
        $this->makeIneligible($condition);
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 0);
    }

    public static function ineligibleMembers(): array
    {
        return [['unverified'], ['disabled'], ['applicant'], ['departed'], ['unlinked'], ['nation_changed']];
    }

    public function test_an_enqueue_failure_rolls_back_state_and_queue_and_can_be_retried(): void
    {
        $war = $this->war();
        $notifications = app(PrivateNotificationService::class);
        $failing = Mockery::mock(PrivateNotificationService::class)->makePartial();
        $failing->shouldReceive('enqueueForNation')->once()->andReturnUsing(function (...$arguments) use ($notifications): never {
            $notifications->enqueueForNation(...$arguments);
            throw new RuntimeException('Simulated failure after queue insert.');
        });
        $this->app->instance(PrivateNotificationService::class, $failing);
        try {
            $this->evaluate($war);
            $this->fail('The simulated enqueue failure must be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after queue insert.', $exception->getMessage());
        }
        $this->assertDatabaseCount('discord_queue', 0);
        $this->assertDatabaseCount('war_map_alert_states', 0);
        $this->app->instance(PrivateNotificationService::class, $notifications);
        $this->assertTrue($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_undeliverable_dm_does_not_create_another_alert_for_the_same_refill(): void
    {
        $war = $this->war();
        $this->assertTrue($this->evaluate($war));
        DiscordQueue::query()->sole()->update([
            'status' => DiscordQueueStatus::Complete,
            'result' => ['delivery' => 'undeliverable', 'reason' => 'dm_failed'],
            'completed_at' => now(),
        ]);
        $this->assertFalse($this->evaluate($war));
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_opt_out_suppresses_only_pending_war_map_alerts(): void
    {
        $this->evaluate($this->war());
        $notifications = app(PrivateNotificationService::class);
        $notifications->updatePreferences($this->user, ['loans' => true]);
        $notifications->enqueueForNation($this->nation->fresh(), 'loans', 'loan_approved', 'test-loan', ['type' => 'loan', 'id' => 1], '/loans');
        $this->actingAs($this->user)->post(route('user.settings.discord-notifications'), ['categories' => ['loans']])->assertRedirect();
        $map = DiscordQueue::query()->where('payload->event_type', WarMapAlertService::EVENT_TYPE)->sole();
        $this->assertSame('suppressed', $map->result['delivery']);
        $this->assertSame(DiscordQueueStatus::Pending, DiscordQueue::query()->where('payload->event_type', 'loan_approved')->sole()->status);
        $this->assertFalse(app(PrivateNotificationService::class)->canSend($this->user->fresh(), WarMapAlertService::CATEGORY));
    }

    #[DataProvider('stalePendingAlerts')]
    public function test_stale_pending_alerts_are_suppressed_before_claiming(string $condition): void
    {
        $war = $this->war();
        $this->evaluate($war);
        match ($condition) {
            'ended' => $war->update(['end_date' => now()]),
            'expired' => $war->update(['turns_left' => 0]),
            'spent' => $war->update(['att_points' => 9]),
            'stale' => $war->update(['updated_at' => now()->subMinutes(91)]),
            'opt_out' => $this->setNotifications('preference', false),
            'master_off' => $this->setNotifications('master', false),
            default => $this->makeIneligible($condition),
        };
        $this->assertNull($this->claim());
        $queue = DiscordQueue::query()->sole();
        $this->assertSame(DiscordQueueStatus::Complete, $queue->status);
        $this->assertSame('suppressed', $queue->result['delivery']);
        $this->assertSame(0, $queue->attempts);
    }

    public static function stalePendingAlerts(): array
    {
        return array_map(fn (string $condition): array => [$condition], [
            'ended', 'expired', 'spent', 'stale', 'opt_out', 'master_off', 'disabled',
            'unverified', 'applicant', 'departed', 'unlinked', 'nation_changed',
        ]);
    }

    public function test_claim_suppresses_obsolete_refills_and_claims_the_current_notification(): void
    {
        $war = $this->war();
        $this->evaluate($war);
        $first = DiscordQueue::query()->sole();
        $this->travel(1)->seconds();
        $war->update(['att_points' => 9]);
        $this->evaluate($war);
        $war->update(['att_points' => 12]);
        $this->evaluate($war);
        $claimed = $this->claim();
        $this->assertNotNull($claimed);
        $this->assertNotSame($first->id, $claimed->id);
        $this->assertSame('obsolete_war_map_notification', $first->fresh()->result['reason']);
        $this->assertSame(2, $claimed->payload['summary']['notification_sequence']);
    }

    public function test_an_active_lease_is_preserved_when_maps_drop_or_the_member_opts_out(): void
    {
        $war = $this->war();
        $this->evaluate($war);
        $requestId = (string) Str::uuid();
        $claimed = $this->claim($requestId);
        $this->assertNotNull($claimed);
        $war->update(['att_points' => 9]);
        $this->setNotifications('preference', false);
        $this->assertSame(0, app(PrivateNotificationService::class)->suppressPending($this->user, [WarMapAlertService::CATEGORY]));
        $retry = $this->claim($requestId);
        $this->assertSame($claimed->id, $retry?->id);
        $this->assertSame($claimed->lease_token, $retry?->lease_token);
        $this->assertSame(DiscordQueueStatus::Processing, $claimed->fresh()->status);
    }

    #[DataProvider('runtimeModes')]
    public function test_evaluation_runs_only_in_tenant_runtimes(NexusRuntime $runtime, int $expectedCount): void
    {
        $this->war();
        $this->app->instance(RuntimeCapabilities::class, new RuntimeCapabilities($runtime));
        $this->app->call([new EvaluateWarMapAlertsJob, 'handle']);
        $this->assertDatabaseCount('discord_queue', $expectedCount);
    }

    public static function runtimeModes(): array
    {
        return [[NexusRuntime::Standalone, 1], [NexusRuntime::HostedTenant, 1], [NexusRuntime::WorldWriter, 0]];
    }

    public function test_job_batch_observes_drops_while_preferences_are_disabled(): void
    {
        $first = $this->war();
        $second = $this->war();
        $this->app->call([new EvaluateWarMapAlertsJob, 'handle']);
        $this->assertDatabaseCount('discord_queue', 2);
        $this->setNotifications('preference', false);
        $first->update(['att_points' => 9]);
        $this->app->call([new EvaluateWarMapAlertsJob, 'handle']);
        $first->update(['att_points' => 12]);
        $this->setNotifications('preference', true);
        $this->app->call([new EvaluateWarMapAlertsJob, 'handle']);
        $this->assertDatabaseCount('discord_queue', 3);
        $this->assertSame(1, WarMapAlertState::query()->where('war_id', $second->id)->sole()->notification_sequence);
    }

    private function evaluate(War $war): bool
    {
        return app(WarMapAlertService::class)->evaluate($this->user->fresh(), $war->fresh());
    }

    private function setNotifications(string $switch, bool $enabled): void
    {
        if ($switch === 'master') {
            SettingService::setDiscordPrivateNotificationsEnabled($enabled);
        } else {
            app(PrivateNotificationService::class)->updatePreferences($this->user, [WarMapAlertService::CATEGORY => $enabled]);
        }
    }

    private function makeIneligible(string $condition): void
    {
        match ($condition) {
            'unverified' => $this->user->update(['verified_at' => null]),
            'disabled' => $this->user->forceFill(['disabled' => true])->save(),
            'applicant' => $this->nation->update(['alliance_position' => 'APPLICANT']),
            'departed' => $this->nation->update(['alliance_id' => null]),
            'unlinked' => $this->user->discordAccounts()->update(['unlinked_at' => now()]),
            'nation_changed' => $this->user->update(['nation_id' => Nation::factory()->create()->id]),
        };
    }

    private function claim(?string $requestId = null): ?DiscordQueue
    {
        return app(DiscordQueueLeaseService::class)->claim(
            'war-map-test-worker',
            $requestId ?? (string) Str::uuid(),
            [DiscordQueueLane::Alerts],
            app(DiscordConnectionResolver::class)->resolveForQueueProducer(),
        );
    }

    private function member(Nation $nation): User
    {
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create(['user_id' => $user->id, 'unlinked_at' => null]);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function war(array $attributes = []): War
    {
        return War::query()->create([
            'date' => now(),
            'reason' => 'MAP alert test',
            'war_type' => 'ORDINARY',
            'turns_left' => 20,
            'att_id' => $this->nation->id,
            'def_id' => Nation::factory()->create()->id,
            'att_points' => 12,
            'def_points' => 6,
            ...$attributes,
        ])->refresh();
    }
}
