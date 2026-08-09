<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationPeerKey;
use App\Models\FederationPublication;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FederationCoalitionService
{
    public function __construct(
        private readonly FederationOutboxService $outbox,
        private readonly AuditLogger $audit,
        private readonly WarPlanPublicationService $publications,
        private readonly FederationCryptography $cryptography,
        private readonly FederationStoredEnvelopeReader $storedEnvelopes,
    ) {}

    public function create(string $name, ?CarbonImmutable $expiresAt, int $actorUserId): FederationCoalition
    {
        $identity = $this->identity();
        $coalitionId = (string) Str::ulid();
        $manifest = $this->manifestPayload(
            coalitionId: $coalitionId,
            name: Str::limit(Str::squish($name), 255, ''),
            coordinatorInstallationId: $identity->id,
            revision: 1,
            status: CoalitionStatus::Active,
            expiresAt: $expiresAt,
            members: [[
                'installation_id' => $identity->id,
                'role' => CoalitionRole::Coordinator->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now()->utc()->toIso8601String(),
                'expires_at' => $expiresAt?->toIso8601String(),
                'removed_at' => null,
            ]],
        );

        $coalition = DB::transaction(function () use (
            $coalitionId,
            $name,
            $identity,
            $manifest,
            $expiresAt,
            $actorUserId,
        ): FederationCoalition {
            $canonical = CanonicalJson::encode($manifest);
            $coalition = FederationCoalition::query()->create([
                'id' => $coalitionId,
                'name' => Str::limit(Str::squish($name), 255, ''),
                'coordinator_installation_id' => $identity->id,
                'status' => CoalitionStatus::Active,
                'roster_revision' => 1,
                'roster_hash' => $manifest['manifest_hash'],
                'canonical_manifest' => $canonical,
                'expires_at' => $expiresAt,
                'created_by' => $actorUserId,
            ]);
            $coalition->memberships()->create([
                'id' => (string) Str::ulid(),
                'installation_id' => $identity->id,
                'role' => CoalitionRole::Coordinator,
                'status' => MembershipStatus::Active,
                'roster_revision' => 1,
                'joined_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            return $coalition;
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.created', $coalition, [
            'coalition_id' => $coalition->id,
            'roster_revision' => 1,
        ]);

        return $coalition;
    }

    public function invite(
        FederationCoalition $coalition,
        FederationLink $link,
        CoalitionRole $role,
        int $actorUserId,
    ): FederationCoalitionInvitation {
        $this->assertLocalCoordinator($coalition);

        if ($link->status !== FederationLinkStatus::Active || $role === CoalitionRole::Coordinator) {
            throw ValidationException::withMessages([
                'member' => 'Coalition invitations require an active link and a non-coordinator role.',
            ]);
        }

        $token = Base64Url::encode(random_bytes(32));
        $expiresAt = CarbonImmutable::now('UTC')->addHours(
            max((int) config('federation.invitation_expiry_hours', 24), 1)
        );

        try {
            $invitation = DB::transaction(function () use (
                $coalition,
                $link,
                $role,
                $actorUserId,
                $token,
                $expiresAt,
            ): FederationCoalitionInvitation {
                $membership = $coalition->memberships()->firstOrCreate(
                    ['installation_id' => $link->remote_installation_id],
                    [
                        'id' => (string) Str::ulid(),
                        'federation_link_id' => $link->id,
                        'role' => $role,
                        'status' => MembershipStatus::Pending,
                        'roster_revision' => $coalition->roster_revision,
                        'expires_at' => $coalition->expires_at,
                    ]
                );

                if ($membership->status === MembershipStatus::Active) {
                    throw ValidationException::withMessages(['member' => 'This installation is already a coalition member.']);
                }

                $invitation = FederationCoalitionInvitation::query()->create([
                    'id' => (string) Str::ulid(),
                    'federation_coalition_id' => $coalition->id,
                    'federation_link_id' => $link->id,
                    'installation_id' => $link->remote_installation_id,
                    'role' => $role,
                    'direction' => 'outbound',
                    'token_hash' => hash('sha256', $token),
                    'status' => FederationWorkflowStatus::Pending,
                    'pending_key' => 1,
                    'created_by' => $actorUserId,
                    'expires_at' => $expiresAt,
                ]);
                $this->outbox->queue(
                    link: $link,
                    type: FederationMessageType::CoalitionInvitation,
                    payload: $this->invitationPayload($coalition, $invitation, $token, 'invite', null),
                    expiresAt: $expiresAt,
                );

                return $invitation;
            }, attempts: 5);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'member' => 'A coalition invitation for this installation is already pending.',
            ]);
        }

        $this->audit->success('federation', 'coalition.invited', $invitation, [
            'coalition_id' => $coalition->id,
            'installation_id' => $link->remote_installation_id,
            'role' => $role->value,
        ]);

        return $invitation;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveInvitation(FederationInboxMessage $message, array $payload): void
    {
        if ($payload['action'] === 'accept') {
            $this->receiveAcceptance($message, $payload);

            return;
        }

        if ($payload['action'] !== 'invite') {
            throw ValidationException::withMessages(['invitation' => 'Unsupported coalition invitation action.']);
        }

        $invitationExpiresAt = CarbonImmutable::parse($payload['expires_at']);

        if ($invitationExpiresAt->isPast()
            || $invitationExpiresAt->isAfter(CarbonImmutable::now('UTC')->addHours(
                max((int) config('federation.invitation_expiry_hours', 24), 1)
            ))
            || $invitationExpiresAt->isAfter(CarbonImmutable::instance($message->expires_at))) {
            throw ValidationException::withMessages([
                'invitation' => 'The coalition invitation expiry is invalid.',
            ]);
        }

        $manifestPayload = $payload;
        unset($manifestPayload['invitation_token']);

        DB::transaction(function () use ($message, $payload, $invitationExpiresAt, $manifestPayload): void {
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->where('status', FederationLinkStatus::Active->value)
                ->firstOrFail();

            if (! hash_equals($payload['coordinator_installation_id'], $message->sender_installation_id)) {
                throw ValidationException::withMessages(['coalition' => 'Only the coordinator may issue invitations.']);
            }

            $coalition = FederationCoalition::query()->lockForUpdate()->find($payload['coalition_id']);

            if ($coalition instanceof FederationCoalition
                && (! hash_equals($coalition->coordinator_installation_id, $message->sender_installation_id)
                    || $coalition->status !== CoalitionStatus::Active)) {
                throw ValidationException::withMessages([
                    'coalition' => 'The coalition invitation conflicts with an existing canonical coalition.',
                ]);
            }

            $coalition ??= FederationCoalition::query()->create([
                'id' => $payload['coalition_id'],
                'name' => $payload['coalition_name'],
                'coordinator_installation_id' => $payload['coordinator_installation_id'],
                'status' => CoalitionStatus::Active,
                'roster_revision' => $payload['roster_revision'],
                'roster_hash' => hash('sha256', CanonicalJson::encode($manifestPayload)),
                'canonical_manifest' => CanonicalJson::encode($manifestPayload),
                'expires_at' => null,
            ]);
            $coalition->memberships()->updateOrCreate(
                ['installation_id' => $message->sender_installation_id],
                [
                    'federation_link_id' => $link->id,
                    'role' => CoalitionRole::Coordinator,
                    'status' => MembershipStatus::Active,
                    'roster_revision' => $payload['roster_revision'],
                    'joined_at' => now(),
                ]
            );
            $identity = $this->identity();
            $coalition->memberships()->updateOrCreate(
                ['installation_id' => $identity->id],
                [
                    'role' => CoalitionRole::from($payload['role']),
                    'status' => MembershipStatus::Pending,
                    'roster_revision' => $payload['roster_revision'],
                ]
            );
            FederationCoalitionInvitation::query()->updateOrCreate(
                ['id' => $payload['invitation_id']],
                [
                    'federation_coalition_id' => $coalition->id,
                    'federation_link_id' => $link->id,
                    'installation_id' => $identity->id,
                    'role' => CoalitionRole::from($payload['role']),
                    'direction' => 'inbound',
                    'token_hash' => hash('sha256', $payload['invitation_token']),
                    'status' => FederationWorkflowStatus::Pending,
                    'pending_key' => 1,
                    'source_message_id' => $message->message_id,
                    'expires_at' => $invitationExpiresAt,
                ]
            );
        }, attempts: 5);
    }

    public function acceptInvitation(FederationCoalitionInvitation $invitation, int $actorUserId): void
    {
        DB::transaction(function () use ($invitation, $actorUserId): void {
            $pending = FederationCoalitionInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($pending->direction !== 'inbound'
                || $pending->status !== FederationWorkflowStatus::Pending
                || $pending->expires_at->isPast()) {
                throw ValidationException::withMessages(['invitation' => 'This coalition invitation is no longer actionable.']);
            }

            $source = FederationInboxMessage::query()
                ->where('message_id', $pending->source_message_id)
                ->firstOrFail();
            $payload = $this->storedEnvelopes->payload($source);
            $this->assertToken($pending, $payload['invitation_token']);
            $coalition = FederationCoalition::query()->findOrFail($pending->federation_coalition_id);
            $link = FederationLink::query()->findOrFail($pending->federation_link_id);

            if ($link->status !== FederationLinkStatus::Active) {
                throw ValidationException::withMessages([
                    'invitation' => 'The peer link must remain active to accept this invitation.',
                ]);
            }
            $pending->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
            $this->outbox->queue(
                link: $link,
                type: FederationMessageType::CoalitionInvitation,
                payload: $this->invitationPayload(
                    $coalition,
                    $pending,
                    $payload['invitation_token'],
                    'accept',
                    now()->utc()->toIso8601String(),
                ),
                expiresAt: CarbonImmutable::instance($pending->expires_at),
            );
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    private function receiveAcceptance(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $invitation = FederationCoalitionInvitation::query()->lockForUpdate()->findOrFail($payload['invitation_id']);
            $this->assertToken($invitation, $payload['invitation_token']);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail($invitation->federation_coalition_id);
            $this->assertLocalCoordinator($coalition);

            if ($invitation->direction !== 'outbound'
                || $invitation->status !== FederationWorkflowStatus::Pending
                || $invitation->expires_at->isPast()
                || $payload['action'] !== 'accept'
                || ! hash_equals($payload['coalition_id'], $coalition->id)
                || ! hash_equals($payload['coordinator_installation_id'], $coalition->coordinator_installation_id)
                || ! hash_equals($invitation->installation_id, $message->sender_installation_id)
                || ! hash_equals((string) $invitation->federation_link_id, (string) FederationLink::query()
                    ->where('remote_installation_id', $message->sender_installation_id)
                    ->value('id'))) {
                throw ValidationException::withMessages(['invitation' => 'Coalition acceptance sender does not match.']);
            }

            $membership = $coalition->memberships()
                ->where('installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->status !== MembershipStatus::Pending
                || $membership->role->value !== $payload['role']) {
                throw ValidationException::withMessages([
                    'invitation' => 'Coalition membership is no longer awaiting this invitation.',
                ]);
            }
            $membership->forceFill([
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'expires_at' => $coalition->expires_at,
            ])->save();
            $invitation->forceFill([
                'status' => FederationWorkflowStatus::Completed,
                'pending_key' => null,
                'source_message_id' => $message->message_id,
            ])->save();
            $this->issueRoster($coalition);
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveManifest(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail($payload['coalition_id']);
            $this->assertManifestMembers($payload);

            if ($coalition->status !== CoalitionStatus::Active
                && $payload['status'] === CoalitionStatus::Active->value) {
                throw ValidationException::withMessages([
                    'coalition' => 'A terminal coalition cannot be reactivated by a later roster.',
                ]);
            }

            $coordinatorChanged = ! hash_equals(
                $coalition->coordinator_installation_id,
                $message->sender_installation_id,
            );
            $hasTransferProof = array_key_exists('transfer_proof', $payload);

            if ($coordinatorChanged || $hasTransferProof) {
                $this->assertCoordinatorTransferManifest($coalition, $message, $payload, $coordinatorChanged);
            } elseif (! hash_equals($payload['coordinator_installation_id'], $message->sender_installation_id)) {
                throw ValidationException::withMessages(['coalition' => 'Coalition manifest was not issued by its coordinator.']);
            }

            if ((int) $payload['revision'] < (int) $coalition->roster_revision) {
                return;
            }

            $hashPayload = $payload;
            unset($hashPayload['manifest_hash'], $hashPayload['transfer_proof']);
            $hash = hash('sha256', CanonicalJson::encode($hashPayload));

            if (! hash_equals($hash, $payload['manifest_hash'])) {
                throw ValidationException::withMessages(['coalition' => 'Coalition manifest hash is invalid.']);
            }

            if ((int) $payload['revision'] === (int) $coalition->roster_revision) {
                if (! hash_equals($coalition->roster_hash, $payload['manifest_hash'])) {
                    throw ValidationException::withMessages([
                        'coalition' => 'A roster revision was received with different canonical contents.',
                    ]);
                }

                return;
            }

            $identity = $this->identity();
            $localMember = collect($payload['members'])->first(
                fn (array $member): bool => $member['installation_id'] === $identity->id
            );

            $coalition->forceFill([
                'name' => $payload['name'],
                'coordinator_installation_id' => $payload['coordinator_installation_id'],
                'status' => CoalitionStatus::from($payload['status']),
                'roster_revision' => $payload['revision'],
                'roster_hash' => $payload['manifest_hash'],
                'canonical_manifest' => CanonicalJson::encode($payload),
                'expires_at' => $payload['expires_at'] ? CarbonImmutable::parse($payload['expires_at']) : null,
            ])->save();

            if ($hasTransferProof) {
                FederationCoalitionProposal::query()
                    ->where('id', $payload['transfer_proof']['statement']['proposal_id'])
                    ->where('federation_coalition_id', $coalition->id)
                    ->update([
                        'status' => FederationWorkflowStatus::Completed->value,
                        'pending_key' => null,
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $manifestInstallationIds = collect($payload['members'])
                ->pluck('installation_id')
                ->map(fn (mixed $installationId): string => (string) $installationId)
                ->all();

            $coalition->memberships()
                ->whereNotIn('installation_id', $manifestInstallationIds)
                ->whereIn('status', [MembershipStatus::Pending->value, MembershipStatus::Active->value])
                ->update([
                    'status' => MembershipStatus::Removed->value,
                    'roster_revision' => $payload['revision'],
                    'removed_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($payload['members'] as $member) {
                $link = FederationLink::query()
                    ->where('remote_installation_id', $member['installation_id'])
                    ->first();
                $coalition->memberships()->updateOrCreate(
                    ['installation_id' => $member['installation_id']],
                    [
                        'federation_link_id' => $link?->id,
                        'role' => CoalitionRole::from($member['role']),
                        'status' => MembershipStatus::from($member['status']),
                        'roster_revision' => $payload['revision'],
                        'joined_at' => $member['joined_at'] ? CarbonImmutable::parse($member['joined_at']) : null,
                        'expires_at' => $member['expires_at'] ? CarbonImmutable::parse($member['expires_at']) : null,
                        'removed_at' => $member['removed_at'] ? CarbonImmutable::parse($member['removed_at']) : null,
                    ]
                );
            }

            if ($payload['status'] !== CoalitionStatus::Active->value
                || ! is_array($localMember)
                || $localMember['status'] !== MembershipStatus::Active->value) {
                $this->expireCapabilities($coalition);
                $this->revokeLocalPublications($coalition, 'coalition_scope_ended');
            }
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveProposal(FederationInboxMessage $message, array $payload): void
    {
        $type = (string) $payload['proposal_type'];
        [$baseType, $action] = $this->proposalAction($type);
        $this->assertProposalPayload($payload, $baseType);
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->firstOrFail();
        $identity = $this->identity();

        if ($action !== null) {
            $this->receiveProposalAction($message, $payload, $baseType, $action, $link, $identity->id);

            return;
        }

        DB::transaction(function () use ($message, $payload, $baseType): void {
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail($payload['coalition_id']);
            $this->assertActiveCoalition($coalition);

            if ((int) $payload['base_roster_revision'] !== (int) $coalition->roster_revision) {
                throw ValidationException::withMessages([
                    'proposal' => 'The proposal is based on a stale coalition roster.',
                ]);
            }

            $proposer = $coalition->memberships()
                ->where('installation_id', $message->sender_installation_id)
                ->where('status', MembershipStatus::Active->value)
                ->lockForUpdate()
                ->first();
            $isCoordinatorProposal = $baseType === 'coordinator.transfer'
                && hash_equals($message->sender_installation_id, $coalition->coordinator_installation_id);

            if (! $isCoordinatorProposal
                && (! $proposer instanceof FederationCoalitionMembership
                    || $proposer->role !== CoalitionRole::Admin)) {
                throw ValidationException::withMessages([
                    'proposal' => 'Only an active coalition administrator may submit this proposal.',
                ]);
            }

            $existing = FederationCoalitionProposal::query()->lockForUpdate()->find($payload['proposal_id']);

            if ($existing instanceof FederationCoalitionProposal) {
                if (! hash_equals($existing->payload_hash, $payload['payload_hash'])) {
                    throw ValidationException::withMessages(['proposal' => 'The proposal contents changed.']);
                }

                return;
            }

            FederationCoalitionProposal::query()->create([
                'id' => $payload['proposal_id'],
                'federation_coalition_id' => $coalition->id,
                'proposer_installation_id' => $message->sender_installation_id,
                'proposal_type' => $baseType,
                'workflow_key' => $payload['workflow_key'],
                'target_installation_id' => $payload['target_installation_id'],
                'requested_role' => $payload['requested_role'],
                'base_roster_revision' => $payload['base_roster_revision'],
                'payload_hash' => $payload['payload_hash'],
                'canonical_payload' => CanonicalJson::encode($payload),
                'status' => FederationWorkflowStatus::Pending,
                'pending_key' => 1,
                'expires_at' => CarbonImmutable::parse($payload['expires_at']),
            ]);
        }, attempts: 5);
    }

    public function proposeRosterChange(
        FederationCoalition $coalition,
        string $proposalType,
        ?string $targetInstallationId,
        ?CoalitionRole $requestedRole,
        int $actorUserId,
    ): FederationCoalitionProposal {
        $baseType = $this->normalizeProposalType($proposalType);
        $identity = $this->identity();
        $expiresAt = CarbonImmutable::now('UTC')->addHours(
            max((int) config('federation.invitation_expiry_hours', 24), 1)
        );
        $proposalId = (string) Str::ulid();

        $proposal = DB::transaction(function () use (
            $coalition,
            $baseType,
            $targetInstallationId,
            $requestedRole,
            $identity,
            $expiresAt,
            $proposalId,
        ): FederationCoalitionProposal {
            $lockedCoalition = FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id);
            $this->assertActiveCoalition($lockedCoalition);
            $localMembership = $lockedCoalition->memberships()
                ->where('installation_id', $identity->id)
                ->where('status', MembershipStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if (! $localMembership instanceof FederationCoalitionMembership
                || ($identity->id !== $lockedCoalition->coordinator_installation_id
                    && $localMembership->role !== CoalitionRole::Admin)) {
                throw ValidationException::withMessages([
                    'proposal' => 'Only the coordinator or an active coalition administrator may propose changes.',
                ]);
            }

            if ($baseType === 'coordinator.transfer' && $identity->id !== $lockedCoalition->coordinator_installation_id) {
                throw ValidationException::withMessages([
                    'proposal' => 'Only the current coordinator may propose a coordinator transfer.',
                ]);
            }

            $this->assertProposalTarget($lockedCoalition, $baseType, $targetInstallationId, $requestedRole);
            $workflowKey = $this->proposalWorkflowKey($lockedCoalition, $baseType, $targetInstallationId, $requestedRole);
            $payload = [
                'proposal_id' => $proposalId,
                'coalition_id' => $lockedCoalition->id,
                'proposal_type' => $baseType,
                'workflow_key' => $workflowKey,
                'target_installation_id' => $targetInstallationId,
                'requested_role' => $requestedRole?->value,
                'base_roster_revision' => (int) $lockedCoalition->roster_revision,
                'expires_at' => $expiresAt->utc()->toIso8601String(),
            ];
            $payload['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));
            $proposal = FederationCoalitionProposal::query()->create([
                'id' => $proposalId,
                'federation_coalition_id' => $lockedCoalition->id,
                'proposer_installation_id' => $identity->id,
                'proposal_type' => $baseType,
                'workflow_key' => $workflowKey,
                'target_installation_id' => $targetInstallationId,
                'requested_role' => $requestedRole,
                'base_roster_revision' => (int) $lockedCoalition->roster_revision,
                'payload_hash' => $payload['payload_hash'],
                'canonical_payload' => CanonicalJson::encode($payload),
                'status' => FederationWorkflowStatus::Pending,
                'pending_key' => 1,
                'expires_at' => $expiresAt,
            ]);

            if ($baseType === 'coordinator.transfer') {
                $destinationLink = FederationLink::query()
                    ->where('remote_installation_id', $targetInstallationId)
                    ->where('status', FederationLinkStatus::Active->value)
                    ->firstOrFail();
                $this->outbox->queue(
                    link: $destinationLink,
                    type: FederationMessageType::CoalitionProposal,
                    payload: $payload,
                    expiresAt: $expiresAt,
                );
            } elseif ($identity->id !== $lockedCoalition->coordinator_installation_id) {
                $coordinatorLink = FederationLink::query()
                    ->where('remote_installation_id', $lockedCoalition->coordinator_installation_id)
                    ->where('status', FederationLinkStatus::Active->value)
                    ->firstOrFail();
                $this->outbox->queue(
                    link: $coordinatorLink,
                    type: FederationMessageType::CoalitionProposal,
                    payload: $payload,
                    expiresAt: $expiresAt,
                );
            }

            return $proposal;
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.proposal_submitted', $proposal, [
            'proposal_id' => $proposal->id,
            'coalition_id' => $proposal->federation_coalition_id,
            'proposal_type' => $proposal->proposal_type,
            'actor_id' => $actorUserId,
        ]);

        return $proposal;
    }

    public function submitProposal(
        FederationCoalition $coalition,
        string $proposalType,
        ?string $targetInstallationId,
        ?CoalitionRole $requestedRole,
        int $actorUserId,
    ): FederationCoalitionProposal {
        return $this->proposeRosterChange(
            $coalition,
            $proposalType,
            $targetInstallationId,
            $requestedRole,
            $actorUserId,
        );
    }

    public function approveProposal(FederationCoalitionProposal $proposal, int $actorUserId): void
    {
        DB::transaction(function () use ($proposal, $actorUserId): void {
            $lockedProposal = FederationCoalitionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail(
                $lockedProposal->federation_coalition_id,
            );
            $this->assertLocalCoordinator($coalition);

            if ($lockedProposal->status !== FederationWorkflowStatus::Pending
                || $lockedProposal->expires_at->isPast()) {
                throw ValidationException::withMessages(['proposal' => 'The coalition proposal is no longer actionable.']);
            }

            if ((int) $lockedProposal->base_roster_revision !== (int) $coalition->roster_revision) {
                throw ValidationException::withMessages(['proposal' => 'The coalition proposal is based on a stale roster.']);
            }

            if ($lockedProposal->proposal_type === 'coordinator.transfer') {
                throw ValidationException::withMessages([
                    'proposal' => 'Coordinator transfers require destination acceptance before coordinator approval.',
                ]);
            }

            $this->applyRosterProposal($coalition, $lockedProposal);
            $lockedProposal->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
            $this->sendProposalResponse($lockedProposal, FederationWorkflowStatus::Approved);
            $this->issueRoster($coalition);
        }, attempts: 5);
    }

    public function rejectProposal(
        FederationCoalitionProposal $proposal,
        int $actorUserId,
        string $reasonCode = 'rejected_by_coordinator',
    ): void {
        DB::transaction(function () use ($proposal, $actorUserId): void {
            $lockedProposal = FederationCoalitionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail(
                $lockedProposal->federation_coalition_id,
            );
            $this->assertLocalCoordinator($coalition);

            if ($lockedProposal->status !== FederationWorkflowStatus::Pending) {
                throw ValidationException::withMessages(['proposal' => 'The coalition proposal is no longer actionable.']);
            }

            $lockedProposal->forceFill([
                'status' => FederationWorkflowStatus::Rejected,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
            $this->sendProposalResponse($lockedProposal, FederationWorkflowStatus::Rejected);
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.proposal_rejected', $proposal, [
            'proposal_id' => $proposal->id,
            'actor_id' => $actorUserId,
            'reason_code' => Str::limit(Str::snake($reasonCode), 64, ''),
        ]);
    }

    public function proposeCoordinatorTransfer(
        FederationCoalition $coalition,
        string $destinationInstallationId,
        int $actorUserId,
    ): FederationCoalitionProposal {
        return $this->proposeRosterChange(
            $coalition,
            'coordinator.transfer',
            $destinationInstallationId,
            CoalitionRole::Coordinator,
            $actorUserId,
        );
    }

    public function acceptCoordinatorTransfer(
        FederationCoalitionProposal $proposal,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($proposal, $actorUserId): void {
            $lockedProposal = FederationCoalitionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail(
                $lockedProposal->federation_coalition_id,
            );
            $identity = $this->identity();

            if ($lockedProposal->proposal_type !== 'coordinator.transfer'
                || $lockedProposal->target_installation_id !== $identity->id
                || $lockedProposal->status !== FederationWorkflowStatus::Pending
                || $lockedProposal->expires_at->isPast()) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer is not awaiting destination acceptance.']);
            }

            $remoteMemberIds = $coalition->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->where('installation_id', '!=', $identity->id)
                ->pluck('installation_id');
            $linkedMemberIds = FederationLink::query()
                ->whereIn('remote_installation_id', $remoteMemberIds)
                ->where('status', FederationLinkStatus::Active->value)
                ->pluck('remote_installation_id');

            if ($remoteMemberIds->diff($linkedMemberIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'proposal' => 'The destination must have an active bilateral link to every active coalition member.',
                ]);
            }

            $lockedProposal->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
            $this->sendProposalResponse($lockedProposal, FederationWorkflowStatus::Approved, 'accepted');
        }, attempts: 5);
    }

    public function approveCoordinatorTransfer(
        FederationCoalitionProposal $proposal,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($proposal, $actorUserId): void {
            $lockedProposal = FederationCoalitionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail(
                $lockedProposal->federation_coalition_id,
            );
            $this->assertLocalCoordinator($coalition);

            if ($lockedProposal->proposal_type !== 'coordinator.transfer'
                || $lockedProposal->status !== FederationWorkflowStatus::Approved
                || $lockedProposal->target_installation_id === null
                || (int) $lockedProposal->base_roster_revision !== (int) $coalition->roster_revision) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer is not accepted by its destination.']);
            }

            $identity = $this->identity()->load('activeKey');
            $key = $identity->activeKey;

            if (! $key instanceof FederationIdentityKey || $key->signing_private_key === null) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator signing key is unavailable.']);
            }

            $manifest = $this->coordinatorTransferManifest($coalition, $lockedProposal);
            $statement = $this->coordinatorTransferStatement($coalition, $lockedProposal, $manifest);
            $payload = StrictJson::decodeObject((string) $lockedProposal->canonical_payload);
            $payload['proposal_type'] = 'coordinator.transfer.approved';
            $payload['transfer_manifest'] = $manifest;
            $payload['transfer_approval'] = [
                'statement' => $statement,
                'coordinator_key_id' => $key->id,
                'coordinator_signature' => $this->cryptography->sign(
                    $this->coordinatorTransferSignatureInput($statement),
                    $key->signing_private_key,
                ),
            ];
            $destinationLink = FederationLink::query()
                ->where('remote_installation_id', $lockedProposal->target_installation_id)
                ->where('status', FederationLinkStatus::Active->value)
                ->firstOrFail();
            $this->outbox->queue(
                link: $destinationLink,
                type: FederationMessageType::CoalitionProposal,
                payload: $payload,
                expiresAt: CarbonImmutable::instance($lockedProposal->expires_at),
            );
            $lockedProposal->forceFill([
                'reviewed_by' => $actorUserId,
                'reviewed_at' => now(),
            ])->save();
        }, attempts: 5);
    }

    public function expire(FederationCoalition $coalition): void
    {
        DB::transaction(function () use ($coalition): void {
            $locked = FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id);

            if ($locked->status !== CoalitionStatus::Active) {
                return;
            }

            $locked->forceFill(['status' => CoalitionStatus::Expired])->save();
            $locked->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->update([
                    'status' => MembershipStatus::Expired->value,
                    'updated_at' => now(),
                ]);
            $this->expireCapabilities($locked);
            $this->revokeLocalPublications($locked, 'coalition_expired');
            $this->issueRoster($locked, includeInactiveRecipients: true);
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveDissolution(FederationInboxMessage $message, array $payload): void
    {
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->whereIn('status', [
                FederationLinkStatus::Active->value,
                FederationLinkStatus::Suspended->value,
            ])
            ->firstOrFail();
        $coalition = DB::transaction(function () use ($message, $payload): FederationCoalition {
            $locked = FederationCoalition::query()->lockForUpdate()->findOrFail($payload['coalition_id']);

            if (! hash_equals($locked->coordinator_installation_id, $message->sender_installation_id)) {
                throw ValidationException::withMessages(['coalition' => 'Only the coordinator may dissolve a coalition.']);
            }

            if ((int) $payload['revision'] < (int) $locked->roster_revision) {
                return $locked;
            }

            if ((int) $payload['revision'] === (int) $locked->roster_revision
                && $locked->status === CoalitionStatus::Dissolved) {
                return $locked;
            }

            $locked->forceFill([
                'status' => CoalitionStatus::Dissolved,
                'roster_revision' => $payload['revision'],
                'roster_hash' => $payload['manifest_hash'],
                'dissolved_at' => now(),
            ])->save();
            $locked->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->update([
                    'status' => MembershipStatus::Removed->value,
                    'removed_at' => now(),
                    'roster_revision' => $payload['revision'],
                    'updated_at' => now(),
                ]);
            $this->expireCapabilities($locked);
            $this->revokeLocalPublications($locked, 'coalition_dissolved_remote');

            return $locked;
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.dissolution_received', $coalition, [
            'coalition_id' => $coalition->id,
            'revision' => $coalition->roster_revision,
            'remote_installation_id' => $link->remote_installation_id,
        ]);
    }

    public function removeMember(
        FederationCoalition $coalition,
        FederationCoalitionMembership $membership,
        int $actorUserId,
    ): void {
        $this->assertLocalCoordinator($coalition);

        if ($membership->role === CoalitionRole::Coordinator) {
            throw ValidationException::withMessages(['member' => 'Transfer coordination before removing the coordinator.']);
        }

        DB::transaction(function () use ($coalition, $membership): void {
            $lockedCoalition = FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id);
            $locked = FederationCoalitionMembership::query()->lockForUpdate()->findOrFail($membership->id);

            if (! hash_equals($locked->federation_coalition_id, $lockedCoalition->id)
                || $locked->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['member' => 'This membership is no longer active.']);
            }

            $locked->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
            ])->save();
            $this->expireCapabilities($lockedCoalition, $locked->installation_id);
            $this->revokeLocalPublications($lockedCoalition, 'coalition_member_removed', $locked->installation_id);
            $this->issueRoster($lockedCoalition, includeInactiveRecipients: true);
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.member_removed', $membership, [
            'coalition_id' => $coalition->id,
            'installation_id' => $membership->installation_id,
            'actor_id' => $actorUserId,
        ]);
    }

    public function dissolve(FederationCoalition $coalition, int $actorUserId): void
    {
        $this->assertLocalCoordinator($coalition);

        DB::transaction(function () use ($coalition): void {
            $locked = FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id);
            $locked->forceFill([
                'status' => CoalitionStatus::Dissolved,
                'dissolved_at' => now(),
            ])->save();
            $locked->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->update([
                    'status' => MembershipStatus::Removed->value,
                    'removed_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->expireCapabilities($locked);
            $this->revokeLocalPublications($locked, 'coalition_dissolved');
            $this->issueRoster($locked, includeInactiveRecipients: true);
            $revision = (int) $locked->roster_revision;

            foreach ($locked->memberships()->with('link')->whereNotNull('federation_link_id')->get() as $member) {
                if (! $member->link instanceof FederationLink
                    || $member->link->status->isTerminal()) {
                    continue;
                }

                $this->outbox->queue(
                    $member->link,
                    FederationMessageType::CoalitionDissolved,
                    [
                        'coalition_id' => $locked->id,
                        'revision' => $revision,
                        'dissolved_at' => now()->utc()->toIso8601String(),
                        'manifest_hash' => $locked->roster_hash,
                    ],
                    CarbonImmutable::now('UTC')->addDays(7),
                );
            }
        }, attempts: 5);

        $this->audit->success('federation', 'coalition.dissolved', $coalition, [
            'coalition_id' => $coalition->id,
            'actor_id' => $actorUserId,
        ]);
    }

    private function issueRoster(FederationCoalition $coalition, bool $includeInactiveRecipients = false): void
    {
        $revision = (int) $coalition->roster_revision + 1;
        $memberships = $coalition->memberships()
            ->with('link')
            ->orderBy('installation_id')
            ->get();
        $members = $memberships->map(
            fn (FederationCoalitionMembership $member): array => [
                'installation_id' => $member->installation_id,
                'role' => $member->role->value,
                'status' => $member->status->value,
                'joined_at' => $member->joined_at?->utc()->toIso8601String(),
                'expires_at' => $member->expires_at?->utc()->toIso8601String(),
                'removed_at' => $member->removed_at?->utc()->toIso8601String(),
            ]
        )->all();
        $payload = $this->manifestPayload(
            $coalition->id,
            $coalition->name,
            $coalition->coordinator_installation_id,
            $revision,
            $coalition->status,
            $coalition->expires_at ? CarbonImmutable::instance($coalition->expires_at) : null,
            $members,
        );
        $hashPayload = $payload;
        unset($hashPayload['manifest_hash']);
        $payload['manifest_hash'] = hash('sha256', CanonicalJson::encode($hashPayload));
        $coalition->forceFill([
            'roster_revision' => $revision,
            'roster_hash' => $payload['manifest_hash'],
            'canonical_manifest' => CanonicalJson::encode($payload),
        ])->save();
        $coalition->memberships()->update(['roster_revision' => $revision, 'updated_at' => now()]);

        foreach ($memberships as $member) {
            if (! $includeInactiveRecipients && $member->status !== MembershipStatus::Active) {
                continue;
            }

            $link = $member->link;

            if ($link instanceof FederationLink && ! $link->status->isTerminal()) {
                $this->outbox->queue(
                    $link,
                    FederationMessageType::CoalitionManifest,
                    $payload,
                    CarbonImmutable::now('UTC')->addDays(7),
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifestPayload(
        string $coalitionId,
        string $name,
        string $coordinatorInstallationId,
        int $revision,
        CoalitionStatus $status,
        ?CarbonImmutable $expiresAt,
        array $members,
    ): array {
        $payload = [
            'coalition_id' => $coalitionId,
            'name' => $name,
            'coordinator_installation_id' => $coordinatorInstallationId,
            'revision' => $revision,
            'status' => $status->value,
            'expires_at' => $expiresAt?->utc()->toIso8601String(),
            'members' => $members,
        ];
        $payload['manifest_hash'] = hash('sha256', CanonicalJson::encode($payload));

        return $payload;
    }

    /** @return array{0: string, 1: string|null} */
    private function proposalAction(string $proposalType): array
    {
        foreach (['accepted', 'approved', 'rejected', 'completed'] as $action) {
            $suffix = '.'.$action;

            if (Str::endsWith($proposalType, $suffix)) {
                return [Str::beforeLast($proposalType, $suffix), $action];
            }
        }

        return [$proposalType, null];
    }

    private function normalizeProposalType(string $proposalType): string
    {
        $normalized = Str::of($proposalType)->trim()->lower()->replace('_', '.')->value();
        $aliases = [
            'membership.add' => 'member.add',
            'membership.remove' => 'member.remove',
            'membership.role' => 'member.role',
            'role.change' => 'member.role',
            'transfer.coordinator' => 'coordinator.transfer',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        if (! in_array($normalized, [
            'member.add',
            'member.remove',
            'member.role',
            'coordinator.transfer',
        ], true)) {
            throw ValidationException::withMessages(['proposal' => 'This coalition proposal type is unsupported.']);
        }

        return $normalized;
    }

    /** @param  array<string, mixed>  $payload */
    private function assertProposalPayload(array $payload, string $baseType): void
    {
        foreach (['proposal_id', 'coalition_id'] as $field) {
            if (! is_string($payload[$field]) || ! Str::isUlid($payload[$field])) {
                throw ValidationException::withMessages(['proposal' => 'The coalition proposal contains an invalid identifier.']);
            }
        }

        if (! is_string($payload['workflow_key'])
            || ! is_int($payload['base_roster_revision'])
            || $payload['base_roster_revision'] < 1
            || ! is_string($payload['payload_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['payload_hash']) !== 1) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal metadata is invalid.']);
        }

        if ($payload['target_installation_id'] !== null
            && (! is_string($payload['target_installation_id']) || ! Str::isUlid($payload['target_installation_id']))) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal target is invalid.']);
        }

        if ($payload['requested_role'] !== null) {
            try {
                CoalitionRole::from($payload['requested_role']);
            } catch (Throwable) {
                throw ValidationException::withMessages(['proposal' => 'The coalition proposal role is invalid.']);
            }
        }

        try {
            $expiresAt = CarbonImmutable::parse($payload['expires_at']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal expiry is invalid.']);
        }

        if ($expiresAt->isPast()) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal has expired.']);
        }

        $hashPayload = array_intersect_key($payload, array_flip([
            'proposal_id',
            'coalition_id',
            'proposal_type',
            'workflow_key',
            'target_installation_id',
            'requested_role',
            'base_roster_revision',
            'expires_at',
        ]));
        $hashPayload['proposal_type'] = $baseType;

        if (! hash_equals(hash('sha256', CanonicalJson::encode($hashPayload)), $payload['payload_hash'])) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal hash is invalid.']);
        }
    }

    private function assertActiveCoalition(FederationCoalition $coalition): void
    {
        if ($coalition->status !== CoalitionStatus::Active
            || ($coalition->expires_at !== null && $coalition->expires_at->isPast())) {
            throw ValidationException::withMessages(['coalition' => 'This coalition is no longer active.']);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function assertManifestMembers(array $payload): void
    {
        $members = collect($payload['members']);
        $installationIds = $members->pluck('installation_id');

        if ($members->isEmpty() || $installationIds->unique()->count() !== $members->count()) {
            throw ValidationException::withMessages([
                'coalition' => 'The coalition roster contains duplicate or missing members.',
            ]);
        }

        foreach ($members as $member) {
            if (! is_array($member) || ! Str::isUlid((string) $member['installation_id'])) {
                throw ValidationException::withMessages(['coalition' => 'The coalition roster contains an invalid member.']);
            }

            try {
                CoalitionRole::from($member['role']);
                MembershipStatus::from($member['status']);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'coalition' => 'The coalition roster contains an unsupported role or state.',
                ]);
            }
        }

        $coordinators = $members->filter(
            fn (array $member): bool => $member['role'] === CoalitionRole::Coordinator->value
                && $member['installation_id'] === $payload['coordinator_installation_id'],
        );

        if ($coordinators->count() !== 1
            || ($payload['status'] === CoalitionStatus::Active->value
                && $coordinators->first()['status'] !== MembershipStatus::Active->value)) {
            throw ValidationException::withMessages([
                'coalition' => 'The coalition roster does not contain one valid coordinator.',
            ]);
        }
    }

    private function assertProposalTarget(
        FederationCoalition $coalition,
        string $proposalType,
        ?string $targetInstallationId,
        ?CoalitionRole $requestedRole,
    ): void {
        $identity = $this->identity();

        if ($targetInstallationId === null || ! Str::isUlid($targetInstallationId)) {
            throw ValidationException::withMessages(['proposal' => 'A target installation is required.']);
        }

        if ($proposalType === 'coordinator.transfer') {
            if ($targetInstallationId === $identity->id
                || $targetInstallationId === $coalition->coordinator_installation_id
                || $requestedRole !== CoalitionRole::Coordinator) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer destination is invalid.']);
            }

            $membership = $coalition->memberships()
                ->where('installation_id', $targetInstallationId)
                ->where('status', MembershipStatus::Active->value)
                ->first();

            if (! $membership instanceof FederationCoalitionMembership
                || $membership->federation_link_id === null) {
                throw ValidationException::withMessages(['proposal' => 'The transfer destination must be an active linked member.']);
            }

            return;
        }

        $membership = $coalition->memberships()->where('installation_id', $targetInstallationId)->first();

        if ($proposalType === 'member.add') {
            $link = FederationLink::query()
                ->where('remote_installation_id', $targetInstallationId)
                ->where('status', FederationLinkStatus::Active->value)
                ->first();

            if ($membership?->status === MembershipStatus::Active
                || ! $link instanceof FederationLink
                || $requestedRole === null
                || $requestedRole === CoalitionRole::Coordinator) {
                throw ValidationException::withMessages(['proposal' => 'The member addition is invalid.']);
            }

            return;
        }

        if (! $membership instanceof FederationCoalitionMembership
            || $membership->status !== MembershipStatus::Active
            || $membership->role === CoalitionRole::Coordinator
            || ($proposalType === 'member.role' && ($requestedRole === null || $requestedRole === CoalitionRole::Coordinator))) {
            throw ValidationException::withMessages(['proposal' => 'The coalition membership change is invalid.']);
        }

        if ($proposalType === 'member.remove' && $requestedRole !== null) {
            throw ValidationException::withMessages(['proposal' => 'A removal proposal cannot include a role.']);
        }
    }

    private function proposalWorkflowKey(
        FederationCoalition $coalition,
        string $proposalType,
        ?string $targetInstallationId,
        ?CoalitionRole $requestedRole,
    ): string {
        return Str::limit(
            implode(':', [
                $coalition->id,
                $proposalType,
                $targetInstallationId ?? 'none',
                $requestedRole?->value ?? 'none',
            ]),
            96,
            '',
        );
    }

    private function applyRosterProposal(
        FederationCoalition $coalition,
        FederationCoalitionProposal $proposal,
    ): void {
        $targetId = (string) $proposal->target_installation_id;

        if ($proposal->proposal_type === 'member.add') {
            $link = FederationLink::query()
                ->where('remote_installation_id', $targetId)
                ->where('status', FederationLinkStatus::Active->value)
                ->firstOrFail();
            $coalition->memberships()->updateOrCreate(
                ['installation_id' => $targetId],
                [
                    'id' => (string) Str::ulid(),
                    'federation_link_id' => $link->id,
                    'role' => $proposal->requested_role,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                    'expires_at' => $coalition->expires_at,
                ],
            );

            return;
        }

        $membership = $coalition->memberships()
            ->where('installation_id', $targetId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($proposal->proposal_type === 'member.remove') {
            $membership->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
            ])->save();
            $this->expireCapabilities($coalition, $targetId);
            $this->revokeLocalPublications($coalition, 'coalition_member_removed', $targetId);

            return;
        }

        $membership->forceFill(['role' => $proposal->requested_role])->save();
    }

    private function sendProposalResponse(
        FederationCoalitionProposal $proposal,
        FederationWorkflowStatus $status,
        ?string $action = null,
    ): void {
        $identity = $this->identity();

        if ($proposal->proposer_installation_id === $identity->id) {
            return;
        }

        $link = FederationLink::query()
            ->where('remote_installation_id', $proposal->proposer_installation_id)
            ->where('status', FederationLinkStatus::Active->value)
            ->first();

        if (! $link instanceof FederationLink) {
            throw ValidationException::withMessages(['proposal' => 'The proposal origin link is not active.']);
        }

        $payload = StrictJson::decodeObject((string) $proposal->canonical_payload);
        $payload['proposal_type'] = $proposal->proposal_type.'.'.(
            $action ?? match ($status) {
                FederationWorkflowStatus::Approved => 'approved',
                FederationWorkflowStatus::Rejected => 'rejected',
                FederationWorkflowStatus::Completed => 'completed',
                default => 'approved',
            }
        );
        $this->outbox->queue(
            link: $link,
            type: FederationMessageType::CoalitionProposal,
            payload: $payload,
            expiresAt: CarbonImmutable::instance($proposal->expires_at),
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function receiveProposalAction(
        FederationInboxMessage $message,
        array $payload,
        string $baseType,
        string $action,
        FederationLink $link,
        string $localIdentityId,
    ): void {
        $proposal = FederationCoalitionProposal::query()->findOrFail($payload['proposal_id']);

        if (! hash_equals($proposal->payload_hash, $payload['payload_hash'])
            || ! hash_equals($proposal->federation_coalition_id, $payload['coalition_id'])
            || $proposal->proposal_type !== $baseType) {
            throw ValidationException::withMessages(['proposal' => 'The coalition proposal response does not match.']);
        }

        if ($action === 'accepted') {
            if ($baseType !== 'coordinator.transfer'
                || $proposal->target_installation_id !== $message->sender_installation_id
                || $proposal->status !== FederationWorkflowStatus::Pending) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer acceptance is invalid.']);
            }
            $proposal->forceFill([
                'status' => FederationWorkflowStatus::Approved,
                'pending_key' => null,
                'reviewed_at' => now(),
            ])->save();

            return;
        }

        if ($baseType === 'coordinator.transfer' && $action === 'approved'
            && $proposal->target_installation_id === $localIdentityId) {
            $this->completeCoordinatorTransfer($message, $proposal, $payload);

            return;
        }

        $coalition = FederationCoalition::query()->findOrFail($proposal->federation_coalition_id);
        if ($baseType !== 'coordinator.transfer'
            && $message->sender_installation_id !== $coalition->coordinator_installation_id) {
            throw ValidationException::withMessages(['proposal' => 'Only the coordinator may answer a proposal.']);
        }

        $proposal->forceFill([
            'status' => match ($action) {
                'rejected' => FederationWorkflowStatus::Rejected,
                'completed' => FederationWorkflowStatus::Completed,
                default => FederationWorkflowStatus::Approved,
            },
            'pending_key' => null,
            'reviewed_at' => now(),
        ])->save();

        if ($action === 'completed') {
            $link->touch();
        }
    }

    private function completeCoordinatorTransfer(
        FederationInboxMessage $message,
        FederationCoalitionProposal $proposal,
        array $payload,
    ): void {
        DB::transaction(function () use ($message, $proposal, $payload): void {
            $lockedProposal = FederationCoalitionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail(
                $lockedProposal->federation_coalition_id,
            );
            $identity = $this->identity()->load('activeKey');

            if ($lockedProposal->status !== FederationWorkflowStatus::Approved
                || $lockedProposal->target_installation_id !== $identity->id
                || $message->sender_installation_id !== $coalition->coordinator_installation_id
                || (int) $lockedProposal->base_roster_revision !== (int) $coalition->roster_revision) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer approval is invalid.']);
            }

            $manifest = $payload['transfer_manifest'];
            $approval = $payload['transfer_approval'];
            $expectedManifest = $this->coordinatorTransferManifest($coalition, $lockedProposal);
            $expectedStatement = $this->coordinatorTransferStatement($coalition, $lockedProposal, $expectedManifest);

            if (! hash_equals(CanonicalJson::encode($expectedManifest), CanonicalJson::encode($manifest))
                || ! hash_equals(CanonicalJson::encode($expectedStatement), CanonicalJson::encode($approval['statement']))
                || ! hash_equals($approval['coordinator_key_id'], $message->sender_key_id)) {
                throw ValidationException::withMessages(['proposal' => 'The coordinator transfer manifest changed after approval.']);
            }

            $coordinatorKey = FederationPeerKey::query()
                ->whereHas('link', fn ($query) => $query->where('remote_installation_id', $message->sender_installation_id))
                ->where('remote_key_id', $approval['coordinator_key_id'])
                ->where('status', FederationKeyStatus::Active->value)
                ->first();

            if (! $coordinatorKey instanceof FederationPeerKey
                || ! $this->cryptography->verify(
                    $this->coordinatorTransferSignatureInput($expectedStatement),
                    $approval['coordinator_signature'],
                    $coordinatorKey->signing_public_key,
                )) {
                throw ValidationException::withMessages(['proposal' => 'The current coordinator transfer signature is invalid.']);
            }

            $destinationKey = $identity->activeKey;

            if (! $destinationKey instanceof FederationIdentityKey || $destinationKey->signing_private_key === null) {
                throw ValidationException::withMessages(['proposal' => 'The destination signing key is unavailable.']);
            }

            $manifest['transfer_proof'] = [
                'statement' => $expectedStatement,
                'previous_coordinator_key_id' => $coordinatorKey->remote_key_id,
                'previous_coordinator_signature' => $approval['coordinator_signature'],
                'new_coordinator_key_id' => $destinationKey->id,
                'new_coordinator_signature' => $this->cryptography->sign(
                    $this->coordinatorTransferSignatureInput($expectedStatement),
                    $destinationKey->signing_private_key,
                ),
            ];
            $coalition->forceFill([
                'coordinator_installation_id' => $identity->id,
                'roster_revision' => $manifest['revision'],
                'roster_hash' => $manifest['manifest_hash'],
                'canonical_manifest' => CanonicalJson::encode($manifest),
            ])->save();

            foreach ($manifest['members'] as $member) {
                $coalition->memberships()
                    ->where('installation_id', $member['installation_id'])
                    ->update([
                        'role' => $member['role'],
                        'status' => $member['status'],
                        'roster_revision' => $manifest['revision'],
                        'updated_at' => now(),
                    ]);
            }

            $lockedProposal->forceFill([
                'status' => FederationWorkflowStatus::Completed,
                'pending_key' => null,
                'reviewed_at' => now(),
            ])->save();
            $this->broadcastCoordinatorTransferManifest($coalition, $manifest);
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    private function coordinatorTransferManifest(
        FederationCoalition $coalition,
        FederationCoalitionProposal $proposal,
    ): array {
        $members = $coalition->memberships()
            ->orderBy('installation_id')
            ->get()
            ->map(function (FederationCoalitionMembership $membership) use ($coalition, $proposal): array {
                $role = match ($membership->installation_id) {
                    $proposal->target_installation_id => CoalitionRole::Coordinator,
                    $coalition->coordinator_installation_id => CoalitionRole::Admin,
                    default => $membership->role,
                };

                return [
                    'installation_id' => $membership->installation_id,
                    'role' => $role->value,
                    'status' => $membership->status->value,
                    'joined_at' => $membership->joined_at?->utc()->toIso8601String(),
                    'expires_at' => $membership->expires_at?->utc()->toIso8601String(),
                    'removed_at' => $membership->removed_at?->utc()->toIso8601String(),
                ];
            })
            ->all();

        return $this->manifestPayload(
            coalitionId: $coalition->id,
            name: $coalition->name,
            coordinatorInstallationId: (string) $proposal->target_installation_id,
            revision: (int) $coalition->roster_revision + 1,
            status: $coalition->status,
            expiresAt: $coalition->expires_at ? CarbonImmutable::instance($coalition->expires_at) : null,
            members: $members,
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function coordinatorTransferStatement(
        FederationCoalition $coalition,
        FederationCoalitionProposal $proposal,
        array $manifest,
    ): array {
        return [
            'proposal_id' => $proposal->id,
            'coalition_id' => $coalition->id,
            'base_roster_revision' => (int) $coalition->roster_revision,
            'base_roster_hash' => $coalition->roster_hash,
            'previous_coordinator_installation_id' => $coalition->coordinator_installation_id,
            'new_coordinator_installation_id' => (string) $proposal->target_installation_id,
            'manifest_hash' => $manifest['manifest_hash'],
        ];
    }

    /** @param  array<string, mixed>  $statement */
    private function coordinatorTransferSignatureInput(array $statement): string
    {
        return "nexus-federation:coalition-transfer-manifest:v1\0".CanonicalJson::encode($statement);
    }

    /** @param  array<string, mixed>  $manifest */
    private function broadcastCoordinatorTransferManifest(
        FederationCoalition $coalition,
        array $manifest,
    ): void {
        $coalition->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->with('link')
            ->get()
            ->each(function (FederationCoalitionMembership $membership) use ($manifest): void {
                $link = $membership->link;

                if ($link instanceof FederationLink && $link->status === FederationLinkStatus::Active) {
                    $this->outbox->queue(
                        link: $link,
                        type: FederationMessageType::CoalitionManifest,
                        payload: $manifest,
                        expiresAt: CarbonImmutable::now('UTC')->addDays(7),
                    );
                }
            });
    }

    /** @param  array<string, mixed>  $payload */
    private function assertCoordinatorTransferManifest(
        FederationCoalition $coalition,
        FederationInboxMessage $message,
        array $payload,
        bool $coordinatorChanged,
    ): void {
        if (! isset($payload['transfer_proof']) || ! is_array($payload['transfer_proof'])) {
            throw ValidationException::withMessages([
                'coalition' => 'A coordinator change requires a dual-signed transfer manifest.',
            ]);
        }

        $proof = $payload['transfer_proof'];
        $statement = $proof['statement'];
        $expectedStatement = [
            'proposal_id' => $statement['proposal_id'],
            'coalition_id' => $payload['coalition_id'],
            'base_roster_revision' => (int) $payload['revision'] - 1,
            'base_roster_hash' => $statement['base_roster_hash'],
            'previous_coordinator_installation_id' => $statement['previous_coordinator_installation_id'],
            'new_coordinator_installation_id' => $payload['coordinator_installation_id'],
            'manifest_hash' => $payload['manifest_hash'],
        ];

        if (! hash_equals(CanonicalJson::encode($expectedStatement), CanonicalJson::encode($statement))
            || ! hash_equals($payload['coordinator_installation_id'], $message->sender_installation_id)
            || ! hash_equals($proof['new_coordinator_key_id'], $message->sender_key_id)) {
            throw ValidationException::withMessages(['coalition' => 'The coordinator transfer statement is invalid.']);
        }

        if ($coordinatorChanged) {
            if ((int) $statement['base_roster_revision'] !== (int) $coalition->roster_revision
                || ! hash_equals($statement['base_roster_hash'], $coalition->roster_hash)
                || ! hash_equals(
                    $statement['previous_coordinator_installation_id'],
                    $coalition->coordinator_installation_id,
                )) {
                throw ValidationException::withMessages(['coalition' => 'The coordinator transfer is based on a stale roster.']);
            }
        } elseif ((int) $payload['revision'] !== (int) $coalition->roster_revision
            || ! hash_equals($payload['manifest_hash'], $coalition->roster_hash)) {
            throw ValidationException::withMessages(['coalition' => 'The coordinator transfer proof does not match the current roster.']);
        }

        $coordinatorMembers = collect($payload['members'])
            ->filter(fn (array $member): bool => $member['role'] === CoalitionRole::Coordinator->value)
            ->values();
        $previousCoordinator = collect($payload['members'])->first(
            fn (array $member): bool => $member['installation_id'] === $statement['previous_coordinator_installation_id'],
        );

        if ($coordinatorMembers->count() !== 1
            || $coordinatorMembers->first()['installation_id'] !== $payload['coordinator_installation_id']
            || ! is_array($previousCoordinator)
            || $previousCoordinator['role'] !== CoalitionRole::Admin->value) {
            throw ValidationException::withMessages(['coalition' => 'The coordinator transfer roster roles are invalid.']);
        }

        $signatureInput = $this->coordinatorTransferSignatureInput($statement);
        $previousPublicKey = $this->signingPublicKeyForInstallation(
            $statement['previous_coordinator_installation_id'],
            $proof['previous_coordinator_key_id'],
        );
        $newPublicKey = $this->signingPublicKeyForInstallation(
            $statement['new_coordinator_installation_id'],
            $proof['new_coordinator_key_id'],
        );

        if ($previousPublicKey === null
            || $newPublicKey === null
            || ! $this->cryptography->verify(
                $signatureInput,
                $proof['previous_coordinator_signature'],
                $previousPublicKey,
            )
            || ! $this->cryptography->verify(
                $signatureInput,
                $proof['new_coordinator_signature'],
                $newPublicKey,
            )) {
            throw ValidationException::withMessages(['coalition' => 'The coordinator transfer signatures are invalid.']);
        }

        $localProposal = FederationCoalitionProposal::query()->find($statement['proposal_id']);

        if ($localProposal instanceof FederationCoalitionProposal
            && (! hash_equals($localProposal->federation_coalition_id, $coalition->id)
                || ! hash_equals((string) $localProposal->target_installation_id, $payload['coordinator_installation_id'])
                || (int) $localProposal->base_roster_revision !== (int) $statement['base_roster_revision']
                || $localProposal->status === FederationWorkflowStatus::Rejected)) {
            throw ValidationException::withMessages(['coalition' => 'The coordinator transfer conflicts with the local proposal record.']);
        }
    }

    private function signingPublicKeyForInstallation(string $installationId, string $keyId): ?string
    {
        $identity = FederationIdentity::query()->firstOrFail();

        if (hash_equals($identity->id, $installationId)) {
            return FederationIdentityKey::query()
                ->where('identity_id', $identity->id)
                ->whereKey($keyId)
                ->whereNull('compromised_at')
                ->value('signing_public_key');
        }

        return FederationPeerKey::query()
            ->whereHas('link', fn ($query) => $query->where('remote_installation_id', $installationId))
            ->where('remote_key_id', $keyId)
            ->whereNull('compromised_at')
            ->value('signing_public_key');
    }

    private function expireCapabilities(
        FederationCoalition $coalition,
        ?string $installationId = null,
    ): void {
        FederationCapability::query()
            ->where('federation_coalition_id', $coalition->id)
            ->where('state', CapabilityState::Active->value)
            ->when($installationId !== null, function ($query) use ($installationId): void {
                $query->where(function ($nested) use ($installationId): void {
                    $nested->where('issuer_installation_id', $installationId)
                        ->orWhere('peer_installation_id', $installationId);
                });
            })
            ->update([
                'state' => CapabilityState::Expired->value,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function revokeLocalPublications(
        FederationCoalition $coalition,
        string $reasonCode,
        ?string $recipientInstallationId = null,
    ): void {
        $identity = $this->identity();
        $query = FederationPublication::query()
            ->where('federation_coalition_id', $coalition->id)
            ->where('source_installation_id', $identity->id)
            ->whereIn('status', [
                PublicationStatus::Published->value,
                PublicationStatus::PartiallyRevoked->value,
            ])
            ->whereHas('versions.deliveries', function ($deliveryQuery) use ($recipientInstallationId): void {
                if ($recipientInstallationId !== null) {
                    $deliveryQuery->where('recipient_installation_id', $recipientInstallationId);
                }
            });

        foreach ($query->with('coalition')->get() as $publication) {
            if ($recipientInstallationId !== null) {
                $this->publications->revokeRecipient($publication, $recipientInstallationId, $reasonCode, 0);
            } else {
                $this->publications->revokeAll($publication, $reasonCode, 0);
            }
        }
    }

    /** @return array<string, mixed> */
    private function invitationPayload(
        FederationCoalition $coalition,
        FederationCoalitionInvitation $invitation,
        string $token,
        string $action,
        ?string $actedAt,
    ): array {
        return [
            'action' => $action,
            'invitation_id' => $invitation->id,
            'invitation_token' => $token,
            'coalition_id' => $coalition->id,
            'coalition_name' => $coalition->name,
            'coordinator_installation_id' => $coalition->coordinator_installation_id,
            'role' => $invitation->role->value,
            'roster_revision' => (int) $coalition->roster_revision,
            'expires_at' => $invitation->expires_at->utc()->toIso8601String(),
            'acted_at' => $actedAt,
        ];
    }

    private function identity(): FederationIdentity
    {
        return FederationIdentity::query()->where('enabled', true)->firstOrFail();
    }

    private function assertLocalCoordinator(FederationCoalition $coalition): void
    {
        if ($coalition->status !== CoalitionStatus::Active
            || ! hash_equals($coalition->coordinator_installation_id, $this->identity()->id)
            || ($coalition->expires_at !== null && $coalition->expires_at->isPast())) {
            throw ValidationException::withMessages(['coalition' => 'This installation does not control an active coalition.']);
        }
    }

    private function assertToken(FederationCoalitionInvitation $invitation, string $token): void
    {
        if (! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['invitation' => 'Coalition invitation token is invalid.']);
        }
    }
}
