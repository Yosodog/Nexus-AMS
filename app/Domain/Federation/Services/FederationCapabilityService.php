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

        $capability = DB::transaction(function () use (
            $coalition,
            $link,
            $direction,
            $state,
            $expiresAt,
            $identity,
        ): FederationCapability {
            $revision = ((int) FederationCapability::query()
                ->where('issuer_installation_id', $identity->id)
                ->where('peer_installation_id', $link->remote_installation_id)
                ->where('federation_coalition_id', $coalition->id)
                ->where('resource_type', FederationResourceType::WarPlanSnapshot->value)
                ->where('direction', $direction->value)
                ->lockForUpdate()
                ->max('revision')) + 1;
            $statement = [
                'peer_installation_id' => $link->remote_installation_id,
                'coalition_id' => $coalition->id,
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
                'peer_installation_id' => $link->remote_installation_id,
                'federation_coalition_id' => $coalition->id,
                'resource_type' => FederationResourceType::WarPlanSnapshot,
                'direction' => $direction,
                'revision' => $revision,
                'state' => $state,
                'is_local' => true,
                'statement_hash' => $statement['statement_hash'],
                'canonical_statement' => CanonicalJson::encode($statement),
                'expires_at' => $expiresAt,
                'revoked_at' => $state === CapabilityState::Revoked ? now() : null,
            ]);
            $this->outbox->queue(
                link: $link,
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

                $coalition = FederationCoalition::query()->findOrFail($statement['coalition_id']);
                $this->assertScope($coalition, $link, $identity->id);
                $hashPayload = $statement;
                unset($hashPayload['statement_hash']);

                if (! hash_equals(
                    hash('sha256', CanonicalJson::encode($hashPayload)),
                    $statement['statement_hash'],
                )) {
                    throw ValidationException::withMessages(['capability' => 'Capability statement hash is invalid.']);
                }

                $latestRevision = (int) FederationCapability::query()
                    ->where('issuer_installation_id', $payload['issuer_installation_id'])
                    ->where('peer_installation_id', $identity->id)
                    ->where('federation_coalition_id', $coalition->id)
                    ->where('resource_type', $statement['resource_type'])
                    ->where('direction', $statement['direction'])
                    ->lockForUpdate()
                    ->max('revision');

                if ((int) $statement['revision'] <= $latestRevision) {
                    continue;
                }

                FederationCapability::query()->create([
                    'id' => (string) Str::ulid(),
                    'issuer_installation_id' => $payload['issuer_installation_id'],
                    'peer_installation_id' => $identity->id,
                    'federation_coalition_id' => $coalition->id,
                    'resource_type' => FederationResourceType::from($statement['resource_type']),
                    'direction' => CapabilityDirection::from($statement['direction']),
                    'revision' => $statement['revision'],
                    'state' => CapabilityState::from($statement['state']),
                    'is_local' => false,
                    'statement_hash' => $statement['statement_hash'],
                    'canonical_statement' => CanonicalJson::encode($statement),
                    'expires_at' => $statement['expires_at']
                        ? CarbonImmutable::parse($statement['expires_at'])
                        : null,
                    'revoked_at' => $statement['state'] === CapabilityState::Revoked->value ? now() : null,
                ]);
            }
        }, attempts: 5);
    }

    private function assertScope(FederationCoalition $coalition, FederationLink $link, string $localId): void
    {
        if ($coalition->status !== CoalitionStatus::Active
            || ($coalition->expires_at !== null && $coalition->expires_at->isPast())
            || $link->status !== FederationLinkStatus::Active
            || ! $coalition->memberships()
                ->where('installation_id', $localId)
                ->where('status', MembershipStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists()
            || ! $coalition->memberships()
                ->where('installation_id', $link->remote_installation_id)
                ->where('status', MembershipStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists()) {
            throw ValidationException::withMessages([
                'capability' => 'Capabilities require an active link and common active coalition membership.',
            ]);
        }
    }
}
