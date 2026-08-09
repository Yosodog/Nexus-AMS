<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use App\Models\ScheduledTaskRun;
use App\Services\AllianceMembershipService;
use App\Services\PWHealthService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class FederationHealthTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
        config()->set('scheduler_lifecycle.enabled', true);
        Cache::put(PWHealthService::CACHE_KEY_STATUS, true, 600);
        Cache::put(PWHealthService::CACHE_KEY_CHECKED_AT, now()->subMinute()->toIso8601String(), 600);
    }

    public function test_enabled_health_reports_payload_free_operational_signals(): void
    {
        app(FederationIdentityService::class)->enable();
        $link = $this->createStaleLink();

        $outbox = FederationOutboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'sender_installation_id' => (string) Str::ulid(),
            'recipient_installation_id' => $link->remote_installation_id,
            'sender_key_id' => (string) Str::ulid(),
            'recipient_key_id' => (string) Str::ulid(),
            'nonce' => 'health-outbox-nonce',
            'message_type' => FederationMessageType::DeliveryReceived,
            'protocol_version' => '1.0',
            'envelope_body' => null,
            'status' => OutboxStatus::Pending,
            'attempts' => 1,
            'correlation_id' => (string) Str::ulid(),
            'expires_at' => now()->addDay(),
        ]);
        $outbox->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->saveQuietly();

        FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $link->remote_installation_id,
            'recipient_installation_id' => (string) Str::ulid(),
            'sender_key_id' => (string) Str::ulid(),
            'recipient_key_id' => (string) Str::ulid(),
            'nonce' => 'health-inbox-nonce',
            'message_type' => FederationMessageType::DeliveryReceived,
            'protocol_version' => '1.0',
            'payload_hash' => hash('sha256', 'health-payload'),
            'envelope_body' => null,
            'decrypted_payload' => null,
            'status' => InboxStatus::Quarantined,
            'safe_error_code' => 'invalid_envelope',
            'correlation_id' => (string) Str::ulid(),
            'issued_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
            'quarantined_at' => now()->subHour(),
        ]);

        FederationLinkInvitation::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'direction' => 'endpoint_outbound',
            'peer_origin' => 'https://new-peer.example',
            'peer_installation_id' => $link->remote_installation_id,
            'token_hash' => hash('sha256', 'health-endpoint-proposal'),
            'status' => FederationWorkflowStatus::Pending,
            'pending_key' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $this->recordFederationScheduleSuccesses();
        $this->createMilcomOperation([
            'federation_action_required' => true,
            'federation_held_at' => now()->subHour(),
        ]);

        $snapshot = $this->serviceForAlliances([])->snapshot();

        $this->assertSame('warning', $this->check($snapshot, 'federation-outbox')['status']);
        $this->assertSame('warning', $this->check($snapshot, 'federation-inbox-quarantine')['status']);
        $this->assertSame('warning', $this->check($snapshot, 'federation-links')['status']);
        $this->assertSame('warning', $this->check($snapshot, 'federation-pending-changes')['status']);
        $this->assertSame('warning', $this->check($snapshot, 'federation-held-imports')['status']);
        $this->assertSame('healthy', $this->check($snapshot, 'federation-identity')['status']);
        $this->assertSame('healthy', $this->check($snapshot, 'federation-scheduler-outbox')['status']);

        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('health-payload', $serialized);
        $this->assertStringNotContainsString('health-outbox-nonce', $serialized);
        $this->assertStringNotContainsString('health-inbox-nonce', $serialized);
        $this->assertStringNotContainsString('envelope_body', $serialized);
        $this->assertStringNotContainsString('decrypted_payload', $serialized);
    }

    public function test_disabled_federation_is_healthy_without_claiming_operational_freshness(): void
    {
        config()->set('federation.enabled', false);

        $snapshot = $this->serviceForAlliances([])->snapshot();
        $identityCheck = $this->check($snapshot, 'federation-identity');

        $this->assertSame('healthy', $identityCheck['status']);
        $this->assertSame('Disabled', $identityCheck['status_label']);
        $this->assertNull(collect($snapshot['checks'])->firstWhere('key', 'federation-outbox'));
    }

    public function test_enabled_federation_with_an_invalid_https_configuration_is_critical(): void
    {
        app(FederationIdentityService::class)->enable();
        config()->set('federation.network.require_https', false);

        $check = $this->check($this->serviceForAlliances([])->snapshot(), 'federation-identity');

        $this->assertSame('critical', $check['status']);
        $this->assertSame('Configuration invalid', $check['status_label']);
    }

    private function createStaleLink(): FederationLink
    {
        return FederationLink::query()->create([
            'id' => (string) Str::ulid(),
            'remote_installation_id' => (string) Str::ulid(),
            'remote_display_name' => 'Peer Nexus',
            'approved_origin' => 'https://peer.example',
            'status' => FederationLinkStatus::Active,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => '1.0',
            'active_at' => now()->subDay(),
            'last_contact_at' => now()->subHours(2),
        ]);
    }

    private function recordFederationScheduleSuccesses(): void
    {
        foreach ([
            'job:App.Jobs.SweepFederationOutboxJob',
            'job:App.Jobs.ReconcileFederationLinksJob',
            'job:App.Jobs.ExpireFederationResourcesJob',
            'job:App.Jobs.PruneFederationMessagesJob',
        ] as $identifier) {
            ScheduledTaskRun::factory()->create([
                'task_identifier' => $identifier,
                'finished_at' => now()->subMinute(),
            ]);
        }
    }

    private function serviceForAlliances(array $allianceIds): SystemHealthService
    {
        $membershipService = $this->mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('getAllianceIds')
            ->once()
            ->andReturn(collect($allianceIds));

        return new SystemHealthService($membershipService, app(PWHealthService::class));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function check(array $snapshot, string $key): array
    {
        $check = collect($snapshot['checks'])->firstWhere('key', $key);

        $this->assertIsArray($check);

        return $check;
    }
}
