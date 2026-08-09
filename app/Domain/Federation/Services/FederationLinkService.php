<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\Enums\FederationEndpointChangeStatus;
use App\Domain\Federation\Enums\FederationKeyRotationPhase;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\FederationFingerprint;
use App\Domain\Federation\Support\StrictJson;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationPeerKey;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FederationLinkService
{
    public function __construct(
        private readonly FederationTransport $transport,
        private readonly FederationOutboxService $outbox,
        private readonly AuditLogger $audit,
        private readonly FederationCryptography $cryptography,
        private readonly FederationIdentityService $identityService,
        private readonly FederationStoredEnvelopeReader $storedEnvelopes,
    ) {}

    public function discover(string $origin): FederationDiscoveryDocument
    {
        $this->assertLinkingEnabled();

        return $this->transport->discover(PeerOrigin::fromUrl($origin));
    }

    public function stagePeerRecoveryKey(FederationLink $link, int $actorUserId): FederationPeerKey
    {
        $this->assertLinkingEnabled();
        $currentLink = FederationLink::query()->findOrFail($link->id);

        if ($currentLink->status !== FederationLinkStatus::Suspended) {
            throw ValidationException::withMessages([
                'link' => 'Replacement keys can only be fetched for a suspended link.',
            ]);
        }

        $discovery = $this->transport->discover(PeerOrigin::fromUrl($currentLink->approved_origin));
        $this->assertDiscoveryKey($discovery);

        if (! hash_equals($currentLink->remote_installation_id, $discovery->installationId)
            || $discovery->ownershipEpoch < (int) $currentLink->remote_ownership_epoch) {
            throw ValidationException::withMessages([
                'link' => 'The recovery discovery document does not match the pinned installation.',
            ]);
        }

        $latestGeneration = (int) $currentLink->peerKeys()->max('generation');
        $knownKeyId = $currentLink->peerKeys()
            ->where('remote_key_id', $discovery->currentKey['key_id'])
            ->exists();

        if ((int) $discovery->currentKey['generation'] < $latestGeneration
            || (! $knownKeyId && (int) $discovery->currentKey['generation'] <= $latestGeneration)) {
            throw ValidationException::withMessages([
                'link' => 'The recovery discovery document advertises an older key generation.',
            ]);
        }

        $staged = DB::transaction(function () use ($currentLink, $discovery): FederationPeerKey {
            $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($currentLink->id);

            if ($lockedLink->status !== FederationLinkStatus::Suspended
                || ! hash_equals($lockedLink->approved_origin, $discovery->origin)) {
                throw ValidationException::withMessages([
                    'link' => 'The suspended link changed while its recovery key was fetched.',
                ]);
            }

            $key = $this->storePeerKey(
                $lockedLink,
                $discovery->currentKey,
                FederationKeyStatus::Pending,
            );
            $lockedLink->forceFill([
                'remote_display_name' => $discovery->displayName,
                'remote_ownership_epoch' => $discovery->ownershipEpoch,
                'suspension_reason_code' => 'key_reapproval_required',
            ])->save();

            return $key;
        }, attempts: 5);

        $this->audit->success('federation', 'link.recovery_key_staged', $staged, [
            'link_id' => $currentLink->id,
            'key_id' => $staged->remote_key_id,
            'generation' => (int) $staged->generation,
            'actor_id' => $actorUserId,
        ]);

        return $staged;
    }

    public function begin(string $origin, int $actorUserId, bool $fingerprintsConfirmed): FederationLink
    {
        $this->assertLinkingEnabled();

        if (! $fingerprintsConfirmed) {
            throw ValidationException::withMessages([
                'fingerprints_confirmed' => 'Confirm both fingerprints through an out-of-band channel.',
            ]);
        }

        $peerOrigin = PeerOrigin::fromUrl($origin);
        $discovery = $this->transport->discover($peerOrigin);
        $this->assertDiscoveryKey($discovery);
        $negotiatedResources = $this->assertDiscoveryCompatibility($discovery);
        $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();

        if (hash_equals($identity->id, $discovery->installationId)) {
            throw ValidationException::withMessages(['origin' => 'An installation cannot link to itself.']);
        }

        try {
            $link = DB::transaction(function () use ($discovery, $identity, $actorUserId, $negotiatedResources): FederationLink {
                $existing = FederationLink::query()
                    ->where('remote_installation_id', $discovery->installationId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof FederationLink && ! $existing->status->isTerminal()) {
                    throw ValidationException::withMessages([
                        'origin' => 'A link workflow already exists for this installation.',
                    ]);
                }

                if ($existing instanceof FederationLink) {
                    $compromisedKeyReused = $existing->peerKeys()
                        ->where('remote_key_id', $discovery->currentKey['key_id'])
                        ->where('status', FederationKeyStatus::Compromised->value)
                        ->exists();

                    if ($compromisedKeyReused) {
                        throw ValidationException::withMessages([
                            'origin' => 'A key previously marked compromised cannot be used to relink.',
                        ]);
                    }

                    $existing->invitations()
                        ->where('status', FederationWorkflowStatus::Pending->value)
                        ->update([
                            'status' => FederationWorkflowStatus::Expired->value,
                            'pending_key' => null,
                            'updated_at' => now(),
                        ]);
                    $existing->forceFill([
                        'remote_display_name' => $discovery->displayName,
                        'approved_origin' => $discovery->origin,
                        'status' => FederationLinkStatus::PendingRemote,
                        'remote_ownership_epoch' => $discovery->ownershipEpoch,
                        'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                        'negotiated_resource_versions' => $negotiatedResources,
                        'suspension_reason_code' => null,
                        'active_at' => null,
                        'suspended_at' => null,
                    ])->save();
                    $existing->peerKeys()
                        ->where('remote_key_id', '!=', $discovery->currentKey['key_id'])
                        ->whereNotIn('status', [
                            FederationKeyStatus::Compromised->value,
                            FederationKeyStatus::Retired->value,
                        ])
                        ->update([
                            'status' => FederationKeyStatus::Retired->value,
                            'retired_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $link = $existing;
                } else {
                    $link = FederationLink::query()->create([
                        'id' => (string) Str::ulid(),
                        'remote_installation_id' => $discovery->installationId,
                        'remote_display_name' => $discovery->displayName,
                        'approved_origin' => $discovery->origin,
                        'status' => FederationLinkStatus::PendingRemote,
                        'remote_ownership_epoch' => $discovery->ownershipEpoch,
                        'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                        'negotiated_resource_versions' => $negotiatedResources,
                    ]);
                }
                $this->storePeerKey($link, $discovery->currentKey, FederationKeyStatus::Active, now());
                $token = Base64Url::encode(random_bytes(32));
                $expiresAt = CarbonImmutable::now('UTC')->addHours(
                    max((int) config('federation.invitation_expiry_hours', 24), 1)
                );
                $invitation = FederationLinkInvitation::query()->create([
                    'id' => (string) Str::ulid(),
                    'federation_link_id' => $link->id,
                    'direction' => 'outbound',
                    'peer_origin' => $discovery->origin,
                    'peer_installation_id' => $discovery->installationId,
                    'token_hash' => hash('sha256', $token),
                    'status' => FederationWorkflowStatus::Pending,
                    'pending_key' => 1,
                    'discovery_snapshot' => $discovery->toArray(),
                    'created_by' => $actorUserId,
                    'expires_at' => $expiresAt,
                ]);
                $key = $identity->activeKey;
                $this->outbox->queue(
                    link: $link,
                    type: FederationMessageType::LinkRequest,
                    payload: [
                        'invitation_id' => $invitation->id,
                        'invitation_token' => $token,
                        'source_origin' => $identity->origin,
                        'source_display_name' => $identity->display_name,
                        'source_installation_id' => $identity->id,
                        'source_ownership_epoch' => (int) $identity->ownership_epoch,
                        'source_key' => $this->localKeyPayload($key),
                        'supported_protocol_versions' => $this->localProtocolVersions(),
                        'resource_schemas' => $this->localResourceSchemas(),
                        'expires_at' => $expiresAt->toIso8601String(),
                    ],
                    expiresAt: $expiresAt,
                    includeHandshakeKey: true,
                );

                return $link;
            }, attempts: 5);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'origin' => 'A link workflow already exists for this installation or origin.',
            ]);
        }

        $this->audit->success('federation', 'link.requested', $link, [
            'link_id' => $link->id,
            'remote_installation_id' => $link->remote_installation_id,
        ]);

        return $link;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveRequest(FederationInboxMessage $message, array $payload): FederationLink
    {
        return DB::transaction(function () use ($message, $payload): FederationLink {
            $origin = PeerOrigin::fromUrl($payload['source_origin'])->value();
            $invitationExpiresAt = CarbonImmutable::parse($payload['expires_at']);

            if ($invitationExpiresAt->isPast()
                || $invitationExpiresAt->isAfter(CarbonImmutable::now('UTC')->addHours(
                    max((int) config('federation.invitation_expiry_hours', 24), 1)
                ))
                || $invitationExpiresAt->isAfter(CarbonImmutable::instance($message->expires_at))) {
                throw ValidationException::withMessages([
                    'invitation' => 'The link invitation expiry is invalid.',
                ]);
            }

            $negotiatedResources = $this->assertVersionCompatibility(
                $payload['supported_protocol_versions'],
                $payload['resource_schemas'],
                'link',
            );
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->first();

            if ($link instanceof FederationLink) {
                if ($link->status === FederationLinkStatus::Active) {
                    return $link;
                }

                throw ValidationException::withMessages([
                    'link' => 'A local administrator must explicitly start any replacement link workflow.',
                ]);
            }

            $link = FederationLink::query()->create([
                'id' => (string) Str::ulid(),
                'remote_installation_id' => $message->sender_installation_id,
                'remote_display_name' => $payload['source_display_name'],
                'approved_origin' => $origin,
                'status' => FederationLinkStatus::PendingLocal,
                'remote_ownership_epoch' => $payload['source_ownership_epoch'],
                'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                'negotiated_resource_versions' => $negotiatedResources,
            ]);
            $link->forceFill([
                'remote_display_name' => $payload['source_display_name'],
                'approved_origin' => $origin,
                'status' => FederationLinkStatus::PendingLocal,
                'remote_ownership_epoch' => $payload['source_ownership_epoch'],
                'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                'negotiated_resource_versions' => $negotiatedResources,
            ])->save();
            $this->storePeerKey($link, $payload['source_key'], FederationKeyStatus::Pending);

            FederationLinkInvitation::query()->updateOrCreate(
                ['id' => $payload['invitation_id']],
                [
                    'federation_link_id' => $link->id,
                    'direction' => 'inbound',
                    'peer_origin' => $origin,
                    'peer_installation_id' => $message->sender_installation_id,
                    'token_hash' => hash('sha256', $payload['invitation_token']),
                    'status' => FederationWorkflowStatus::Pending,
                    'pending_key' => 1,
                    'discovery_snapshot' => [
                        'installation_id' => $message->sender_installation_id,
                        'origin' => $origin,
                        'display_name' => $payload['source_display_name'],
                        'ownership_epoch' => $payload['source_ownership_epoch'],
                        'current_key' => $payload['source_key'],
                        'supported_protocol_versions' => $payload['supported_protocol_versions'],
                        'resource_schemas' => $payload['resource_schemas'],
                    ],
                    'source_message_id' => $message->message_id,
                    'expires_at' => $invitationExpiresAt,
                ]
            );

            return $link;
        }, attempts: 5);
    }

    public function approveIncoming(FederationLinkInvitation $invitation, int $actorUserId): FederationLink
    {
        $link = DB::transaction(function () use ($invitation, $actorUserId): FederationLink {
            $pending = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $this->assertPendingInvitation($pending, 'inbound');
            $payload = $this->sourcePayload($pending);
            $link = FederationLink::query()->lockForUpdate()->findOrFail($pending->federation_link_id);
            $peerKey = $link->peerKeys()->where('remote_key_id', $payload['source_key']['key_id'])->firstOrFail();
            $peerKey->forceFill([
                'status' => FederationKeyStatus::Active,
                'approved_at' => now(),
            ])->save();
            $pending->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
            $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::LinkAcceptance,
                payload: [
                    'invitation_id' => $pending->id,
                    'invitation_token' => $payload['invitation_token'],
                    'source_installation_id' => $link->remote_installation_id,
                    'recipient_origin' => $identity->origin,
                    'recipient_display_name' => $identity->display_name,
                    'recipient_installation_id' => $identity->id,
                    'recipient_ownership_epoch' => (int) $identity->ownership_epoch,
                    'recipient_key' => $this->localKeyPayload($identity->activeKey),
                    'supported_protocol_versions' => $this->localProtocolVersions(),
                    'resource_schemas' => $this->localResourceSchemas(),
                    'accepted_at' => now()->utc()->toIso8601String(),
                ],
                expiresAt: CarbonImmutable::instance($pending->expires_at),
            );

            return $link;
        }, attempts: 5);

        $this->audit->success('federation', 'link.incoming_approved', $link, [
            'link_id' => $link->id,
            'remote_installation_id' => $link->remote_installation_id,
        ]);

        return $link;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveAcceptance(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $invitation = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($payload['invitation_id']);
            $this->assertToken($invitation, $payload['invitation_token']);
            $link = FederationLink::query()->lockForUpdate()->findOrFail($invitation->federation_link_id);
            $identity = FederationIdentity::query()->firstOrFail();
            $negotiatedResources = $this->assertVersionCompatibility(
                $payload['supported_protocol_versions'],
                $payload['resource_schemas'],
                'link',
            );
            $acceptedOrigin = PeerOrigin::fromUrl($payload['recipient_origin'])->value();

            if ($invitation->direction !== 'outbound'
                || $invitation->status !== FederationWorkflowStatus::Pending
                || $invitation->expires_at->isPast()
                || $link->status !== FederationLinkStatus::PendingRemote
                || ! hash_equals($message->sender_installation_id, $payload['recipient_installation_id'])
                || ! hash_equals($identity->id, $payload['source_installation_id'])
                || ! hash_equals($link->remote_installation_id, $payload['recipient_installation_id'])
                || ! hash_equals((string) $invitation->peer_installation_id, $message->sender_installation_id)
                || ! hash_equals($invitation->peer_origin, $acceptedOrigin)) {
                throw ValidationException::withMessages(['link' => 'Link acceptance installation does not match.']);
            }

            $knownKey = $link->peerKeys()->latest('generation')->first();

            if (! $knownKey instanceof FederationPeerKey
                || ! hash_equals($knownKey->remote_key_id, $payload['recipient_key']['key_id'])
                || ! hash_equals($knownKey->signing_public_key, $payload['recipient_key']['signing_public_key'])
                || ! hash_equals($knownKey->box_public_key, $payload['recipient_key']['box_public_key'])) {
                $link->forceFill([
                    'status' => FederationLinkStatus::Suspended,
                    'suspension_reason_code' => 'unapproved_key_change',
                    'suspended_at' => now(),
                ])->save();

                return;
            }

            $this->storePeerKey($link, $payload['recipient_key'], FederationKeyStatus::Active, now());
            $link->forceFill([
                'status' => FederationLinkStatus::PendingLocal,
                'approved_origin' => $acceptedOrigin,
                'remote_display_name' => $payload['recipient_display_name'],
                'remote_ownership_epoch' => $payload['recipient_ownership_epoch'],
                'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                'negotiated_resource_versions' => $negotiatedResources,
            ])->save();
            $invitation->forceFill(['source_message_id' => $message->message_id])->save();
        }, attempts: 5);
    }

    public function finalizeOutgoing(FederationLinkInvitation $invitation, int $actorUserId): FederationLink
    {
        $link = DB::transaction(function () use ($invitation, $actorUserId): FederationLink {
            $pending = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $this->assertPendingInvitation($pending, 'outbound', allowApproved: true);
            $acceptance = $this->sourcePayload($pending);
            $this->assertToken($pending, $acceptance['invitation_token']);
            $link = FederationLink::query()->lockForUpdate()->findOrFail($pending->federation_link_id);
            $identity = FederationIdentity::query()->firstOrFail();

            if ($link->status !== FederationLinkStatus::PendingLocal) {
                throw ValidationException::withMessages([
                    'link' => 'The peer acceptance has not been validated for final activation.',
                ]);
            }

            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::LinkActivation,
                payload: [
                    'invitation_id' => $pending->id,
                    'invitation_token' => $acceptance['invitation_token'],
                    'link_id' => $link->id,
                    'source_installation_id' => $identity->id,
                    'recipient_installation_id' => $link->remote_installation_id,
                    'activated_at' => now()->utc()->toIso8601String(),
                    'acknowledgment' => false,
                ],
                expiresAt: CarbonImmutable::instance($pending->expires_at),
            );
            $pending->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();

            return $link;
        }, attempts: 5);

        $this->audit->success('federation', 'link.activation_approved', $link, [
            'link_id' => $link->id,
            'remote_installation_id' => $link->remote_installation_id,
        ]);

        return $link;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveActivation(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $invitation = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($payload['invitation_id']);
            $this->assertToken($invitation, $payload['invitation_token']);
            $link = FederationLink::query()->lockForUpdate()->findOrFail($invitation->federation_link_id);
            $identity = FederationIdentity::query()->firstOrFail();
            $expectedDirection = $payload['acknowledgment'] ? 'outbound' : 'inbound';

            if ($invitation->status === FederationWorkflowStatus::Completed) {
                if ($link->status === FederationLinkStatus::Active) {
                    return;
                }

                throw ValidationException::withMessages([
                    'link' => 'A completed activation cannot change the current link state.',
                ]);
            }

            if ($invitation->direction !== $expectedDirection
                || $invitation->status !== FederationWorkflowStatus::Approved
                || $invitation->expires_at->isPast()
                || $link->status !== FederationLinkStatus::PendingLocal
                || ! hash_equals($message->sender_installation_id, $payload['source_installation_id'])
                || ! hash_equals($identity->id, $payload['recipient_installation_id'])
                || ! hash_equals($link->remote_installation_id, $message->sender_installation_id)
                || ! hash_equals((string) $invitation->peer_installation_id, $message->sender_installation_id)) {
                throw ValidationException::withMessages([
                    'link' => 'The link activation does not match this invitation workflow.',
                ]);
            }

            $link->forceFill([
                'status' => FederationLinkStatus::Active,
                'active_at' => $link->active_at ?? now(),
                'suspended_at' => null,
                'suspension_reason_code' => null,
                'last_contact_at' => now(),
            ])->save();
            $invitation->forceFill([
                'status' => FederationWorkflowStatus::Completed,
                'pending_key' => null,
                'consumed_at' => now(),
            ])->save();

            if (! $payload['acknowledgment']) {
                $this->outbox->queue(
                    link: $link,
                    type: FederationMessageType::LinkActivation,
                    payload: [
                        ...$payload,
                        'link_id' => $link->id,
                        'source_installation_id' => $identity->id,
                        'recipient_installation_id' => $link->remote_installation_id,
                        'activated_at' => now()->utc()->toIso8601String(),
                        'acknowledgment' => true,
                    ],
                    expiresAt: CarbonImmutable::instance($invitation->expires_at),
                );
            }
        }, attempts: 5);
    }

    public function proposeEndpointChange(
        FederationLink $link,
        string $newOrigin,
        int $actorUserId,
    ): FederationLinkInvitation {
        $this->assertLinkingEnabled();
        $newOrigin = PeerOrigin::fromUrl($newOrigin)->value();
        $expiresAt = CarbonImmutable::now('UTC')->addHours(
            max((int) config('federation.invitation_expiry_hours', 24), 1)
        );
        $proposalId = (string) Str::ulid();

        $proposal = DB::transaction(function () use (
            $link,
            $newOrigin,
            $actorUserId,
            $expiresAt,
            $proposalId,
        ): FederationLinkInvitation {
            $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($link->id);
            $this->assertEndpointLink($lockedLink);

            if (hash_equals($lockedLink->approved_origin, $newOrigin)) {
                throw ValidationException::withMessages([
                    'origin' => 'The proposed origin is already pinned for this link.',
                ]);
            }

            $alreadyPending = FederationLinkInvitation::query()
                ->where('federation_link_id', $lockedLink->id)
                ->where('direction', 'endpoint_outbound')
                ->whereIn('status', [
                    FederationWorkflowStatus::Pending->value,
                    FederationWorkflowStatus::Approved->value,
                ])
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'origin' => 'An endpoint change is already awaiting approval.',
                ]);
            }

            $payload = $this->endpointPayload(
                proposalId: $proposalId,
                link: $lockedLink,
                newOrigin: $newOrigin,
                status: FederationEndpointChangeStatus::Proposed,
                expiresAt: $expiresAt,
            );
            $proposal = FederationLinkInvitation::query()->create([
                'id' => $proposalId,
                'federation_link_id' => $lockedLink->id,
                'direction' => 'endpoint_outbound',
                'peer_origin' => $newOrigin,
                'peer_installation_id' => $lockedLink->remote_installation_id,
                'token_hash' => hash('sha256', Base64Url::encode(random_bytes(32))),
                'status' => FederationWorkflowStatus::Pending,
                'pending_key' => 1,
                'discovery_snapshot' => ['payload' => $payload],
                'created_by' => $actorUserId,
                'expires_at' => $expiresAt,
            ]);
            $this->outbox->queue(
                link: $lockedLink,
                type: FederationMessageType::EndpointChange,
                payload: $payload,
                expiresAt: $expiresAt,
            );

            return $proposal;
        }, attempts: 5);

        $this->audit->success('federation', 'link.endpoint_change_proposed', $proposal, [
            'proposal_id' => $proposal->id,
            'link_id' => $link->id,
            'remote_installation_id' => $link->remote_installation_id,
            'actor_id' => $actorUserId,
        ]);

        return $proposal;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveEndpointChange(FederationInboxMessage $message, array $payload): void
    {
        $status = FederationEndpointChangeStatus::from($payload['status']);
        $this->assertEndpointPayload($message, $payload);
        $oldOrigin = PeerOrigin::fromUrl($payload['old_origin'])->value();
        $newOrigin = PeerOrigin::fromUrl($payload['new_origin'])->value();

        DB::transaction(function () use ($message, $payload, $status, $oldOrigin, $newOrigin): void {
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = FederationIdentity::query()->lockForUpdate()->firstOrFail();
            $proposal = FederationLinkInvitation::query()->lockForUpdate()->find($payload['proposal_id']);
            $isTargetSide = in_array($status, [
                FederationEndpointChangeStatus::Proposed,
                FederationEndpointChangeStatus::Activated,
            ], true);
            $expectedInstallationId = $isTargetSide ? $identity->id : $link->remote_installation_id;
            $expectedOwnershipEpoch = $isTargetSide
                ? (int) $identity->ownership_epoch
                : (int) $link->remote_ownership_epoch;
            $expectedOldOrigin = $isTargetSide ? $identity->origin : $link->approved_origin;

            if ($proposal instanceof FederationLinkInvitation) {
                if (! hash_equals((string) $proposal->federation_link_id, $link->id)
                    || ! hash_equals((string) $proposal->peer_installation_id, $message->sender_installation_id)) {
                    throw ValidationException::withMessages([
                        'origin' => 'The endpoint proposal belongs to a different peer link.',
                    ]);
                }

                $storedPayload = data_get($proposal->discovery_snapshot, 'payload');

                if (is_array($storedPayload)
                    && (! hash_equals((string) $storedPayload['old_origin'], $oldOrigin)
                        || ! hash_equals((string) $storedPayload['new_origin'], $newOrigin))) {
                    throw ValidationException::withMessages(['origin' => 'The endpoint proposal changed.']);
                }

                if ($proposal->status === FederationWorkflowStatus::Completed) {
                    return;
                }
            }

            if (! hash_equals($expectedInstallationId, $payload['installation_id'])
                || $expectedOwnershipEpoch !== (int) $payload['ownership_epoch']
                || ! hash_equals($expectedOldOrigin, $oldOrigin)) {
                throw ValidationException::withMessages([
                    'origin' => 'The endpoint proposal does not match the installation that owns this origin.',
                ]);
            }

            if ($status === FederationEndpointChangeStatus::Proposed) {
                if ($proposal instanceof FederationLinkInvitation) {
                    return;
                }

                FederationLinkInvitation::query()->create([
                    'id' => $payload['proposal_id'],
                    'federation_link_id' => $link->id,
                    'direction' => 'endpoint_inbound',
                    'peer_origin' => $newOrigin,
                    'peer_installation_id' => $message->sender_installation_id,
                    'token_hash' => hash('sha256', CanonicalJson::encode($payload)),
                    'status' => FederationWorkflowStatus::Pending,
                    'pending_key' => 1,
                    'discovery_snapshot' => ['payload' => $payload],
                    'source_message_id' => $message->message_id,
                    'expires_at' => CarbonImmutable::parse($payload['expires_at']),
                ]);

                return;
            }

            if (! $proposal instanceof FederationLinkInvitation
                || ! in_array($proposal->direction, ['endpoint_outbound', 'endpoint_inbound'], true)) {
                throw ValidationException::withMessages(['origin' => 'The endpoint proposal is unknown.']);
            }

            if ($status === FederationEndpointChangeStatus::Approved) {
                if ($proposal->direction !== 'endpoint_outbound'
                    || ! in_array($proposal->status, [
                        FederationWorkflowStatus::Pending,
                        FederationWorkflowStatus::Approved,
                    ], true)) {
                    throw ValidationException::withMessages(['origin' => 'The endpoint proposal is not awaiting approval.']);
                }
                $proposal->forceFill([
                    'status' => FederationWorkflowStatus::Approved,
                    'pending_key' => null,
                    'source_message_id' => $message->message_id,
                    'reviewed_at' => now(),
                ])->save();

                return;
            }

            if ($status === FederationEndpointChangeStatus::Rejected) {
                if ($proposal->direction !== 'endpoint_outbound'
                    || ! in_array($proposal->status, [
                        FederationWorkflowStatus::Pending,
                        FederationWorkflowStatus::Approved,
                    ], true)) {
                    throw ValidationException::withMessages(['origin' => 'The endpoint proposal cannot be rejected.']);
                }

                $proposal->forceFill([
                    'status' => FederationWorkflowStatus::Rejected,
                    'pending_key' => null,
                    'source_message_id' => $message->message_id,
                    'reviewed_at' => now(),
                ])->save();

                return;
            }

            if ($proposal->direction !== 'endpoint_inbound'
                || $proposal->status !== FederationWorkflowStatus::Approved) {
                throw ValidationException::withMessages(['origin' => 'The endpoint proposal was not approved.']);
            }

            $proposal->forceFill([
                'status' => FederationWorkflowStatus::Completed,
                'pending_key' => null,
                'source_message_id' => $message->message_id,
                'consumed_at' => now(),
            ])->save();
        }, attempts: 5);
    }

    public function approveEndpointChange(FederationLinkInvitation $proposal, int $actorUserId): void
    {
        $this->respondToEndpointChange($proposal, FederationEndpointChangeStatus::Approved, $actorUserId);
    }

    public function rejectEndpointChange(
        FederationLinkInvitation $proposal,
        int $actorUserId,
        string $reasonCode = 'rejected_by_administrator',
    ): void {
        $this->respondToEndpointChange(
            $proposal,
            FederationEndpointChangeStatus::Rejected,
            $actorUserId,
            $reasonCode,
        );
    }

    public function activateEndpointChange(FederationLinkInvitation $proposal, int $actorUserId): FederationLink
    {
        $link = DB::transaction(function () use ($proposal, $actorUserId): FederationLink {
            $lockedProposal = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($lockedProposal->direction !== 'endpoint_outbound'
                || $lockedProposal->status !== FederationWorkflowStatus::Approved
                || $lockedProposal->expires_at->isPast()) {
                throw ValidationException::withMessages(['origin' => 'The endpoint change is not ready for activation.']);
            }

            $payload = $this->invitationPayload($lockedProposal);
            $payload['status'] = FederationEndpointChangeStatus::Activated->value;
            $link = FederationLink::query()->lockForUpdate()->findOrFail($lockedProposal->federation_link_id);
            $this->assertEndpointLink($link);
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::EndpointChange,
                payload: $payload,
                expiresAt: CarbonImmutable::instance($lockedProposal->expires_at),
            );
            $link->forceFill([
                'approved_origin' => $payload['new_origin'],
                'last_contact_at' => now(),
            ])->save();
            $lockedProposal->forceFill([
                'status' => FederationWorkflowStatus::Completed,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
                'consumed_at' => now(),
            ])->save();

            return $link;
        }, attempts: 5);

        $this->audit->success('federation', 'link.endpoint_change_activated', $link, [
            'proposal_id' => $proposal->id,
            'link_id' => $link->id,
            'actor_id' => $actorUserId,
        ]);

        return $link;
    }

    public function initiateKeyRotation(int $actorUserId): FederationIdentityKey
    {
        $newKey = $this->identityService->initiateRoutineRotation();
        $this->broadcastKeyRotation($newKey, $actorUserId);

        return $newKey;
    }

    public function transferOwnership(int $actorUserId): FederationIdentityKey
    {
        $newKey = $this->identityService->transferOwnership();
        $this->broadcastKeyRotation($newKey, $actorUserId);

        return $newKey;
    }

    public function broadcastKeyRotation(FederationIdentityKey $newKey, int $actorUserId = 0): void
    {
        $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();
        $newKey = $identity->keys()->findOrFail($newKey->id);
        $base = $this->rotationBase($newKey);

        if ($newKey->status !== FederationKeyStatus::Pending) {
            throw ValidationException::withMessages(['rotation' => 'Only a pending key can be proposed.']);
        }

        $links = FederationLink::query()
            ->where('status', FederationLinkStatus::Active->value)
            ->with('peerKeys')
            ->get();

        foreach ($links as $link) {
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::KeyRotation,
                payload: $this->rotationPayload($identity, $newKey, $base, FederationKeyRotationPhase::Proposed),
                expiresAt: CarbonImmutable::now('UTC')->addDays(7),
            );
        }

        $this->audit->success('federation', 'identity.rotation_proposed', $newKey, [
            'key_id' => $newKey->id,
            'generation' => (int) $newKey->generation,
            'peer_count' => $links->count(),
            'actor_id' => $actorUserId,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveKeyRotation(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $this->processKeyRotation($message, $payload);
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    private function processKeyRotation(FederationInboxMessage $message, array $payload): void
    {
        $identity = FederationIdentity::query()->firstOrFail();
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->lockForUpdate()
            ->firstOrFail();
        [$phase, $base, $baseStatement] = $this->rotationStatement($payload['statement']);
        $this->assertRotationPayload($message, $payload, $base);
        $newKey = $payload['new_key'];

        if ($phase === FederationKeyRotationPhase::Acknowledged) {
            if (! hash_equals($payload['installation_id'], $identity->id)) {
                throw ValidationException::withMessages(['rotation' => 'Rotation acknowledgment subject is invalid.']);
            }
            $this->recordRotationAcknowledgment(
                $identity,
                $newKey['key_id'],
                $message->sender_installation_id,
                $payload,
                $baseStatement,
            );

            return;
        }

        if (! hash_equals($payload['installation_id'], $message->sender_installation_id)) {
            throw ValidationException::withMessages(['rotation' => 'Rotation sender does not match its subject.']);
        }

        $this->assertKeyPayload($newKey);

        if ($phase === FederationKeyRotationPhase::Reapproved) {
            $approved = $link->peerKeys()
                ->where('remote_key_id', $newKey['key_id'])
                ->where('status', FederationKeyStatus::Active->value)
                ->first();

            if (! $approved instanceof FederationPeerKey
                || $approved->approved_at === null
                || ($link->suspended_at !== null && $approved->approved_at->isBefore($link->suspended_at))
                || ! $this->cryptography->verify(
                    $baseStatement,
                    $payload['new_signature'],
                    $approved->signing_public_key,
                )) {
                throw ValidationException::withMessages([
                    'rotation' => 'The replacement peer key has not been approved out of band.',
                ]);
            }

            $link->forceFill([
                'status' => FederationLinkStatus::Active,
                'remote_ownership_epoch' => $payload['ownership_epoch'],
                'suspension_reason_code' => null,
                'suspended_at' => null,
                'last_contact_at' => now(),
            ])->save();

            return;
        }

        $knownOldKey = $link->peerKeys()->where('remote_key_id', $payload['old_key_id'])->first();

        $trustedOldKey = $knownOldKey instanceof FederationPeerKey
            && $knownOldKey->status !== FederationKeyStatus::Compromised;

        if ($trustedOldKey) {
            $this->assertRotationSignatures($payload, $baseStatement, $knownOldKey);
        }

        if ($phase === FederationKeyRotationPhase::Proposed) {
            if ($trustedOldKey
                && (int) $newKey['generation'] > (int) $knownOldKey->generation) {
                $this->outbox->queue(
                    link: $link,
                    type: FederationMessageType::KeyRotation,
                    payload: $this->rotationPayload(
                        $identity,
                        null,
                        $base,
                        FederationKeyRotationPhase::Acknowledged,
                        $payload,
                    ),
                    expiresAt: CarbonImmutable::now('UTC')->addDays(7),
                );
                $this->storePeerKey($link, $newKey, FederationKeyStatus::Pending);

                return;
            }

            if (! $trustedOldKey) {
                $this->storePeerKey($link, $newKey, FederationKeyStatus::Pending);
                $link->forceFill([
                    'status' => FederationLinkStatus::Suspended,
                    'suspension_reason_code' => 'key_reapproval_required',
                    'suspended_at' => now(),
                ])->save();

                return;
            }

            throw ValidationException::withMessages(['rotation' => 'The peer key generation is not newer.']);
        }

        $pending = $link->peerKeys()->where('remote_key_id', $newKey['key_id'])->first();

        if (! $pending instanceof FederationPeerKey) {
            throw ValidationException::withMessages(['rotation' => 'The rotated peer key is unknown.']);
        }

        if ($phase === FederationKeyRotationPhase::Activated) {
            $pending->forceFill([
                'status' => FederationKeyStatus::Active,
                'approved_at' => $pending->approved_at ?? now(),
            ])->save();
            $link->peerKeys()
                ->where('id', '!=', $pending->id)
                ->where('generation', '<', $pending->generation)
                ->where('status', FederationKeyStatus::Active->value)
                ->update([
                    'status' => FederationKeyStatus::Retiring->value,
                    'retired_at' => null,
                    'updated_at' => now(),
                ]);
            $link->forceFill([
                'remote_ownership_epoch' => $payload['ownership_epoch'],
                'last_contact_at' => now(),
            ])->save();

            return;
        }

    }

    public function activateKeyRotation(FederationIdentityKey $newKey, int $actorUserId): FederationIdentityKey
    {
        $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();
        $pending = $identity->keys()->findOrFail($newKey->id);

        if ($pending->status !== FederationKeyStatus::Pending) {
            throw ValidationException::withMessages(['rotation' => 'This key is not pending activation.']);
        }

        $metadata = $this->rotationMetadata($pending);
        $activePeerIds = FederationLink::query()
            ->where('status', FederationLinkStatus::Active->value)
            ->pluck('remote_installation_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $acknowledged = array_map('strval', (array) ($metadata['acknowledged_peers'] ?? []));

        if (array_diff($activePeerIds, $acknowledged) !== []) {
            throw ValidationException::withMessages([
                'rotation' => 'Every active peer must acknowledge the new key before activation.',
            ]);
        }

        $activated = $this->identityService->activateRotation($pending);
        $base = $this->rotationBase($activated);

        foreach (FederationLink::query()->where('status', FederationLinkStatus::Active->value)->get() as $link) {
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::KeyRotation,
                payload: $this->rotationPayload(
                    $identity,
                    $activated,
                    $base,
                    FederationKeyRotationPhase::Activated,
                ),
                expiresAt: CarbonImmutable::now('UTC')->addDays(7),
            );
        }

        $this->audit->success('federation', 'identity.rotation_activated_for_peers', $activated, [
            'key_id' => $activated->id,
            'actor_id' => $actorUserId,
        ]);

        return $activated;
    }

    public function reapprovePeerKey(
        FederationLink $link,
        array $keyPayload,
        int $actorUserId,
        bool $fingerprintsConfirmed,
    ): FederationPeerKey {
        if (! $fingerprintsConfirmed) {
            throw ValidationException::withMessages([
                'fingerprints_confirmed' => 'Confirm the replacement fingerprints out of band before reapproval.',
            ]);
        }

        $this->assertKeyPayload($keyPayload);
        $approved = DB::transaction(function () use ($link, $keyPayload): FederationPeerKey {
            $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($link->id);

            if ($lockedLink->status !== FederationLinkStatus::Suspended) {
                throw ValidationException::withMessages(['link' => 'Only a suspended link requires reapproval.']);
            }

            $peerKey = $this->storePeerKey($lockedLink, $keyPayload, FederationKeyStatus::Active, now());
            $lockedLink->forceFill([
                'suspension_reason_code' => 'reapproval_pending',
            ])->save();
            $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();
            $this->outbox->queue(
                link: $lockedLink,
                type: FederationMessageType::KeyRotation,
                payload: $this->recoveryPayload($identity),
                expiresAt: CarbonImmutable::now('UTC')->addDays(7),
            );

            return $peerKey;
        }, attempts: 5);

        $this->audit->success('federation', 'link.peer_key_reapproved', $approved, [
            'link_id' => $link->id,
            'key_id' => $approved->remote_key_id,
            'actor_id' => $actorUserId,
        ]);

        return $approved;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveSuspensionNotice(FederationInboxMessage $message, array $payload): void
    {
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->firstOrFail();

        if (! hash_equals($link->remote_installation_id, $message->sender_installation_id)) {
            throw ValidationException::withMessages(['link' => 'Suspension notice does not match the peer link.']);
        }

        $link->forceFill([
            'status' => FederationLinkStatus::Suspended,
            'suspension_reason_code' => Str::limit(Str::snake($payload['reason_code']), 64, ''),
            'suspended_at' => now(),
        ])->save();
    }

    public function suspend(FederationLink $link, string $reasonCode): FederationLink
    {
        $link->forceFill([
            'status' => FederationLinkStatus::Suspended,
            'suspension_reason_code' => Str::limit(Str::snake($reasonCode), 64, ''),
            'suspended_at' => now(),
        ])->save();

        $this->outbox->queue(
            link: $link,
            type: FederationMessageType::LinkSuspensionNotice,
            payload: [
                'link_id' => $link->id,
                'reason_code' => $link->suspension_reason_code,
                'suspended_at' => now()->utc()->toIso8601String(),
            ],
            expiresAt: CarbonImmutable::now('UTC')->addDay(),
        );

        return $link;
    }

    public function revoke(FederationLink $link, string $reasonCode): FederationLink
    {
        if ($link->status === FederationLinkStatus::Active) {
            $this->suspend($link, $reasonCode);
        }

        $link->forceFill([
            'status' => FederationLinkStatus::Revoked,
            'suspension_reason_code' => Str::limit(Str::snake($reasonCode), 64, ''),
            'revoked_at' => now(),
        ])->save();

        return $link;
    }

    /** @return array<string, mixed> */
    private function endpointPayload(
        string $proposalId,
        FederationLink $link,
        string $newOrigin,
        FederationEndpointChangeStatus $status,
        CarbonImmutable $expiresAt,
    ): array {
        return [
            'proposal_id' => $proposalId,
            'installation_id' => $link->remote_installation_id,
            'old_origin' => $link->approved_origin,
            'new_origin' => $newOrigin,
            'ownership_epoch' => (int) $link->remote_ownership_epoch,
            'status' => $status->value,
            'issued_at' => now()->utc()->toIso8601String(),
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function invitationPayload(FederationLinkInvitation $proposal): array
    {
        $payload = data_get($proposal->discovery_snapshot, 'payload');

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['origin' => 'The endpoint proposal payload is unavailable.']);
        }

        return $payload;
    }

    private function respondToEndpointChange(
        FederationLinkInvitation $proposal,
        FederationEndpointChangeStatus $status,
        int $actorUserId,
        ?string $reasonCode = null,
    ): void {
        DB::transaction(function () use ($proposal, $status, $actorUserId): void {
            $lockedProposal = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($lockedProposal->direction !== 'endpoint_inbound'
                || $lockedProposal->status !== FederationWorkflowStatus::Pending
                || $lockedProposal->expires_at->isPast()) {
                throw ValidationException::withMessages(['origin' => 'The endpoint proposal is no longer actionable.']);
            }

            $link = FederationLink::query()->lockForUpdate()->findOrFail($lockedProposal->federation_link_id);
            $this->assertEndpointLink($link);
            $payload = $this->invitationPayload($lockedProposal);
            $payload['status'] = $status->value;
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::EndpointChange,
                payload: $payload,
                expiresAt: CarbonImmutable::instance($lockedProposal->expires_at),
            );
            $lockedProposal->forceFill([
                'status' => $status === FederationEndpointChangeStatus::Approved
                    ? FederationWorkflowStatus::Approved
                    : FederationWorkflowStatus::Rejected,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
        }, attempts: 5);

        $this->audit->success('federation', 'link.endpoint_change_'.$status->value, $proposal, [
            'proposal_id' => $proposal->id,
            'actor_id' => $actorUserId,
            'reason_code' => $reasonCode,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function assertEndpointPayload(
        FederationInboxMessage $message,
        array $payload,
    ): void {
        foreach (['proposal_id', 'installation_id'] as $field) {
            if (! is_string($payload[$field]) || ! Str::isUlid($payload[$field])) {
                throw ValidationException::withMessages(['origin' => 'The endpoint proposal contains an invalid identifier.']);
            }
        }

        if (! is_int($payload['ownership_epoch']) || $payload['ownership_epoch'] < 1
            || ! is_string($payload['issued_at']) || ! is_string($payload['expires_at'])) {
            throw ValidationException::withMessages(['origin' => 'The endpoint proposal contains invalid metadata.']);
        }

        try {
            $expiresAt = CarbonImmutable::parse($payload['expires_at']);
            PeerOrigin::fromUrl($payload['old_origin']);
            PeerOrigin::fromUrl($payload['new_origin']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['origin' => 'The endpoint proposal contains invalid origin data.']);
        }

        if ($expiresAt->isPast()
            || $expiresAt->isAfter(CarbonImmutable::now('UTC')->addHours(
                max((int) config('federation.invitation_expiry_hours', 24), 1)
            ))
            || $expiresAt->isAfter(CarbonImmutable::instance($message->expires_at))) {
            throw ValidationException::withMessages(['origin' => 'The endpoint proposal has expired.']);
        }
    }

    private function assertEndpointLink(FederationLink $link): void
    {
        if ($link->status->isTerminal()) {
            throw ValidationException::withMessages(['link' => 'This link is terminal and must be relinked.']);
        }
    }

    /** @return array{0: FederationKeyRotationPhase, 1: array<string, mixed>, 2: string} */
    private function rotationStatement(string $statement): array
    {
        $decoded = StrictJson::decodeObject($statement);

        if (array_key_exists('phase', $decoded)) {
            StrictJson::rejectUnknown($decoded, ['phase', 'base_statement', 'acted_at']);
            StrictJson::requireProperties($decoded, ['phase', 'base_statement', 'acted_at']);

            if (! is_string($decoded['base_statement']) || ! is_string($decoded['acted_at'])) {
                throw ValidationException::withMessages(['rotation' => 'The rotation control statement is invalid.']);
            }

            return [
                FederationKeyRotationPhase::from($decoded['phase']),
                StrictJson::decodeObject($decoded['base_statement']),
                $decoded['base_statement'],
            ];
        }

        return [FederationKeyRotationPhase::Proposed, $decoded, $statement];
    }

    /** @return array<string, mixed> */
    private function rotationBase(FederationIdentityKey $key): array
    {
        $metadata = $this->rotationMetadata($key);

        if (! is_string($metadata['statement'] ?? null)) {
            throw ValidationException::withMessages(['rotation' => 'The key has no valid rotation statement.']);
        }

        return StrictJson::decodeObject($metadata['statement']);
    }

    /** @return array<string, mixed> */
    private function rotationMetadata(FederationIdentityKey $key): array
    {
        $metadata = StrictJson::decodeObject((string) $key->rotation_statement);
        StrictJson::requireProperties($metadata, ['statement', 'old_signature', 'new_signature']);

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function rotationPayload(
        FederationIdentity $identity,
        ?FederationIdentityKey $newKey,
        array $base,
        FederationKeyRotationPhase $phase,
        ?array $receivedPayload = null,
    ): array {
        $subject = $receivedPayload ?? [
            'installation_id' => $identity->id,
            'ownership_epoch' => (int) $identity->ownership_epoch,
            'old_key_id' => $base['old_key_id'],
            'new_key' => $newKey === null ? null : $this->localKeyPayload($newKey),
            'old_signature' => null,
            'new_signature' => null,
        ];

        if (! is_array($subject['new_key'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation key payload is unavailable.']);
        }

        if ($receivedPayload === null) {
            $metadata = $this->rotationMetadata($newKey);
            $subject['old_signature'] = $metadata['old_signature'];
            $subject['new_signature'] = $metadata['new_signature'];
        }

        $statement = CanonicalJson::encode($base);

        if ($phase !== FederationKeyRotationPhase::Proposed) {
            $statement = CanonicalJson::encode([
                'phase' => $phase->value,
                'base_statement' => $statement,
                'acted_at' => now()->utc()->toIso8601String(),
            ]);
        }

        return [
            'installation_id' => $subject['installation_id'],
            'ownership_epoch' => (int) $subject['ownership_epoch'],
            'old_key_id' => $subject['old_key_id'],
            'new_key' => $subject['new_key'],
            'statement' => $statement,
            'old_signature' => $subject['old_signature'],
            'new_signature' => $subject['new_signature'],
            'issued_at' => $base['issued_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function recoveryPayload(FederationIdentity $identity): array
    {
        $key = $identity->activeKey;

        if (! $key instanceof FederationIdentityKey || $key->signing_private_key === null) {
            throw ValidationException::withMessages([
                'rotation' => 'The local replacement key is not active for recovery.',
            ]);
        }

        $previousKeyId = $identity->keys()
            ->where('id', '!=', $key->id)
            ->whereIn('status', [
                FederationKeyStatus::Compromised->value,
                FederationKeyStatus::Retiring->value,
                FederationKeyStatus::Retired->value,
            ])
            ->latest('generation')
            ->value('id') ?? $key->id;
        $issuedAt = now()->utc()->toIso8601String();
        $base = [
            'installation_id' => $identity->id,
            'ownership_epoch' => (int) $identity->ownership_epoch,
            'old_key_id' => $previousKeyId,
            'new_key' => $this->localKeyPayload($key),
            'issued_at' => $issuedAt,
        ];
        $baseStatement = CanonicalJson::encode($base);

        return [
            'installation_id' => $identity->id,
            'ownership_epoch' => (int) $identity->ownership_epoch,
            'old_key_id' => $previousKeyId,
            'new_key' => $base['new_key'],
            'statement' => CanonicalJson::encode([
                'phase' => FederationKeyRotationPhase::Reapproved->value,
                'base_statement' => $baseStatement,
                'acted_at' => $issuedAt,
            ]),
            'old_signature' => '',
            'new_signature' => $this->cryptography->sign($baseStatement, $key->signing_private_key),
            'issued_at' => $issuedAt,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function assertRotationPayload(
        FederationInboxMessage $message,
        array $payload,
        array $base,
    ): void {
        $this->assertKeyPayload($payload['new_key']);

        foreach (['installation_id', 'old_key_id'] as $field) {
            if (! is_string($payload[$field]) || ! Str::isUlid($payload[$field])) {
                throw ValidationException::withMessages(['rotation' => 'The rotation payload contains an invalid identifier.']);
            }
        }

        if (! is_int($payload['ownership_epoch']) || $payload['ownership_epoch'] < 1
            || ! is_string($payload['issued_at'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation payload contains invalid metadata.']);
        }

        StrictJson::rejectUnknown($base, [
            'installation_id', 'ownership_epoch', 'old_key_id', 'new_key', 'issued_at',
        ]);
        StrictJson::requireProperties($base, [
            'installation_id', 'ownership_epoch', 'old_key_id', 'new_key', 'issued_at',
        ]);

        if (! is_string($base['installation_id'])
            || ! Str::isUlid($base['installation_id'])
            || ! is_int($base['ownership_epoch'])
            || $base['ownership_epoch'] < 1
            || ! is_string($base['old_key_id'])
            || ! Str::isUlid($base['old_key_id'])
            || ! is_string($base['issued_at'])
            || ! is_array($base['new_key'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation statement metadata is invalid.']);
        }

        $this->assertKeyPayload($base['new_key']);

        if (! hash_equals($base['installation_id'], $payload['installation_id'])
            || (int) $base['ownership_epoch'] !== (int) $payload['ownership_epoch']
            || ! hash_equals($base['old_key_id'], $payload['old_key_id'])
            || CanonicalJson::encode($base['new_key']) !== CanonicalJson::encode($payload['new_key'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation statement does not match its payload.']);
        }

        if (hash_equals($message->sender_installation_id, $payload['installation_id'])
            && ! hash_equals($message->sender_key_id, $payload['old_key_id'])
            && ! hash_equals($message->sender_key_id, $payload['new_key']['key_id'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation sender key is not part of the statement.']);
        }

    }

    /** @param  array<string, mixed>  $payload */
    private function assertRotationSignatures(
        array $payload,
        string $baseStatement,
        FederationPeerKey $oldKey,
    ): void {
        if (! $this->cryptography->verify($baseStatement, $payload['old_signature'], $oldKey->signing_public_key)
            || ! $this->cryptography->verify(
                $baseStatement,
                $payload['new_signature'],
                $payload['new_key']['signing_public_key'],
            )) {
            throw ValidationException::withMessages(['rotation' => 'The rotation signatures are invalid.']);
        }
    }

    /** @param  array<string, mixed>  $key */
    private function assertKeyPayload(array $key): void
    {
        StrictJson::rejectUnknown($key, [
            'key_id', 'generation', 'signing_public_key', 'box_public_key',
            'signing_fingerprint', 'box_fingerprint',
        ]);
        StrictJson::requireProperties($key, [
            'key_id', 'generation', 'signing_public_key', 'box_public_key',
            'signing_fingerprint', 'box_fingerprint',
        ]);

        if (! is_string($key['key_id']) || ! Str::isUlid($key['key_id'])
            || ! is_int($key['generation']) || $key['generation'] < 1) {
            throw ValidationException::withMessages(['rotation' => 'The rotation key identifier is invalid.']);
        }

        try {
            $signingPublicKey = Base64Url::decode($key['signing_public_key']);
            $boxPublicKey = Base64Url::decode($key['box_public_key']);
            $signingFingerprint = FederationFingerprint::signing($signingPublicKey);
            $boxFingerprint = FederationFingerprint::encryption($boxPublicKey);
        } catch (Throwable) {
            throw ValidationException::withMessages(['rotation' => 'The rotation key material is invalid.']);
        }

        if (! hash_equals($signingFingerprint, (string) $key['signing_fingerprint'])
            || ! hash_equals($boxFingerprint, (string) $key['box_fingerprint'])) {
            throw ValidationException::withMessages(['rotation' => 'The rotation key fingerprints are invalid.']);
        }
    }

    private function recordRotationAcknowledgment(
        FederationIdentity $identity,
        string $keyId,
        string $peerInstallationId,
        array $payload,
        string $baseStatement,
    ): void {
        DB::transaction(function () use (
            $identity,
            $keyId,
            $peerInstallationId,
            $payload,
            $baseStatement,
        ): void {
            $key = $identity->keys()->lockForUpdate()->findOrFail($keyId);

            if ($key->status !== FederationKeyStatus::Pending) {
                throw ValidationException::withMessages(['rotation' => 'The acknowledged key is not pending.']);
            }

            $metadata = $this->rotationMetadata($key);

            if (! hash_equals((string) $metadata['statement'], $baseStatement)
                || ! hash_equals((string) $metadata['old_signature'], $payload['old_signature'])
                || ! hash_equals((string) $metadata['new_signature'], $payload['new_signature'])
                || CanonicalJson::encode($payload['new_key']) !== CanonicalJson::encode($this->localKeyPayload($key))) {
                throw ValidationException::withMessages([
                    'rotation' => 'The acknowledgment does not match the pending local rotation.',
                ]);
            }

            $peers = array_map('strval', (array) ($metadata['acknowledged_peers'] ?? []));
            $peers[] = $peerInstallationId;
            $metadata['acknowledged_peers'] = array_values(array_unique($peers));
            $key->forceFill(['rotation_statement' => CanonicalJson::encode($metadata)])->save();
        }, attempts: 5);
    }

    private function assertLinkingEnabled(): void
    {
        if (! (bool) config('federation.enabled', false)
            || ! (bool) config('federation.features.linking', false)) {
            throw ValidationException::withMessages(['federation' => 'Federation linking is disabled.']);
        }
    }

    private function assertDiscoveryKey(FederationDiscoveryDocument $discovery): void
    {
        try {
            $signing = FederationFingerprint::signing(Base64Url::decode($discovery->currentKey['signing_public_key']));
            $box = FederationFingerprint::encryption(Base64Url::decode($discovery->currentKey['box_public_key']));
        } catch (Throwable) {
            throw ValidationException::withMessages(['origin' => 'Peer discovery returned invalid key material.']);
        }

        if (! hash_equals($signing, $discovery->currentKey['signing_fingerprint'])
            || ! hash_equals($box, $discovery->currentKey['box_fingerprint'])) {
            throw ValidationException::withMessages(['origin' => 'Peer discovery fingerprints do not match its keys.']);
        }
    }

    /** @return array<string, list<string>> */
    private function assertDiscoveryCompatibility(FederationDiscoveryDocument $discovery): array
    {
        return $this->assertVersionCompatibility(
            $discovery->protocolVersions,
            $discovery->resourceSchemas,
            'origin',
        );
    }

    /**
     * @param  list<string>  $protocolVersions
     * @param  array<string, list<string>>  $resourceSchemas
     * @return array<string, list<string>>
     */
    private function assertVersionCompatibility(array $protocolVersions, array $resourceSchemas, string $field): array
    {
        $protocol = (string) config('federation.protocol_version', '1.0');

        if (! in_array($protocol, $protocolVersions, true)) {
            throw ValidationException::withMessages([
                $field => 'The peer does not support this federation protocol version.',
            ]);
        }

        $negotiated = [];

        foreach ($this->localResourceSchemas() as $resource => $localVersions) {
            $common = array_values(array_intersect(
                $localVersions,
                array_map('strval', (array) ($resourceSchemas[$resource] ?? [])),
            ));

            if ($common !== []) {
                $negotiated[(string) $resource] = $common;
            }
        }

        if (! in_array('1.0', $negotiated['milcom.war-plan-snapshot'] ?? [], true)) {
            throw ValidationException::withMessages([
                $field => 'The peer does not support the required war-plan snapshot schema.',
            ]);
        }

        return $negotiated;
    }

    /** @return list<string> */
    private function localProtocolVersions(): array
    {
        return [(string) config('federation.protocol_version', '1.0')];
    }

    /** @return array<string, list<string>> */
    private function localResourceSchemas(): array
    {
        return collect((array) config('federation.resource_schemas', []))
            ->mapWithKeys(fn (mixed $versions, mixed $resource): array => [
                (string) $resource => array_values(array_map('strval', (array) $versions)),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function storePeerKey(
        FederationLink $link,
        array $key,
        FederationKeyStatus $status,
        ?\DateTimeInterface $approvedAt = null,
    ): FederationPeerKey {
        $existing = FederationPeerKey::query()
            ->where('federation_link_id', $link->id)
            ->where('remote_key_id', $key['key_id'])
            ->first();

        if ($existing instanceof FederationPeerKey) {
            if ((int) $existing->generation !== (int) $key['generation']
                || ! hash_equals($existing->signing_public_key, $key['signing_public_key'])
                || ! hash_equals($existing->box_public_key, $key['box_public_key'])
                || ! hash_equals($existing->signing_fingerprint, $key['signing_fingerprint'])
                || ! hash_equals($existing->box_fingerprint, $key['box_fingerprint'])) {
                throw ValidationException::withMessages([
                    'key' => 'An existing peer key identifier cannot be rebound to different key material.',
                ]);
            }

            if (in_array($existing->status, [
                FederationKeyStatus::Compromised,
                FederationKeyStatus::Retired,
            ], true) && $existing->status !== $status) {
                throw ValidationException::withMessages([
                    'key' => 'A compromised or retired peer key generation cannot be reactivated.',
                ]);
            }

            $existing->forceFill([
                'status' => $status,
                'approved_at' => $approvedAt,
            ])->save();

            return $existing;
        }

        return FederationPeerKey::query()->create([
            'federation_link_id' => $link->id,
            'remote_key_id' => $key['key_id'],
            'generation' => $key['generation'],
            'status' => $status,
            'signing_public_key' => $key['signing_public_key'],
            'box_public_key' => $key['box_public_key'],
            'signing_fingerprint' => $key['signing_fingerprint'],
            'box_fingerprint' => $key['box_fingerprint'],
            'approved_at' => $approvedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function localKeyPayload(mixed $key): array
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

    private function assertPendingInvitation(
        FederationLinkInvitation $invitation,
        string $direction,
        bool $allowApproved = false,
    ): void {
        $allowed = [FederationWorkflowStatus::Pending];

        if ($allowApproved) {
            $allowed[] = FederationWorkflowStatus::Approved;
        }

        if ($invitation->direction !== $direction
            || ! in_array($invitation->status, $allowed, true)
            || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'This link invitation is no longer actionable.']);
        }
    }

    /** @return array<string, mixed> */
    private function sourcePayload(FederationLinkInvitation $invitation): array
    {
        $message = FederationInboxMessage::query()
            ->where('message_id', $invitation->source_message_id)
            ->firstOrFail();

        return $this->storedEnvelopes->payload($message);
    }

    private function assertToken(FederationLinkInvitation $invitation, string $token): void
    {
        if (! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['invitation' => 'Link invitation token is invalid.']);
        }
    }
}
