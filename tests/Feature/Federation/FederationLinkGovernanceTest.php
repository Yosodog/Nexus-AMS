<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\FederationEndpointChangeStatus;
use App\Domain\Federation\Enums\FederationKeyRotationPhase;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationLinkService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationPeerKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FederationLinkGovernanceTest extends TestCase
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
        $remoteMaterial = app(FederationCryptography::class)->generateKeyMaterial();
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
            'status' => FederationKeyStatus::Active,
            ...$remoteMaterial,
            'approved_at' => now(),
        ]);
    }

    public function test_endpoint_changes_require_remote_approval_before_pinning(): void
    {
        $actor = User::factory()->create();
        $service = app(FederationLinkService::class);
        $proposal = $service->proposeEndpointChange($this->link, 'https://peer-new.example', $actor->id);

        $payload = data_get($proposal->fresh()->discovery_snapshot, 'payload');
        $payload['status'] = FederationEndpointChangeStatus::Approved->value;
        $service->receiveEndpointChange($this->inbox(FederationMessageType::EndpointChange, $payload), $payload);

        $this->assertSame(
            FederationWorkflowStatus::Approved->value,
            FederationLinkInvitation::query()->findOrFail($proposal->id)->status->value,
        );
        $this->assertSame('https://peer.example', $this->link->fresh()->approved_origin);

        $service->activateEndpointChange($proposal->fresh(), $actor->id);

        $this->assertSame('https://peer-new.example', $this->link->fresh()->approved_origin);
        $this->assertSame(
            'completed',
            FederationLinkInvitation::query()->findOrFail($proposal->id)->status->value,
        );
    }

    public function test_inbound_endpoint_proposal_can_be_approved_without_automatic_discovery_updates(): void
    {
        $actor = User::factory()->create();
        $expiresAt = CarbonImmutable::now('UTC')->addDay();
        $payload = [
            'proposal_id' => (string) Str::ulid(),
            'installation_id' => $this->identity->id,
            'old_origin' => 'https://nexus-one.example',
            'new_origin' => 'https://nexus-one-new.example',
            'ownership_epoch' => 1,
            'status' => FederationEndpointChangeStatus::Proposed->value,
            'issued_at' => now()->utc()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $service = app(FederationLinkService::class);
        $service->receiveEndpointChange($this->inbox(FederationMessageType::EndpointChange, $payload), $payload);
        $proposal = FederationLinkInvitation::query()->findOrFail($payload['proposal_id']);
        $service->approveEndpointChange($proposal, $actor->id);

        $this->assertSame('approved', $proposal->fresh()->status->value);
        $this->assertSame('https://peer.example', $this->link->fresh()->approved_origin);
    }

    public function test_key_rotation_acknowledgment_is_required_before_activation_and_compromise_reapproval_is_explicit(): void
    {
        $actor = User::factory()->create();
        $service = app(FederationLinkService::class);
        $newKey = app(FederationIdentityService::class)->initiateRoutineRotation();
        $service->broadcastKeyRotation($newKey, $actor->id);

        $metadata = StrictJson::decodeObject((string) $newKey->fresh()->rotation_statement);
        $base = StrictJson::decodeObject($metadata['statement']);
        $newKeyPayload = $this->localKeyPayload($newKey->fresh());
        $ackStatement = CanonicalJson::encode([
            'phase' => FederationKeyRotationPhase::Acknowledged->value,
            'base_statement' => $metadata['statement'],
            'acted_at' => now()->utc()->toIso8601String(),
        ]);
        $ackPayload = [
            'installation_id' => $this->identity->id,
            'ownership_epoch' => 1,
            'old_key_id' => $base['old_key_id'],
            'new_key' => $newKeyPayload,
            'statement' => $ackStatement,
            'old_signature' => $metadata['old_signature'],
            'new_signature' => $metadata['new_signature'],
            'issued_at' => $base['issued_at'],
        ];

        $service->receiveKeyRotation($this->inbox(FederationMessageType::KeyRotation, $ackPayload), $ackPayload);
        $activated = $service->activateKeyRotation($newKey, $actor->id);

        $this->assertSame(FederationKeyStatus::Active, $activated->fresh()->status);
        $this->assertSame($activated->id, FederationIdentity::query()->firstOrFail()->activeKey->id);

        $this->link->forceFill([
            'status' => FederationLinkStatus::Suspended,
            'suspension_reason_code' => 'key_reapproval_required',
            'suspended_at' => now(),
        ])->save();
        $replacement = app(FederationCryptography::class)->generateKeyMaterial();
        $replacementPayload = [
            'key_id' => (string) Str::ulid(),
            'generation' => 3,
            'signing_public_key' => $replacement['signing_public_key'],
            'box_public_key' => $replacement['box_public_key'],
            'signing_fingerprint' => $replacement['signing_fingerprint'],
            'box_fingerprint' => $replacement['box_fingerprint'],
        ];

        $approved = $service->reapprovePeerKey($this->link, $replacementPayload, $actor->id, true);

        $this->assertSame(FederationKeyStatus::Active, $approved->status);
        $this->assertSame(FederationLinkStatus::Suspended, $this->link->fresh()->status);
        $this->assertSame('reapproval_pending', $this->link->fresh()->suspension_reason_code);

        $issuedAt = now()->utc()->toIso8601String();
        $recoveryBase = [
            'installation_id' => $this->remoteInstallationId,
            'ownership_epoch' => 1,
            'old_key_id' => $this->remoteKey->remote_key_id,
            'new_key' => $replacementPayload,
            'issued_at' => $issuedAt,
        ];
        $baseStatement = CanonicalJson::encode($recoveryBase);
        $recoveryPayload = [
            ...$recoveryBase,
            'statement' => CanonicalJson::encode([
                'phase' => FederationKeyRotationPhase::Reapproved->value,
                'base_statement' => $baseStatement,
                'acted_at' => $issuedAt,
            ]),
            'old_signature' => '',
            'new_signature' => app(FederationCryptography::class)->sign(
                $baseStatement,
                $replacement['signing_private_key'],
            ),
        ];
        $service->receiveKeyRotation(
            $this->inbox(FederationMessageType::KeyRotation, $recoveryPayload, $approved->remote_key_id),
            $recoveryPayload,
        );

        $this->assertSame(FederationLinkStatus::Active, $this->link->fresh()->status);
    }

    public function test_suspension_notice_only_suspends_the_authenticated_peer_link(): void
    {
        $payload = [
            'link_id' => (string) Str::ulid(),
            'reason_code' => 'remote_maintenance',
            'suspended_at' => now()->utc()->toIso8601String(),
        ];

        app(FederationLinkService::class)->receiveSuspensionNotice(
            $this->inbox(FederationMessageType::LinkSuspensionNotice, $payload),
            $payload,
        );

        $this->assertSame(FederationLinkStatus::Suspended, $this->link->fresh()->status);
        $this->assertSame('remote_maintenance', $this->link->fresh()->suspension_reason_code);
    }

    public function test_compromise_replacement_becomes_the_local_discovery_key_while_every_link_stays_suspended(): void
    {
        $oldKey = $this->identity->activeKey;
        $replacement = app(FederationIdentityService::class)->markCompromised($oldKey);

        $this->assertSame(FederationKeyStatus::Compromised, $oldKey->fresh()->status);
        $this->assertNull($oldKey->fresh()->signing_private_key);
        $this->assertSame(FederationKeyStatus::Active, $replacement->fresh()->status);
        $this->assertSame(1, $replacement->fresh()->active_key);
        $this->assertSame($replacement->id, $this->identity->fresh()->activeKey->id);
        $this->assertSame(FederationLinkStatus::Suspended, $this->link->fresh()->status);
        $this->assertSame('local_key_compromised', $this->link->fresh()->suspension_reason_code);
    }

    public function test_compromised_peer_key_generation_cannot_be_reapproved(): void
    {
        $actor = User::factory()->create();
        $this->link->forceFill([
            'status' => FederationLinkStatus::Suspended,
            'suspension_reason_code' => 'key_reapproval_required',
            'suspended_at' => now(),
        ])->save();
        $this->remoteKey->forceFill([
            'status' => FederationKeyStatus::Compromised,
            'compromised_at' => now(),
        ])->save();

        $this->expectException(ValidationException::class);
        app(FederationLinkService::class)->reapprovePeerKey(
            $this->link,
            [
                'key_id' => $this->remoteKey->remote_key_id,
                'generation' => (int) $this->remoteKey->generation,
                'signing_public_key' => $this->remoteKey->signing_public_key,
                'box_public_key' => $this->remoteKey->box_public_key,
                'signing_fingerprint' => $this->remoteKey->signing_fingerprint,
                'box_fingerprint' => $this->remoteKey->box_fingerprint,
            ],
            $actor->id,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function localKeyPayload(object $key): array
    {
        return [
            'key_id' => $key->id,
            'generation' => (int) $key->generation,
            'signing_public_key' => $key->signing_public_key,
            'box_public_key' => $key->box_public_key,
            'signing_fingerprint' => $key->signing_fingerprint,
            'box_fingerprint' => $key->box_fingerprint,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function inbox(
        FederationMessageType $type,
        array $payload,
        ?string $senderKeyId = null,
    ): FederationInboxMessage {
        return FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'sender_key_id' => $senderKeyId ?? $this->remoteKey->remote_key_id,
            'recipient_key_id' => $this->identity->activeKey->id,
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => $type,
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
