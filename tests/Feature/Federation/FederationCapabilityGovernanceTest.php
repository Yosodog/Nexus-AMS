<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Domain\Federation\Services\FederationCapabilityService;
use App\Domain\Federation\Services\FederationCoalitionService;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationInboxProcessor;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationPeerKey;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FederationCapabilityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private FederationIdentity $identity;

    private FederationLink $link;

    private string $remoteInstallationId;

    private FederationPeerKey $remoteKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
        config()->set('federation.features.inbound', true);
        config()->set('federation.features.linking', true);
        Queue::fake();

        $this->identity = app(FederationIdentityService::class)->enable();
        $this->remoteInstallationId = (string) Str::ulid();
        $material = app(FederationCryptography::class)->generateKeyMaterial();
        $this->link = FederationLink::query()->create([
            'id' => (string) Str::ulid(),
            'remote_installation_id' => $this->remoteInstallationId,
            'remote_display_name' => 'Peer Nexus',
            'approved_origin' => 'https://peer.example',
            'status' => FederationLinkStatus::Active,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => '1.0',
            'active_at' => now(),
        ]);
        $this->remoteKey = FederationPeerKey::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $this->link->id,
            'remote_key_id' => (string) Str::ulid(),
            'generation' => 1,
            'status' => 'active',
            ...$material,
            'approved_at' => now(),
        ]);
    }

    public function test_capabilities_are_directional_explicit_and_default_denied(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $service = app(FederationCapabilityService::class);

        $this->assertFalse($service->allows($coalition, $this->link, CapabilityDirection::Outbound));
        $outbound = $service->set(
            $coalition,
            $this->link,
            CapabilityDirection::Outbound,
            CapabilityState::Active,
            null,
            $actor->id,
        );
        $inbound = $service->set(
            $coalition,
            $this->link,
            CapabilityDirection::Inbound,
            CapabilityState::Active,
            CarbonImmutable::now('UTC')->addHour(),
            $actor->id,
        );

        $this->assertSame(1, $outbound->revision);
        $this->assertSame(1, $inbound->revision);
        $this->assertTrue($service->allows($coalition, $this->link, CapabilityDirection::Outbound));
        $this->assertTrue($service->allows($coalition, $this->link, CapabilityDirection::Inbound));

        $revoked = $service->set(
            $coalition,
            $this->link,
            CapabilityDirection::Outbound,
            CapabilityState::Revoked,
            null,
            $actor->id,
        );

        $this->assertSame(2, $revoked->revision);
        $this->assertFalse($service->allows($coalition, $this->link, CapabilityDirection::Outbound));
        $this->assertSame(
            CapabilityState::Active,
            $service->current($coalition, $this->link, CapabilityDirection::Inbound)?->state,
        );
    }

    public function test_received_capability_revisions_are_monotonic_and_conflicts_are_rejected(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $service = app(FederationCapabilityService::class);
        $payload = $this->manifest($coalition, 1, CapabilityState::Active);
        $service->receiveManifest($this->inbox($payload), $payload);

        $this->assertTrue($service->allows(
            $coalition,
            $this->link,
            CapabilityDirection::Inbound,
            $this->remoteInstallationId,
        ));

        $conflicting = $this->manifest($coalition, 1, CapabilityState::Revoked);
        $this->expectException(ValidationException::class);
        $service->receiveManifest($this->inbox($conflicting), $conflicting);
    }

    public function test_remote_capability_invalidation_respects_the_statement_direction(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $resource = FederationReceivedResource::factory()->accepted()->create([
            'federation_link_id' => $this->link->id,
            'source_installation_id' => $this->remoteInstallationId,
            'coalition_id' => $coalition->id,
        ]);
        $version = FederationReceivedVersion::factory()->accepted()->create([
            'federation_received_resource_id' => $resource->id,
            'source_installation_id' => $this->remoteInstallationId,
            'source_publication_id' => $resource->source_publication_id,
        ]);
        $processor = app(FederationInboxProcessor::class);
        $remoteInbound = $this->manifest(
            $coalition,
            1,
            CapabilityState::Revoked,
            CapabilityDirection::Inbound,
        );

        $processor->process($this->inbox($remoteInbound));

        $this->assertSame(ReceivedResourceState::Accepted, $resource->fresh()->state);
        $this->assertNotNull($version->fresh()->canonical_payload);

        $remoteOutbound = $this->manifest(
            $coalition,
            1,
            CapabilityState::Revoked,
            CapabilityDirection::Outbound,
        );
        $processor->process($this->inbox($remoteOutbound));

        $this->assertSame(ReceivedResourceState::Revoked, $resource->fresh()->state);
        $this->assertNull($version->fresh()->canonical_payload);
    }

    /** @return array<string, mixed> */
    private function manifest(
        object $coalition,
        int $revision,
        CapabilityState $state,
        CapabilityDirection $direction = CapabilityDirection::Inbound,
    ): array {
        $statement = [
            'peer_installation_id' => $this->identity->id,
            'coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'direction' => $direction->value,
            'revision' => $revision,
            'state' => $state->value,
            'expires_at' => null,
        ];
        $statement['statement_hash'] = hash('sha256', CanonicalJson::encode($statement));

        return [
            'issuer_installation_id' => $this->remoteInstallationId,
            'generated_at' => now()->utc()->toIso8601String(),
            'statements' => [$statement],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function inbox(array $payload): FederationInboxMessage
    {
        return FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'sender_key_id' => $this->remoteKey->remote_key_id,
            'recipient_key_id' => $this->identity->activeKey->id,
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => FederationMessageType::CapabilityManifest,
            'protocol_version' => '1.0',
            'payload_hash' => hash('sha256', CanonicalJson::encode($payload)),
            'envelope_body' => '{}',
            'decrypted_payload' => CanonicalJson::encode($payload),
            'correlation_id' => (string) Str::ulid(),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
