<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\FederationFingerprint;
use App\Domain\Federation\Support\StrictJson;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationIdentity;
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
    ) {}

    public function discover(string $origin): FederationDiscoveryDocument
    {
        $this->assertLinkingEnabled();

        return $this->transport->discover(PeerOrigin::fromUrl($origin));
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
        $identity = FederationIdentity::query()->with('activeKey')->firstOrFail();

        if (hash_equals($identity->id, $discovery->installationId)) {
            throw ValidationException::withMessages(['origin' => 'An installation cannot link to itself.']);
        }

        try {
            $link = DB::transaction(function () use ($discovery, $identity, $actorUserId): FederationLink {
                $existing = FederationLink::query()
                    ->where('remote_installation_id', $discovery->installationId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof FederationLink) {
                    throw ValidationException::withMessages([
                        'origin' => 'A link workflow already exists for this installation.',
                    ]);
                }

                $link = FederationLink::query()->create([
                    'id' => (string) Str::ulid(),
                    'remote_installation_id' => $discovery->installationId,
                    'remote_display_name' => $discovery->displayName,
                    'approved_origin' => $discovery->origin,
                    'status' => FederationLinkStatus::PendingRemote,
                    'remote_ownership_epoch' => $discovery->ownershipEpoch,
                    'negotiated_protocol_version' => (string) config('federation.protocol_version', '1.0'),
                    'negotiated_resource_versions' => $discovery->resourceSchemas,
                ]);
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
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->first();

            if ($link instanceof FederationLink && $link->status === FederationLinkStatus::Active) {
                return $link;
            }

            $link ??= FederationLink::query()->create([
                'id' => (string) Str::ulid(),
                'remote_installation_id' => $message->sender_installation_id,
                'remote_display_name' => $payload['source_display_name'],
                'approved_origin' => $origin,
                'status' => FederationLinkStatus::PendingLocal,
                'remote_ownership_epoch' => $payload['source_ownership_epoch'],
                'negotiated_protocol_version' => $message->protocol_version,
            ]);
            $link->forceFill([
                'remote_display_name' => $payload['source_display_name'],
                'approved_origin' => $origin,
                'status' => FederationLinkStatus::PendingLocal,
                'remote_ownership_epoch' => $payload['source_ownership_epoch'],
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
                    ],
                    'source_message_id' => $message->message_id,
                    'expires_at' => CarbonImmutable::parse($payload['expires_at']),
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

            if (! hash_equals($link->remote_installation_id, $payload['recipient_installation_id'])) {
                throw ValidationException::withMessages(['link' => 'Link acceptance installation does not match.']);
            }

            $knownKey = $link->peerKeys()->where('remote_key_id', $payload['recipient_key']['key_id'])->first();

            if ($knownKey instanceof FederationPeerKey
                && (! hash_equals($knownKey->signing_public_key, $payload['recipient_key']['signing_public_key'])
                    || ! hash_equals($knownKey->box_public_key, $payload['recipient_key']['box_public_key']))) {
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
                'approved_origin' => PeerOrigin::fromUrl($payload['recipient_origin'])->value(),
                'remote_display_name' => $payload['recipient_display_name'],
                'remote_ownership_epoch' => $payload['recipient_ownership_epoch'],
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
        DB::transaction(function () use ($payload): void {
            $invitation = FederationLinkInvitation::query()->lockForUpdate()->findOrFail($payload['invitation_id']);
            $this->assertToken($invitation, $payload['invitation_token']);
            $link = FederationLink::query()->lockForUpdate()->findOrFail($invitation->federation_link_id);
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
                $identity = FederationIdentity::query()->firstOrFail();
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

    /**
     * @param  array<string, mixed>  $key
     */
    private function storePeerKey(
        FederationLink $link,
        array $key,
        FederationKeyStatus $status,
        ?\DateTimeInterface $approvedAt = null,
    ): FederationPeerKey {
        return FederationPeerKey::query()->updateOrCreate(
            [
                'federation_link_id' => $link->id,
                'remote_key_id' => $key['key_id'],
            ],
            [
                'generation' => $key['generation'],
                'status' => $status,
                'signing_public_key' => $key['signing_public_key'],
                'box_public_key' => $key['box_public_key'],
                'signing_fingerprint' => $key['signing_fingerprint'],
                'box_fingerprint' => $key['box_fingerprint'],
                'approved_at' => $approvedAt,
            ]
        );
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

        return StrictJson::decodeObject((string) $message->decrypted_payload);
    }

    private function assertToken(FederationLinkInvitation $invitation, string $token): void
    {
        if (! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['invitation' => 'Link invitation token is invalid.']);
        }
    }
}
