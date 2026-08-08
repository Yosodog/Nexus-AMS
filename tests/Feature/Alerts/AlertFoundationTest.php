<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationHealth;
use App\Enums\AlertDestinationKind;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Models\AlertDailyMetric;
use App\Models\AlertDelivery;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDeliveryBatch;
use App\Models\AlertDestination;
use App\Models\AlertOccurrence;
use App\Models\AlertRoute;
use App\Models\AlertSubscription;
use App\Models\AlertUserSetting;
use App\Models\DiscordQueue;
use App\Models\User;
use App\Services\Alerts\AlertDeliveryPolicy;
use App\Services\Alerts\AlertEventCatalog;
use App\Services\Alerts\AlertMetricsRollupService;
use App\Services\Alerts\AlertOccurrenceRecorder;
use App\Services\Alerts\AlertRetentionService;
use App\Services\Alerts\AlertScheduledDeliveryDispatcher;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AlertFoundationTest extends TestCase
{
    use BuildsTestUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-08 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_catalog_contract_is_stable_and_assignment_alert_free(): void
    {
        $catalog = app(AlertEventCatalog::class);
        $definitions = $catalog->all();

        $this->assertNotEmpty($definitions);
        $this->assertCount(count(array_unique(array_keys($definitions))), $definitions);
        foreach (array_keys($definitions) as $eventKey) {
            $this->assertStringNotContainsString('war_assignment', $eventKey);
            $this->assertStringNotContainsString('spy_assignment', $eventKey);
        }

        $manifest = $catalog->rendererManifest();
        $templates = collect($manifest['templates'])->keyBy('template_key');

        $this->assertSame(1, $manifest['contract_version']);
        $this->assertSame([
            'digest.v1',
            'member_alert_v1',
            'milcom_alert_v1',
            'operational_alert_v1',
            'workflow_status_v1',
        ], $templates->keys()->sort()->values()->all());
        $this->assertContains('milcom.incident.detected', $templates['milcom_alert_v1']['event_keys']);
        $this->assertNotContains('war_assignment.created', $templates['milcom_alert_v1']['event_keys']);

        $this->assertSame(
            ['label' => 'Safe', 'nations' => ['One', 'Two']],
            $catalog->safePayload('beige.turn.window', [
                'label' => 'Safe',
                'nations' => ['One', 'Two'],
                'turn' => [['private' => 'nested objects are not allowed']],
                'secret' => 'not allowlisted',
            ]),
        );
    }

    public function test_occurrence_deduplication_creates_web_activity_and_a_canonical_discord_intent(): void
    {
        $routeOwner = $this->grantPermissions(User::factory()->create(), ['manage-war-room']);
        $destination = AlertDestination::factory()->create([
            'kind' => AlertDestinationKind::DiscordChannel,
            'guild_id' => '123456789012345678',
            'channel_id' => '223456789012345678',
            'mention_role_ids' => ['323456789012345678'],
        ]);
        AlertRoute::factory()->create([
            'alert_destination_id' => $destination->id,
            'event_key' => 'milcom.incident.detected',
            'created_by_user_id' => $routeOwner->id,
        ]);

        $arguments = [
            'eventKey' => 'milcom.incident.detected',
            'sourceType' => 'milcom_incident',
            'sourceId' => 91,
            'dedupeKey' => 'milcom-incident:91:detected',
            'payload' => [
                'incident_id' => 91,
                'war_id' => 701,
                'label' => 'Incoming war against Test Nation',
                'unsafe_secret' => 'must-not-leave-nexus',
            ],
            'occurredAt' => now(),
            'deepLinkPath' => '/admin/milcom/incidents/91',
        ];

        $first = app(AlertOccurrenceRecorder::class)->record(...$arguments);
        $second = app(AlertOccurrenceRecorder::class)->record(...$arguments);

        $this->assertSame($first->id, $second->id);
        $this->assertArrayNotHasKey('unsafe_secret', $first->payload);
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertDatabaseCount('alert_deliveries', 2);
        $this->assertDatabaseCount('discord_queue', 1);

        $queue = DiscordQueue::query()->sole();
        $this->assertSame(DiscordQueueLane::Alerts, $queue->lane);
        $this->assertSame('ALERT_DELIVERY_V1', $queue->action);
        $this->assertSame((string) $first->id, $queue->payload['occurrence_id']);
        $this->assertSame('milcom_alert_v1', $queue->payload['template_key']);
        $this->assertSame('channel', $queue->payload['destination']['type']);
        $this->assertSame($destination->channel_id, $queue->payload['destination']['channel_id']);
        $this->assertSame($destination->mention_role_ids, $queue->payload['allowed_role_ids']);
        $this->assertSame(91, $queue->payload['data']['incident_id']);
        $this->assertArrayNotHasKey('unsafe_secret', $queue->payload['data']);
    }

    public function test_scheduled_dispatch_groups_digests_and_quarantines_expired_milcom_alerts(): void
    {
        $recipient = User::factory()->create();
        $snapshot = [
            'type' => 'dm',
            'discord_user_id' => '423456789012345678',
        ];

        $digestDeliveries = collect([1, 2])->map(function (int $sequence) use ($recipient, $snapshot): AlertDelivery {
            $occurrence = AlertOccurrence::factory()->create([
                'event_key' => 'nation.city_count.changed',
                'source_id' => (string) $sequence,
                'subject_id' => (string) $sequence,
                'payload' => [
                    'label' => "Nation {$sequence}",
                    'old_cities' => 10 + $sequence,
                    'cities' => 11 + $sequence,
                ],
                'deep_link_path' => "/nations/{$sequence}",
            ]);

            return AlertDelivery::factory()->create([
                'alert_occurrence_id' => $occurrence->id,
                'recipient_user_id' => $recipient->id,
                'destination_kind' => AlertDestinationKind::DiscordDm,
                'delivery_mode' => AlertDeliveryMode::Daily,
                'status' => AlertDeliveryStatus::Scheduled,
                'destination_snapshot' => $snapshot,
                'scheduled_at' => now()->subMinute(),
            ]);
        });

        $staleOccurrence = AlertOccurrence::factory()->create([
            'event_key' => 'milcom.incident.detected',
            'payload' => ['incident_id' => 92, 'war_id' => 702, 'label' => 'Stale incoming war'],
            'deep_link_path' => '/admin/milcom/incidents/92',
            'stale_at' => now()->subSecond(),
        ]);
        $staleDelivery = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $staleOccurrence->id,
            'destination_kind' => AlertDestinationKind::DiscordChannel,
            'delivery_mode' => AlertDeliveryMode::Immediate,
            'status' => AlertDeliveryStatus::Scheduled,
            'destination_snapshot' => [
                'type' => 'channel',
                'guild_id' => '123456789012345678',
                'channel_id' => '223456789012345678',
            ],
            'scheduled_at' => now()->subMinute(),
        ]);

        $processed = app(AlertScheduledDeliveryDispatcher::class)->dispatchDue();

        $this->assertSame(3, $processed);
        $batch = AlertDeliveryBatch::query()->where('template_key', 'digest.v1')->sole();
        $this->assertSame($batch->id, $digestDeliveries->first()->refresh()->alert_delivery_batch_id);
        $this->assertSame($batch->id, $digestDeliveries->last()->refresh()->alert_delivery_batch_id);
        $this->assertSame(AlertDeliveryStatus::Quarantined, $staleDelivery->refresh()->status);
        $this->assertSame('stale_occurrence', $staleDelivery->reason_code);

        $queue = DiscordQueue::query()->sole();
        $this->assertSame(DiscordQueueLane::Digests, $queue->lane);
        $this->assertSame('digest.v1', $queue->payload['template_key']);
        $this->assertCount(2, $queue->payload['data']['items']);
        $this->assertSame(0, $queue->payload['data']['remaining_count']);
    }

    public function test_member_quiet_hours_and_digest_schedules_use_the_members_timezone(): void
    {
        Carbon::setTestNow('2026-08-09 04:30:00');
        $user = User::factory()->create();
        AlertUserSetting::factory()->create([
            'user_id' => $user->id,
            'timezone' => 'America/Chicago',
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'default_digest_time' => '09:00:00',
        ]);
        $subscription = AlertSubscription::factory()->create([
            'user_id' => $user->id,
            'timezone' => null,
            'delivery_mode' => AlertDeliveryMode::Immediate,
        ]);

        $policy = app(AlertDeliveryPolicy::class);
        $this->assertSame(
            '2026-08-09T12:00:00+00:00',
            $policy->scheduledAtForSubscription($subscription, $user)?->toIso8601String(),
        );

        $subscription->forceFill(['delivery_mode' => AlertDeliveryMode::Daily])->save();
        $this->assertSame(
            '2026-08-09T14:00:00+00:00',
            $policy->scheduledAtForSubscription($subscription->refresh(), $user)?->toIso8601String(),
        );
    }

    public function test_delivery_receipt_records_provider_state_and_destination_health(): void
    {
        $routeOwner = $this->grantPermissions(User::factory()->create(), ['manage-war-room']);
        $destination = AlertDestination::factory()->create([
            'kind' => AlertDestinationKind::DiscordChannel,
            'guild_id' => '123456789012345678',
            'channel_id' => '223456789012345678',
        ]);
        AlertRoute::factory()->create([
            'alert_destination_id' => $destination->id,
            'event_key' => 'milcom.discord_dispatch.failed',
            'created_by_user_id' => $routeOwner->id,
        ]);
        app(AlertOccurrenceRecorder::class)->record(
            eventKey: 'milcom.discord_dispatch.failed',
            sourceType: 'milcom_dispatch',
            sourceId: 8,
            dedupeKey: 'milcom-dispatch:8:failed',
            payload: ['dispatch_id' => 8, 'label' => 'Room dispatch failed'],
            occurredAt: now(),
            deepLinkPath: '/admin/milcom/dispatches/8',
        );

        $leaseService = app(DiscordQueueLeaseService::class);
        $command = $leaseService->claim(
            (string) Str::uuid(),
            (string) Str::uuid(),
            [DiscordQueueLane::Alerts],
            $destination->guild_id,
        );
        $this->assertNotNull($command);

        $acknowledged = $leaseService->acknowledge(
            $command,
            DiscordQueueStatus::Complete,
            $command->lease_token,
            null,
            null,
            [
                'delivery' => 'delivered',
                'delivery_id' => (string) AlertDelivery::query()->whereNotNull('alert_delivery_batch_id')->sole()->id,
                'provider_message_id' => '523456789012345678',
                'guild_id' => $destination->guild_id,
                'channel_id' => $destination->channel_id,
                'retryable' => false,
            ],
        );

        $this->assertSame(DiscordQueueStatus::Complete, $acknowledged->status);
        $this->assertSame(AlertDeliveryStatus::Delivered, AlertDelivery::query()->whereNotNull('alert_delivery_batch_id')->sole()->status);
        $this->assertSame('523456789012345678', AlertDeliveryBatch::query()->sole()->provider_message_id);
        $this->assertSame(AlertDestinationHealth::Healthy, $destination->refresh()->health_status);
        $this->assertDatabaseCount('alert_delivery_attempts', 1);
        $this->assertNotNull(AlertDeliveryAttempt::query()->sole()->finished_at);
    }

    public function test_daily_metrics_rollup_is_idempotent_for_global_scope(): void
    {
        $occurrence = AlertOccurrence::factory()->create([
            'event_key' => 'nation.alliance.changed',
            'received_at' => now()->subMinutes(10),
        ]);
        AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'destination_kind' => AlertDestinationKind::Web,
            'status' => AlertDeliveryStatus::Delivered,
            'delivered_at' => now()->subMinutes(8),
        ]);
        AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'destination_kind' => AlertDestinationKind::DiscordDm,
            'status' => AlertDeliveryStatus::Failed,
            'failed_at' => now()->subMinutes(7),
        ]);

        $service = app(AlertMetricsRollupService::class);
        $this->assertSame(2, $service->rollup(now()->toDateString()));
        $this->assertSame(2, $service->rollup(now()->toDateString()));

        $this->assertDatabaseCount('alert_daily_metrics', 2);
        $this->assertSame(['delivered', 'failed'], AlertDailyMetric::query()
            ->orderBy('outcome')
            ->pluck('outcome')
            ->all());
        $this->assertSame(['global'], AlertDailyMetric::query()->pluck('scope_key')->unique()->all());
    }

    public function test_retention_prunes_detailed_history_after_thirty_days_and_metrics_after_thirteen_months(): void
    {
        $oldOccurrence = AlertOccurrence::factory()->create([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
        $oldDelivery = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $oldOccurrence->id,
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
        $recentOccurrence = AlertOccurrence::factory()->create();
        $oldMetric = AlertDailyMetric::factory()->create([
            'metric_date' => now()->subMonths(14)->toDateString(),
        ]);
        $recentMetric = AlertDailyMetric::factory()->create([
            'event_key' => 'nation.city_count.changed',
            'metric_date' => now()->subMonths(12)->toDateString(),
        ]);

        app(AlertRetentionService::class)->prune();

        $this->assertModelMissing($oldOccurrence);
        $this->assertModelMissing($oldDelivery);
        $this->assertModelExists($recentOccurrence);
        $this->assertModelMissing($oldMetric);
        $this->assertModelExists($recentMetric);
    }
}
