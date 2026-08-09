<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Support\CanonicalJson;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FederationCapabilityService
{
    public function __construct(
        private readonly FederationOutboxService $outbox,
        private readonly AuditLogger $audit,
        private readonly FederationReceivedWarPlanService $receivedPlans,
        private readonly WarPlanPublicationService $publications,
    ) {}

    public function set(
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        CapabilityState $state,
        ?CarbonImmutable $expiresAt,
        int $actorUserId,
    ): FederationCapability {
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $this->assertScope($coalition, $link, $identity->id);

        if ($state === CapabilityState::Active
            && $expiresAt !== null
            && ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages(['capability' => 'An active capability must not already be expired.']);
        }

        $capability = DB::transaction(function () use (
            $coalition,
            $link,
            $direction,
            $state,
            $expiresAt,
            $identity,
        ): FederationCapability {
            $lockedCoalition = FederationCoalition::query()->lockForUpdate()->findOrFail($coalition->id);
            $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($link->id);
            $this->assertScope($lockedCoalition, $lockedLink, $identity->id);
            $revision = ((int) FederationCapability::query()
                ->where('issuer_installation_id', $identity->id)
                ->where('peer_installation_id', $lockedLink->remote_installation_id)
                ->where('federation_coalition_id', $lockedCoalition->id)
                ->where('resource_type', FederationResourceType::WarPlanSnapshot->value)
                ->where('direction', $direction->value)
                ->lockForUpdate()
                ->max('revision')) + 1;
            $statement = [
                'peer_installation_id' => $lockedLink->remote_installation_id,
                'coalition_id' => $lockedCoalition->id,
                'resource_type' => FederationResourceType::WarPlanSnapshot->value,
                'direction' => $direction->value,
                'revision' => $revision,
                'state' => $state->value,
                'expires_at' => $expiresAt?->utc()->toIso8601String(),
            ];
            $statement['statement_hash'] = hash('sha256', CanonicalJson::encode($statement));
            $capability = FederationCapability::query()->create([
                'id' => (string) Str::ulid(),
                'issuer_installation_id' => $identity->id,
                'peer_installation_id' => $lockedLink->remote_installation_id,
                'federation_coalition_id' => $lockedCoalition->id,
                'resource_type' => FederationResourceType::WarPlanSnapshot,
                'direction' => $direction,
                'revision' => $revision,
                'state' => $state,
                'is_local' => true,
                'statement_hash' => $statement['statement_hash'],
                'canonical_statement' => CanonicalJson::encode($statement),
                'expires_at' => $expiresAt,
                'revoked_at' => $state !== CapabilityState::Active ? now() : null,
            ]);
            $this->outbox->queue(
                link: $lockedLink,
                type: FederationMessageType::CapabilityManifest,
                payload: [
                    'issuer_installation_id' => $identity->id,
                    'generated_at' => now()->utc()->toIso8601String(),
                    'statements' => [$statement],
                ],
                expiresAt: CarbonImmutable::now('UTC')->addDays(7),
            );

            return $capability;
        }, attempts: 5);

        $this->audit->success('federation', 'capability.changed', $capability, [
            'capability_id' => $capability->id,
            'coalition_id' => $coalition->id,
            'peer_installation_id' => $link->remote_installation_id,
            'direction' => $direction->value,
            'state' => $state->value,
            'revision' => $capability->revision,
            'actor_id' => $actorUserId,
        ]);

        if ($state !== CapabilityState::Active && $direction === CapabilityDirection::Inbound) {
            $this->receivedPlans->invalidateCoalition(
                $coalition->id,
                'capability_invalidated',
                $link->remote_installation_id,
            );
        }

        if ($state !== CapabilityState::Active && $direction === CapabilityDirection::Outbound) {
            $this->publications->revokeForRecipientScope(
                $coalition->id,
                $link->remote_installation_id,
                'outbound_capability_invalidated',
            );
        }

        return $capability;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveManifest(FederationInboxMessage $message, array $payload): void
    {
        if (! hash_equals($message->sender_installation_id, $payload['issuer_installation_id'])) {
            throw ValidationException::withMessages(['capability' => 'Capability issuer does not match the sender.']);
        }

        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->where('status', FederationLinkStatus::Active->value)
            ->firstOrFail();

        DB::transaction(function () use ($payload, $identity, $link): void {
            foreach ($payload['statements'] as $statement) {
                if (! hash_equals($statement['peer_installation_id'], $identity->id)) {
                    continue;
                }

                $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail($statement['coalition_id']);
                $this->assertScope($coalition, $link, $identity->id);
                $resourceType = FederationResourceType::tryFrom($statement['resource_type']);
                $direction = CapabilityDirection::tryFrom($statement['direction']);

                if ($resourceType !== FederationResourceType::WarPlanSnapshot || $direction === null) {
                    throw ValidationException::withMessages(['capability' => 'The capability resource or direction is unsupported.']);
                }
                $hashPayload = $statement;
                unset($hashPayload['statement_hash']);

                if (! hash_equals(
                    hash('sha256', CanonicalJson::encode($hashPayload)),
                    $statement['statement_hash'],
                )) {
                    throw ValidationException::withMessages(['capability' => 'Capability statement hash is invalid.']);
                }

                $latest = FederationCapability::query()
                    ->where('issuer_installation_id', $payload['issuer_installation_id'])
                    ->where('peer_installation_id', $identity->id)
                    ->where('federation_coalition_id', $coalition->id)
                    ->where('resource_type', $resourceType->value)
                    ->where('direction', $direction->value)
                    ->latest('revision')
                    ->lockForUpdate()
                    ->first();
                $latestRevision = (int) ($latest?->revision ?? 0);

                if ((int) $statement['revision'] < $latestRevision) {
                    continue;
                }

                if ((int) $statement['revision'] === $latestRevision) {
                    if ($latest instanceof FederationCapability
                        && hash_equals($latest->statement_hash, $statement['statement_hash'])) {
                        continue;
                    }

                    throw ValidationException::withMessages([
                        'capability' => 'A capability revision was received with different canonical contents.',
                    ]);
                }

                $statementExpiry = $statement['expires_at']
                    ? CarbonImmutable::parse($statement['expires_at'])
                    : null;
                $state = CapabilityState::from($statement['state']);

                if ($state === CapabilityState::Active
                    && $statementExpiry !== null
                    && $statementExpiry->isPast()) {
                    $state = CapabilityState::Expired;
                }

                FederationCapability::query()->create([
                    'id' => (string) Str::ulid(),
                    'issuer_installation_id' => $payload['issuer_installation_id'],
                    'peer_installation_id' => $identity->id,
                    'federation_coalition_id' => $coalition->id,
                    'resource_type' => $resourceType,
                    'direction' => $direction,
                    'revision' => $statement['revision'],
                    'state' => $state,
                    'is_local' => false,
                    'statement_hash' => $statement['statement_hash'],
                    'canonical_statement' => CanonicalJson::encode($statement),
                    'expires_at' => $statementExpiry,
                    'revoked_at' => $state !== CapabilityState::Active ? now() : null,
                ]);
            }
        }, attempts: 5);
    }

    public function current(
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        ?string $issuerInstallationId = null,
    ): ?FederationCapability {
        $identity = FederationIdentity::query()->where('enabled', true)->first();

        if (! $identity instanceof FederationIdentity || ! $this->scopeIsActive($coalition, $link, $identity->id)) {
            return null;
        }

        $issuerInstallationId ??= $identity->id;
        $peerInstallationId = $issuerInstallationId === $identity->id
            ? $link->remote_installation_id
            : $identity->id;

        return FederationCapability::query()
            ->where('issuer_installation_id', $issuerInstallationId)
            ->where('peer_installation_id', $peerInstallationId)
            ->where('federation_coalition_id', $coalition->id)
            ->where('resource_type', FederationResourceType::WarPlanSnapshot->value)
            ->where('direction', $direction->value)
            ->latest('revision')
            ->first();
    }

    public function allows(
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        ?string $issuerInstallationId = null,
    ): bool {
        $capability = $this->current($coalition, $link, $direction, $issuerInstallationId);

        return $capability instanceof FederationCapability
            && $capability->state === CapabilityState::Active
            && ($capability->expires_at === null || $capability->expires_at->isFuture());
    }

    public function assertAllows(
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        ?string $issuerInstallationId = null,
    ): void {
        if (! $this->allows($coalition, $link, $direction, $issuerInstallationId)) {
            throw ValidationException::withMessages(['capability' => 'The requested federation capability is denied.']);
        }
    }

    public function setCapability(
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        CapabilityState $state,
        ?CarbonImmutable $expiresAt,
        int $actorUserId,
    ): FederationCapability {
        return $this->set($coalition, $link, $direction, $state, $expiresAt, $actorUserId);
    }

    private function assertScope(FederationCoalition $coalition, FederationLink $link, string $localId): void
    {
        if (! $this->scopeIsActive($coalition, $link, $localId)) {
            throw ValidationException::withMessages([
                'capability' => 'Capabilities require an active link and common active coalition membership.',
            ]);
        }
    }

    private function scopeIsActive(
        FederationCoalition $coalition,
        FederationLink $link,
        string $localId,
    ): bool {
        return $coalition->status === CoalitionStatus::Active
            && ($coalition->expires_at === null || $coalition->expires_at->isFuture())
            && $link->status === FederationLinkStatus::Active
            && $coalition->memberships()
                ->where('installation_id', $localId)
                ->where('status', MembershipStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists()
            && $coalition->memberships()
                ->where('installation_id', $link->remote_installation_id)
                ->where('status', MembershipStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
    }
}
