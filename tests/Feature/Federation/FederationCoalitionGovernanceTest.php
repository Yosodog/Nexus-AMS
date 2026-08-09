<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Services\FederationCapabilityService;
use App\Domain\Federation\Services\FederationCoalitionService;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPeerKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FederationCoalitionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private FederationIdentity $identity;

    private FederationLink $link;

    private string $remoteInstallationId;

    private FederationPeerKey $remoteKey;

    /** @var array<string, string> */
    private array $remoteKeyMaterial;

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
        $this->remoteKeyMaterial = $material;
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

    public function test_coordinator_proposals_are_explicitly_approved_or_rejected_and_issue_monotonic_rosters(): void
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
        $service = app(FederationCoalitionService::class);

        $roleProposal = $service->proposeRosterChange(
            $coalition,
            'member.role',
            $this->remoteInstallationId,
            CoalitionRole::Admin,
            $actor->id,
        );
        $service->approveProposal($roleProposal, $actor->id);

        $this->assertSame(
            CoalitionRole::Admin,
            $coalition->memberships()->where('installation_id', $this->remoteInstallationId)->firstOrFail()->role,
        );
        $this->assertSame(2, $coalition->fresh()->roster_revision);
        $this->assertSame(
            $coalition->fresh()->roster_hash,
            hash('sha256', CanonicalJson::encode(
                tap(json_decode($coalition->fresh()->canonical_manifest, true), function (array &$manifest): void {
                    unset($manifest['manifest_hash']);
                }),
            )),
        );

        $removeProposal = $service->proposeRosterChange(
            $coalition->fresh(),
            'member.remove',
            $this->remoteInstallationId,
            null,
            $actor->id,
        );
        $service->rejectProposal($removeProposal, $actor->id);

        $this->assertSame(FederationWorkflowStatus::Rejected, $removeProposal->fresh()->status);
        $this->assertSame(
            MembershipStatus::Active,
            $coalition->memberships()->where('installation_id', $this->remoteInstallationId)->firstOrFail()->status,
        );
    }

    public function test_incoming_invitation_stores_only_the_token_hash(): void
    {
        $token = Base64Url::encode(random_bytes(32));
        $coalitionId = (string) Str::ulid();
        $payload = [
            'action' => 'invite',
            'invitation_id' => (string) Str::ulid(),
            'invitation_token' => $token,
            'coalition_id' => $coalitionId,
            'coalition_name' => 'Protected coalition',
            'coordinator_installation_id' => $this->remoteInstallationId,
            'role' => CoalitionRole::Member->value,
            'roster_revision' => 1,
            'expires_at' => now()->addHours(12)->utc()->toIso8601String(),
            'acted_at' => null,
        ];

        app(FederationCoalitionService::class)->receiveInvitation(
            $this->inbox($payload, FederationMessageType::CoalitionInvitation),
            $payload,
        );

        $coalition = FederationCoalition::query()->findOrFail($coalitionId);
        $invitation = FederationCoalitionInvitation::query()->findOrFail($payload['invitation_id']);
        $manifest = StrictJson::decodeObject($coalition->canonical_manifest);

        $this->assertArrayNotHasKey('invitation_token', $manifest);
        $this->assertStringNotContainsString($token, $coalition->canonical_manifest);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
    }

    public function test_member_removal_expires_capabilities_and_dissolution_expires_all_governance_state(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $membership = $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $capability = app(FederationCapabilityService::class)->set(
            $coalition,
            $this->link,
            CapabilityDirection::Outbound,
            CapabilityState::Active,
            null,
            $actor->id,
        );

        $service = app(FederationCoalitionService::class);
        $service->removeMember($coalition, $membership, $actor->id);

        $this->assertSame(MembershipStatus::Removed, $membership->fresh()->status);
        $this->assertSame(CapabilityState::Expired, $capability->fresh()->state);

        $secondMember = $membership->fresh();
        $secondMember->forceFill([
            'status' => MembershipStatus::Active,
            'removed_at' => null,
            'roster_revision' => $coalition->fresh()->roster_revision,
        ])->save();
        $secondCapability = app(FederationCapabilityService::class)->set(
            $coalition->fresh(),
            $this->link,
            CapabilityDirection::Inbound,
            CapabilityState::Active,
            null,
            $actor->id,
        );

        $service->dissolve($coalition->fresh(), $actor->id);

        $this->assertSame(CoalitionStatus::Dissolved, $coalition->fresh()->status);
        $this->assertSame(CapabilityState::Expired, $secondCapability->fresh()->state);
        $this->assertSame(MembershipStatus::Removed, $secondMember->fresh()->status);
    }

    public function test_receive_proposal_accepts_remote_admin_workflows_and_receive_dissolution_marks_roster_terminal(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Admin,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $proposalPayload = $this->proposalPayload(
            $coalition,
            'member.role',
            $this->remoteInstallationId,
            CoalitionRole::Member,
        );
        $service = app(FederationCoalitionService::class);
        $service->receiveProposal(
            $this->inbox($proposalPayload, FederationMessageType::CoalitionProposal),
            $proposalPayload,
        );
        $proposal = $coalition->proposals()->findOrFail($proposalPayload['proposal_id']);
        $service->approveProposal($proposal, $actor->id);

        $this->assertSame(FederationWorkflowStatus::Approved, $proposal->fresh()->status);
        $this->assertSame(
            CoalitionRole::Member,
            $coalition->memberships()->where('installation_id', $this->remoteInstallationId)->firstOrFail()->role,
        );

        $remoteCoalition = FederationCoalition::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Remote Coalition',
            'coordinator_installation_id' => $this->remoteInstallationId,
            'status' => CoalitionStatus::Active,
            'roster_revision' => 1,
            'roster_hash' => str_repeat('b', 64),
            'canonical_manifest' => '{}',
            'created_by' => $actor->id,
        ]);
        $remoteCoalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Coordinator,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $remoteCoalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->identity->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $dissolution = [
            'coalition_id' => $remoteCoalition->id,
            'revision' => 2,
            'dissolved_at' => now()->utc()->toIso8601String(),
            'manifest_hash' => str_repeat('c', 64),
        ];

        $service->receiveDissolution(
            $this->inbox($dissolution, FederationMessageType::CoalitionDissolved),
            $dissolution,
        );

        $this->assertSame(CoalitionStatus::Dissolved, $remoteCoalition->fresh()->status);

        try {
            $replay = $this->manifest($remoteCoalition->fresh(), 3, 'Remote Coalition');
            $service->receiveManifest($this->inbox($replay), $replay);
            $this->fail('A terminal coalition was reactivated by a later active roster.');
        } catch (ValidationException) {
            $this->assertSame(CoalitionStatus::Dissolved, $remoteCoalition->fresh()->status);
        }
    }

    public function test_same_roster_revision_with_different_canonical_contents_is_rejected(): void
    {
        $actor = User::factory()->create();
        $remoteCoordinatorId = $this->remoteInstallationId;
        $coalition = FederationCoalition::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Remote Operations',
            'coordinator_installation_id' => $remoteCoordinatorId,
            'status' => CoalitionStatus::Active,
            'roster_revision' => 1,
            'roster_hash' => str_repeat('a', 64),
            'canonical_manifest' => '{}',
            'created_by' => $actor->id,
        ]);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $remoteCoordinatorId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Coordinator,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->identity->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $payload = $this->manifest($coalition, 2, 'Remote Operations');
        $service = app(FederationCoalitionService::class);
        $service->receiveManifest($this->inbox($payload), $payload);

        $conflicting = $this->manifest($coalition->fresh(), 2, 'Conflicting Name');

        $this->expectException(ValidationException::class);
        $service->receiveManifest($this->inbox($conflicting), $conflicting);
    }

    public function test_removed_installation_accepts_the_coordinator_roster_and_loses_local_membership(): void
    {
        $coalition = FederationCoalition::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Remote Operations',
            'coordinator_installation_id' => $this->remoteInstallationId,
            'status' => CoalitionStatus::Active,
            'roster_revision' => 1,
            'roster_hash' => str_repeat('a', 64),
            'canonical_manifest' => '{}',
        ]);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Coordinator,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $localMembership = $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->identity->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $payload = $this->manifest($coalition, 2, 'Remote Operations');
        $localMemberIndex = collect($payload['members'])->search(
            fn (array $member): bool => $member['installation_id'] === $this->identity->id,
        );
        $payload['members'][$localMemberIndex]['status'] = MembershipStatus::Removed->value;
        $payload['members'][$localMemberIndex]['removed_at'] = now()->utc()->toIso8601String();
        unset($payload['manifest_hash']);
        $payload['manifest_hash'] = hash('sha256', CanonicalJson::encode($payload));

        app(FederationCoalitionService::class)->receiveManifest($this->inbox($payload), $payload);

        $this->assertSame(MembershipStatus::Removed, $localMembership->fresh()->status);
        $this->assertSame(2, $coalition->fresh()->roster_revision);
    }

    public function test_coordinator_transfer_manifest_requires_and_applies_both_installation_signatures(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Admin,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $payload = $this->transferManifest($coalition->fresh());

        app(FederationCoalitionService::class)->receiveManifest($this->inbox($payload), $payload);

        $updated = $coalition->fresh();
        $this->assertSame($this->remoteInstallationId, $updated->coordinator_installation_id);
        $this->assertSame(2, $updated->roster_revision);
        $this->assertSame(CoalitionRole::Admin, $updated->memberships()
            ->where('installation_id', $this->identity->id)
            ->firstOrFail()
            ->role);
        $this->assertSame(CoalitionRole::Coordinator, $updated->memberships()
            ->where('installation_id', $this->remoteInstallationId)
            ->firstOrFail()
            ->role);
        $this->assertArrayHasKey('transfer_proof', StrictJson::decodeObject($updated->canonical_manifest));
    }

    public function test_coordinator_transfer_proposal_and_final_approval_are_delivered_to_the_destination(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Admin,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $service = app(FederationCoalitionService::class);
        $proposal = $service->proposeCoordinatorTransfer($coalition, $this->remoteInstallationId, $actor->id);

        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::CoalitionProposal->value)
            ->where('recipient_installation_id', $this->remoteInstallationId)
            ->count());

        $proposal->forceFill([
            'status' => FederationWorkflowStatus::Approved,
            'pending_key' => null,
            'reviewed_at' => now(),
        ])->save();
        $service->approveCoordinatorTransfer($proposal->fresh(), $actor->id);

        $this->assertSame(2, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::CoalitionProposal->value)
            ->where('recipient_installation_id', $this->remoteInstallationId)
            ->count());
    }

    public function test_coordinator_transfer_manifest_rejects_a_forged_countersignature(): void
    {
        $actor = User::factory()->create();
        $coalition = app(FederationCoalitionService::class)->create('Operations', null, $actor->id);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $this->remoteInstallationId,
            'federation_link_id' => $this->link->id,
            'role' => CoalitionRole::Admin,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);
        $payload = $this->transferManifest($coalition->fresh());
        $payload['transfer_proof']['new_coordinator_signature'] = Base64Url::encode(random_bytes(64));

        $this->expectException(ValidationException::class);
        app(FederationCoalitionService::class)->receiveManifest($this->inbox($payload), $payload);
    }

    /** @return array<string, mixed> */
    private function manifest(FederationCoalition $coalition, int $revision, string $name): array
    {
        $members = [
            [
                'installation_id' => $this->remoteInstallationId,
                'role' => CoalitionRole::Coordinator->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now()->utc()->toIso8601String(),
                'expires_at' => null,
                'removed_at' => null,
            ],
            [
                'installation_id' => $this->identity->id,
                'role' => CoalitionRole::Member->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now()->utc()->toIso8601String(),
                'expires_at' => null,
                'removed_at' => null,
            ],
        ];
        $payload = [
            'coalition_id' => $coalition->id,
            'name' => $name,
            'coordinator_installation_id' => $this->remoteInstallationId,
            'revision' => $revision,
            'status' => CoalitionStatus::Active->value,
            'expires_at' => null,
            'members' => $members,
        ];
        $payload['manifest_hash'] = hash('sha256', CanonicalJson::encode($payload));

        return $payload;
    }

    /** @return array<string, mixed> */
    private function proposalPayload(
        FederationCoalition $coalition,
        string $proposalType,
        string $targetInstallationId,
        CoalitionRole $requestedRole,
    ): array {
        $payload = [
            'proposal_id' => (string) Str::ulid(),
            'coalition_id' => $coalition->id,
            'proposal_type' => $proposalType,
            'workflow_key' => $coalition->id.':'.$proposalType.':'.$targetInstallationId,
            'target_installation_id' => $targetInstallationId,
            'requested_role' => $requestedRole->value,
            'base_roster_revision' => (int) $coalition->roster_revision,
            'expires_at' => now()->addDay()->utc()->toIso8601String(),
        ];
        $payload['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        return $payload;
    }

    /** @return array<string, mixed> */
    private function transferManifest(FederationCoalition $coalition): array
    {
        $members = $coalition->memberships()
            ->orderBy('installation_id')
            ->get()
            ->map(fn ($membership): array => [
                'installation_id' => $membership->installation_id,
                'role' => $membership->installation_id === $this->remoteInstallationId
                    ? CoalitionRole::Coordinator->value
                    : CoalitionRole::Admin->value,
                'status' => $membership->status->value,
                'joined_at' => $membership->joined_at?->utc()->toIso8601String(),
                'expires_at' => $membership->expires_at?->utc()->toIso8601String(),
                'removed_at' => $membership->removed_at?->utc()->toIso8601String(),
            ])
            ->all();
        $manifest = [
            'coalition_id' => $coalition->id,
            'name' => $coalition->name,
            'coordinator_installation_id' => $this->remoteInstallationId,
            'revision' => 2,
            'status' => CoalitionStatus::Active->value,
            'expires_at' => null,
            'members' => $members,
        ];
        $manifest['manifest_hash'] = hash('sha256', CanonicalJson::encode($manifest));
        $statement = [
            'proposal_id' => (string) Str::ulid(),
            'coalition_id' => $coalition->id,
            'base_roster_revision' => 1,
            'base_roster_hash' => $coalition->roster_hash,
            'previous_coordinator_installation_id' => $this->identity->id,
            'new_coordinator_installation_id' => $this->remoteInstallationId,
            'manifest_hash' => $manifest['manifest_hash'],
        ];
        $signatureInput = "nexus-federation:coalition-transfer-manifest:v1\0".CanonicalJson::encode($statement);
        $cryptography = app(FederationCryptography::class);
        $manifest['transfer_proof'] = [
            'statement' => $statement,
            'previous_coordinator_key_id' => $this->identity->activeKey->id,
            'previous_coordinator_signature' => $cryptography->sign(
                $signatureInput,
                $this->identity->activeKey->signing_private_key,
            ),
            'new_coordinator_key_id' => $this->remoteKey->remote_key_id,
            'new_coordinator_signature' => $cryptography->sign(
                $signatureInput,
                $this->remoteKeyMaterial['signing_private_key'],
            ),
        ];

        return $manifest;
    }

    /** @param  array<string, mixed>  $payload */
    private function inbox(
        array $payload,
        FederationMessageType $type = FederationMessageType::CoalitionManifest,
    ): FederationInboxMessage {
        return FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'sender_key_id' => $this->remoteKey->remote_key_id,
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
