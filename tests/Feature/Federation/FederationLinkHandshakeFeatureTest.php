<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\DTO\TransportResult;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationLinkService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationPeerKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FederationLinkHandshakeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private FederationIdentity $identity;

    private string $remoteInstallationId;

    /** @var array<string, string> */
    private array $remoteMaterial;

    private FederationDiscoveryDocument $discovery;

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
        $this->remoteMaterial = app(FederationCryptography::class)->generateKeyMaterial();
        $this->discovery = new FederationDiscoveryDocument(
            installationId: $this->remoteInstallationId,
            origin: 'https://peer.example',
            displayName: 'Peer Nexus',
            ownershipEpoch: 1,
            currentKey: [
                'key_id' => (string) Str::ulid(),
                'generation' => 1,
                'signing_public_key' => $this->remoteMaterial['signing_public_key'],
                'box_public_key' => $this->remoteMaterial['box_public_key'],
                'signing_fingerprint' => $this->remoteMaterial['signing_fingerprint'],
                'box_fingerprint' => $this->remoteMaterial['box_fingerprint'],
            ],
            protocolVersions: ['1.0'],
            resourceSchemas: ['milcom.war-plan-snapshot' => ['1.0']],
            ingress: [
                'handshakes' => '/api/v1/federation/handshakes',
                'envelopes' => '/api/v1/federation/envelopes',
            ],
            sizeLimits: [
                'outer_request_bytes' => 1048576,
                'decrypted_payload_bytes' => 524288,
            ],
        );
        $this->app->instance(FederationTransport::class, new class($this->discovery) implements FederationTransport
        {
            public function __construct(private readonly FederationDiscoveryDocument $discovery) {}

            public function discover(PeerOrigin $origin): FederationDiscoveryDocument
            {
                return $this->discovery;
            }

            public function send(PeerOrigin $origin, FederationEndpoint $endpoint, string $body): TransportResult
            {
                throw new \LogicException('The handshake service test does not perform HTTP delivery.');
            }
        });
    }

    public function test_outgoing_link_only_becomes_active_after_acceptance_and_activation_acknowledgment(): void
    {
        $actor = User::factory()->create();
        $service = app(FederationLinkService::class);
        [$link, $invitation, $token] = $this->outgoingWorkflow();

        $acceptance = [
            'invitation_id' => $invitation->id,
            'invitation_token' => $token,
            'source_installation_id' => $this->identity->id,
            'recipient_origin' => $this->discovery->origin,
            'recipient_display_name' => $this->discovery->displayName,
            'recipient_installation_id' => $this->remoteInstallationId,
            'recipient_ownership_epoch' => 1,
            'recipient_key' => $this->discovery->currentKey,
            'supported_protocol_versions' => ['1.0'],
            'resource_schemas' => ['milcom.war-plan-snapshot' => ['1.0']],
            'accepted_at' => now()->utc()->toIso8601String(),
        ];
        $service->receiveAcceptance($this->inbox($acceptance), $acceptance);

        $this->assertSame(FederationLinkStatus::PendingLocal, $link->fresh()->status);
        $service->finalizeOutgoing($invitation->fresh(), $actor->id);
        $this->assertSame(FederationWorkflowStatus::Approved, $invitation->fresh()->status);
        $this->assertSame(FederationLinkStatus::PendingLocal, $link->fresh()->status);

        $activation = [
            'invitation_id' => $invitation->id,
            'invitation_token' => $token,
            'link_id' => (string) Str::ulid(),
            'source_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'activated_at' => now()->utc()->toIso8601String(),
            'acknowledgment' => true,
        ];
        $service->receiveActivation($this->inbox($activation), $activation);

        $this->assertSame(FederationLinkStatus::Active, $link->fresh()->status);
        $this->assertSame(FederationWorkflowStatus::Completed, $invitation->fresh()->status);
    }

    public function test_activation_rejects_a_sender_that_does_not_own_the_invitation(): void
    {
        [, $invitation, $token] = $this->outgoingWorkflow(FederationLinkStatus::PendingLocal);
        $invitation->forceFill([
            'status' => FederationWorkflowStatus::Approved,
            'pending_key' => null,
        ])->save();
        $activation = [
            'invitation_id' => $invitation->id,
            'invitation_token' => $token,
            'link_id' => (string) Str::ulid(),
            'source_installation_id' => (string) Str::ulid(),
            'recipient_installation_id' => $this->identity->id,
            'activated_at' => now()->utc()->toIso8601String(),
            'acknowledgment' => true,
        ];

        $this->expectException(ValidationException::class);
        app(FederationLinkService::class)->receiveActivation(
            $this->inbox($activation, $activation['source_installation_id']),
            $activation,
        );
    }

    public function test_incoming_request_cannot_resurrect_a_revoked_link(): void
    {
        $link = $this->link(FederationLinkStatus::Revoked);
        $payload = $this->requestPayload();

        try {
            app(FederationLinkService::class)->receiveRequest($this->inbox($payload), $payload);
            $this->fail('A revoked link must not accept an unsolicited replacement request.');
        } catch (ValidationException) {
            $this->assertSame(FederationLinkStatus::Revoked, $link->fresh()->status);
        }
    }

    public function test_completed_activation_cannot_reactivate_a_locally_suspended_link(): void
    {
        [$link, $invitation, $token] = $this->outgoingWorkflow(FederationLinkStatus::Suspended);
        $invitation->forceFill([
            'status' => FederationWorkflowStatus::Completed,
            'pending_key' => null,
            'consumed_at' => now(),
        ])->save();
        $activation = [
            'invitation_id' => $invitation->id,
            'invitation_token' => $token,
            'link_id' => (string) Str::ulid(),
            'source_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'activated_at' => now()->utc()->toIso8601String(),
            'acknowledgment' => true,
        ];

        try {
            app(FederationLinkService::class)->receiveActivation($this->inbox($activation), $activation);
            $this->fail('A completed activation token reactivated a suspended link.');
        } catch (ValidationException) {
            $this->assertSame(FederationLinkStatus::Suspended, $link->fresh()->status);
        }
    }

    public function test_explicit_fingerprint_confirmed_relink_reuses_only_the_terminal_link_record(): void
    {
        $actor = User::factory()->create();
        $link = $this->link(FederationLinkStatus::Revoked);

        $relinked = app(FederationLinkService::class)->begin(
            $this->discovery->origin,
            $actor->id,
            true,
        );

        $this->assertSame($link->id, $relinked->id);
        $this->assertSame(FederationLinkStatus::PendingRemote, $relinked->fresh()->status);
        $this->assertSame(1, $relinked->invitations()
            ->where('status', FederationWorkflowStatus::Pending->value)
            ->count());
    }

    /** @return array{FederationLink, FederationLinkInvitation, string} */
    private function outgoingWorkflow(
        FederationLinkStatus $status = FederationLinkStatus::PendingRemote,
    ): array {
        $link = $this->link($status);
        $token = Base64Url::encode(random_bytes(32));
        $invitation = FederationLinkInvitation::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'direction' => 'outbound',
            'peer_origin' => $this->discovery->origin,
            'peer_installation_id' => $this->remoteInstallationId,
            'token_hash' => hash('sha256', $token),
            'status' => FederationWorkflowStatus::Pending,
            'pending_key' => 1,
            'discovery_snapshot' => $this->discovery->toArray(),
            'expires_at' => now()->addDay(),
        ]);

        return [$link, $invitation, $token];
    }

    private function link(FederationLinkStatus $status): FederationLink
    {
        $link = FederationLink::query()->create([
            'id' => (string) Str::ulid(),
            'remote_installation_id' => $this->remoteInstallationId,
            'remote_display_name' => $this->discovery->displayName,
            'approved_origin' => $this->discovery->origin,
            'status' => $status,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => '1.0',
            'negotiated_resource_versions' => ['milcom.war-plan-snapshot' => ['1.0']],
            'revoked_at' => $status === FederationLinkStatus::Revoked ? now() : null,
        ]);
        FederationPeerKey::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'remote_key_id' => $this->discovery->currentKey['key_id'],
            'generation' => 1,
            'status' => FederationKeyStatus::Active,
            'signing_public_key' => $this->remoteMaterial['signing_public_key'],
            'box_public_key' => $this->remoteMaterial['box_public_key'],
            'signing_fingerprint' => $this->remoteMaterial['signing_fingerprint'],
            'box_fingerprint' => $this->remoteMaterial['box_fingerprint'],
            'approved_at' => now(),
        ]);

        return $link;
    }

    /** @return array<string, mixed> */
    private function requestPayload(): array
    {
        return [
            'invitation_id' => (string) Str::ulid(),
            'invitation_token' => Base64Url::encode(random_bytes(32)),
            'source_origin' => $this->discovery->origin,
            'source_display_name' => $this->discovery->displayName,
            'source_installation_id' => $this->remoteInstallationId,
            'source_ownership_epoch' => 1,
            'source_key' => $this->discovery->currentKey,
            'supported_protocol_versions' => ['1.0'],
            'resource_schemas' => ['milcom.war-plan-snapshot' => ['1.0']],
            'expires_at' => now()->addHours(23)->utc()->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function inbox(array $payload, ?string $senderInstallationId = null): FederationInboxMessage
    {
        $senderInstallationId ??= $this->remoteInstallationId;

        return FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $senderInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'sender_key_id' => $this->discovery->currentKey['key_id'],
            'recipient_key_id' => $this->identity->activeKey->id,
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => FederationMessageType::LinkActivation,
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
