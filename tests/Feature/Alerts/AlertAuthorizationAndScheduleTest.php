<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Jobs\DispatchScheduledAlertBatchesJob;
use App\Jobs\PruneAlertHistoryJob;
use App\Jobs\RollupAlertMetricsJob;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AlertRoute;
use App\Models\User;
use App\Services\Alerts\AlertOccurrenceRecorder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Tests\TestCase;

class AlertAuthorizationAndScheduleTest extends TestCase
{
    use BuildsTestUsers, ConfiguresDiscordQueueV2, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();
    }

    public function test_restricted_route_is_reauthorized_for_every_occurrence(): void
    {
        $owner = $this->grantPermissions(User::factory()->create(), ['manage-war-room']);
        $destination = AlertDestination::factory()->create([
            'kind' => AlertDestinationKind::DiscordChannel,
            'guild_id' => '123456789012345678',
            'channel_id' => '223456789012345678',
        ]);
        AlertRoute::factory()->create([
            'alert_destination_id' => $destination->id,
            'created_by_user_id' => $owner->id,
            'event_key' => 'milcom.incident.detected',
        ]);

        $this->recordIncident('authorized', 101);
        $this->assertDatabaseCount('discord_queue', 1);

        $owner->roles()->detach();
        $this->recordIncident('permission-revoked', 102);

        $this->assertDatabaseCount('discord_queue', 1);
        $suppressed = AlertDelivery::query()
            ->whereHas('occurrence', fn ($query) => $query->where('source_id', '102'))
            ->sole();
        $this->assertSame(AlertDeliveryStatus::Quarantined, $suppressed->status);
        $this->assertSame('route_permission_missing', $suppressed->reason_code);
    }

    public function test_alert_maintenance_jobs_are_scheduled_with_cluster_locks(): void
    {
        $events = collect(app(Schedule::class)->events());
        $expected = [
            DispatchScheduledAlertBatchesJob::class => '* * * * *',
            RollupAlertMetricsJob::class => '20 0 * * *',
            PruneAlertHistoryJob::class => '20 1 * * *',
        ];

        foreach ($expected as $job => $expression) {
            $event = $events->first(fn ($event): bool => $event instanceof CallbackEvent
                && is_string($event->description)
                && str_contains($event->description, $job));

            $this->assertInstanceOf(CallbackEvent::class, $event, "Missing scheduled job {$job}.");
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    private function recordIncident(string $suffix, int $id): void
    {
        app(AlertOccurrenceRecorder::class)->record(
            eventKey: 'milcom.incident.detected',
            sourceType: 'milcom_incident',
            sourceId: $id,
            dedupeKey: "milcom-incident:{$id}:{$suffix}",
            payload: ['incident_id' => $id, 'war_id' => 700 + $id, 'label' => "Incident {$id}"],
            occurredAt: now(),
        );
    }
}
