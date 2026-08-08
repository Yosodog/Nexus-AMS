<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
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

        DB::transaction(function () use ($message, $payload): void {
            $link = FederationLink::query()
                ->where('remote_installation_id', $message->sender_installation_id)
                ->where('status', FederationLinkStatus::Active->value)
                ->firstOrFail();

            if (! hash_equals($payload['coordinator_installation_id'], $message->sender_installation_id)) {
                throw ValidationException::withMessages(['coalition' => 'Only the coordinator may issue invitations.']);
            }

            $coalition = FederationCoalition::query()->firstOrCreate(
                ['id' => $payload['coalition_id']],
                [
                    'name' => $payload['coalition_name'],
                    'coordinator_installation_id' => $payload['coordinator_installation_id'],
                    'status' => CoalitionStatus::Active,
                    'roster_revision' => $payload['roster_revision'],
                    'roster_hash' => hash('sha256', CanonicalJson::encode($payload)),
                    'canonical_manifest' => CanonicalJson::encode($payload),
                    'expires_at' => null,
                ]
            );
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
                    'expires_at' => CarbonImmutable::parse($payload['expires_at']),
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
            $payload = StrictJson::decodeObject((string) $source->decrypted_payload);
            $this->assertToken($pending, $payload['invitation_token']);
            $coalition = FederationCoalition::query()->findOrFail($pending->federation_coalition_id);
            $link = FederationLink::query()->findOrFail($pending->federation_link_id);
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

            if (! hash_equals($invitation->installation_id, $message->sender_installation_id)) {
                throw ValidationException::withMessages(['invitation' => 'Coalition acceptance sender does not match.']);
            }

            $membership = $coalition->memberships()
                ->where('installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->firstOrFail();
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

            if (! hash_equals($coalition->coordinator_installation_id, $message->sender_installation_id)
                || ! hash_equals($payload['coordinator_installation_id'], $message->sender_installation_id)) {
                throw ValidationException::withMessages(['coalition' => 'Coalition manifest was not issued by its coordinator.']);
            }

            if ((int) $payload['revision'] <= (int) $coalition->roster_revision) {
                return;
            }

            $hashPayload = $payload;
            unset($hashPayload['manifest_hash']);
            $hash = hash('sha256', CanonicalJson::encode($hashPayload));

            if (! hash_equals($hash, $payload['manifest_hash'])) {
                throw ValidationException::withMessages(['coalition' => 'Coalition manifest hash is invalid.']);
            }

            $identity = $this->identity();
            $containsLocal = collect($payload['members'])->contains(
                fn (array $member): bool => $member['installation_id'] === $identity->id
                    && $member['status'] === MembershipStatus::Active->value
            );

            if (! $containsLocal) {
                throw ValidationException::withMessages(['coalition' => 'Coalition manifest does not include this installation.']);
            }

            $coalition->forceFill([
                'name' => $payload['name'],
                'status' => CoalitionStatus::from($payload['status']),
                'roster_revision' => $payload['revision'],
                'roster_hash' => $payload['manifest_hash'],
                'canonical_manifest' => CanonicalJson::encode($payload),
                'expires_at' => $payload['expires_at'] ? CarbonImmutable::parse($payload['expires_at']) : null,
            ])->save();

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
        }, attempts: 5);
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
            $locked = FederationCoalitionMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $locked->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
            ])->save();
            $this->issueRoster(FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id));
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
            $revision = (int) $locked->roster_revision + 1;
            $locked->forceFill([
                'status' => CoalitionStatus::Dissolved,
                'roster_revision' => $revision,
                'dissolved_at' => now(),
            ])->save();

            foreach ($locked->memberships()->where('status', MembershipStatus::Active->value)->get() as $member) {
                if ($member->federation_link_id === null) {
                    continue;
                }

                $this->outbox->queue(
                    FederationLink::query()->findOrFail($member->federation_link_id),
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

    private function issueRoster(FederationCoalition $coalition): void
    {
        $revision = (int) $coalition->roster_revision + 1;
        $members = $coalition->memberships()->orderBy('installation_id')->get()->map(
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

        foreach ($coalition->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('federation_link_id')
            ->get() as $member) {
            $link = FederationLink::query()->find($member->federation_link_id);

            if ($link?->status === FederationLinkStatus::Active) {
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
