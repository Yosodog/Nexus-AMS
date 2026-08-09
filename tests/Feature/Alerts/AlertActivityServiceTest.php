<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertAttemptStatus;
use App\Enums\AlertBatchStatus;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDelivery;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDeliveryBatch;
use App\Models\AlertOccurrence;
use App\Models\User;
use App\Services\Alerts\AlertActivityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_groups_owner_scoped_delivery_state_and_safe_attempt_details(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $occurrence = AlertOccurrence::factory()->create([
            'audience_user_id' => $user->id,
            'event_key' => 'nation.city_count.changed',
            'payload' => ['label' => 'City change', 'old_cities' => 10, 'cities' => 11],
            'is_test' => true,
        ]);
        $web = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'recipient_user_id' => $user->id,
            'destination_kind' => AlertDestinationKind::Web,
            'status' => AlertDeliveryStatus::Delivered,
        ]);
        $batch = AlertDeliveryBatch::factory()->create([
            'recipient_user_id' => $user->id,
            'status' => AlertBatchStatus::Failed,
            'failure_code' => 'network_error',
            'failure_message' => 'Sensitive provider detail must not be projected.',
            'failed_at' => now(),
        ]);
        $discord = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'alert_delivery_batch_id' => $batch->id,
            'recipient_user_id' => $user->id,
            'destination_kind' => AlertDestinationKind::DiscordDm,
            'status' => AlertDeliveryStatus::Failed,
            'reason_code' => 'network_error',
            'delivered_at' => null,
            'failed_at' => now(),
        ]);
        AlertDeliveryAttempt::factory()->create([
            'alert_delivery_batch_id' => $batch->id,
            'status' => AlertAttemptStatus::RetryableFailure,
            'error_code' => 'network_error',
            'error_message' => 'Raw adapter detail must not be projected.',
            'retryable' => true,
            'finished_at' => now(),
        ]);
        $otherOccurrence = AlertOccurrence::factory()->create(['audience_user_id' => $otherUser->id]);
        $otherWeb = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $otherOccurrence->id,
            'recipient_user_id' => $otherUser->id,
        ]);

        $activity = app(AlertActivityService::class)->forUser($user);

        $this->assertCount(1, $activity['items']);
        $this->assertNull($activity['next_cursor']);
        $item = $activity['items'][0];
        $this->assertSame($web->id, $item['activity_id']);
        $this->assertTrue($item['is_test']);
        $this->assertSame(['delivered', 'failed'], array_column($item['deliveries'], 'status'));
        $discordState = collect($item['deliveries'])->firstWhere('id', $discord->id);
        $this->assertSame($occurrence->id, $discordState['occurrence_id']);
        $this->assertSame('nation.city_count.changed', $discordState['event_key']);
        $this->assertTrue($discordState['is_test']);
        $this->assertSame('network_error', $discordState['batch']['failure_code']);
        $this->assertSame(1, $discordState['batch']['attempt_count']);
        $this->assertSame('network_error', $discordState['batch']['last_attempt']['error_code']);
        $this->assertArrayNotHasKey('failure_message', $discordState['batch']);
        $this->assertArrayNotHasKey('error_message', $discordState['batch']['last_attempt']);

        $read = app(AlertActivityService::class)->markRead($user, $web, true);
        $this->assertNotNull($read->read_at);
        $this->assertNull(app(AlertActivityService::class)->markRead($user, $web, false)->read_at);

        $this->expectException(AuthorizationException::class);
        app(AlertActivityService::class)->markRead($user, $otherWeb, true);
    }

    public function test_delivery_receipts_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $delivery = AlertDelivery::factory()->create(['recipient_user_id' => $owner->id]);

        $this->assertSame(
            $delivery->id,
            app(AlertActivityService::class)->deliveryForUser($owner, $delivery)['id'],
        );

        $this->expectException(AuthorizationException::class);
        app(AlertActivityService::class)->deliveryForUser($other, $delivery);
    }
}
