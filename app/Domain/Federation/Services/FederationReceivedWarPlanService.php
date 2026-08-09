<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\DeliveryState;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Jobs\ImportFederatedWarPlanJob;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationPublicationVersion;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FederationReceivedWarPlanService
{
    public function __construct(
        private readonly FederationAuthorizationService $authorization,
        private readonly FederationOutboxService $outbox,
        private readonly FederationHoldService $holds,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function store(FederationInboxMessage $message, array $payload): ?FederationReceivedVersion
    {
        $snapshot = WarPlanSnapshotV1::fromArray($payload);
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();

        if (! hash_equals($message->sender_installation_id, $snapshot->sourceInstallationId)
            || ! hash_equals($identity->id, $snapshot->recipientInstallationId)
            || $message->resource_schema !== WarPlanSnapshotV1::SCHEMA
            || ! hash_equals((string) $message->decrypted_payload, $snapshot->toJson())) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        $now = CarbonImmutable::now('UTC');
        $maximumExpiry = $now->addDays(
            max((int) config('federation.publication_max_expiry_days', 30), 1)
        );
        $clockSkew = max((int) config('federation.clock_skew_seconds', 300), 0);

        if ($snapshot->expiresAt->isPast()) {
            throw new FederationProtocolException(FederationErrorCode::MessageExpired, 410);
        }

        if ($snapshot->expiresAt->isAfter($maximumExpiry)
            || $snapshot->expiresAt->isAfter(CarbonImmutable::instance($message->expires_at))
            || $snapshot->publishedAt->isAfter($now->addSeconds($clockSkew))) {
            throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope);
        }

        $link = FederationLink::query()
            ->where('remote_installation_id', $snapshot->sourceInstallationId)
            ->where('status', FederationLinkStatus::Active->value)
            ->first();
        $coalition = FederationCoalition::query()->find($snapshot->coalitionId);

        if (! $link instanceof FederationLink) {
            throw new FederationProtocolException(FederationErrorCode::LinkInactive, 403);
        }

        if (! $coalition instanceof FederationCoalition) {
            throw new FederationProtocolException(FederationErrorCode::TemporaryUnavailable, 503);
        }

        if ($snapshot->rosterRevision > (int) $coalition->roster_revision) {
            throw new FederationProtocolException(FederationErrorCode::TemporaryUnavailable, 503);
        }

        $this->authorization->assertCanReceive($coalition, $link);

        return DB::transaction(function () use ($snapshot, $link, $coalition, $message): ?FederationReceivedVersion {
            $this->authorization->assertCanReceive($coalition, $link, lockForUpdate: true);
            $resource = FederationReceivedResource::query()
                ->where('source_installation_id', $snapshot->sourceInstallationId)
                ->where('source_publication_id', $snapshot->publicationId)
                ->lockForUpdate()
                ->first();

            if ($resource instanceof FederationReceivedResource
                && in_array($resource->state, [ReceivedResourceState::Revoked, ReceivedResourceState::Expired], true)) {
                throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
            }

            $duplicate = FederationReceivedVersion::query()
                ->where('source_installation_id', $snapshot->sourceInstallationId)
                ->where('source_publication_id', $snapshot->publicationId)
                ->where('source_version_id', $snapshot->versionId)
                ->lockForUpdate()
                ->first();

            if ($duplicate instanceof FederationReceivedVersion) {
                if (! hash_equals($duplicate->payload_hash, $message->payload_hash)
                    || (int) $duplicate->revision !== $snapshot->revision
                    || (int) $duplicate->version !== $snapshot->version) {
                    throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
                }

                return $duplicate;
            }

            if ($resource instanceof FederationReceivedResource
                && $snapshot->revision <= (int) $resource->current_revision) {
                return null;
            }

            $resource ??= FederationReceivedResource::query()->create([
                'id' => (string) Str::ulid(),
                'federation_link_id' => $link->id,
                'source_installation_id' => $snapshot->sourceInstallationId,
                'source_publication_id' => $snapshot->publicationId,
                'coalition_id' => $snapshot->coalitionId,
                'resource_type' => FederationResourceType::WarPlanSnapshot,
                'state' => ReceivedResourceState::PendingReview,
                'current_version' => 0,
                'current_revision' => 0,
                'expires_at' => $snapshot->expiresAt,
            ]);

            if (! hash_equals($resource->coalition_id, $snapshot->coalitionId)
                || ! hash_equals($resource->federation_link_id, $link->id)) {
                throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
            }

            $version = FederationReceivedVersion::query()->create([
                'id' => (string) Str::ulid(),
                'federation_received_resource_id' => $resource->id,
                'source_installation_id' => $snapshot->sourceInstallationId,
                'source_publication_id' => $snapshot->publicationId,
                'source_version_id' => $snapshot->versionId,
                'version' => $snapshot->version,
                'revision' => $snapshot->revision,
                'source_generation' => $snapshot->sourceGeneration,
                'roster_revision' => $snapshot->rosterRevision,
                'schema_version' => '1.0',
                'canonical_payload' => (string) $message->decrypted_payload,
                'payload_hash' => $message->payload_hash,
                'payload_bytes' => strlen((string) $message->decrypted_payload),
                'disposition' => ReceivedDisposition::Pending,
                'import_state' => ImportState::NotRequested,
                'expires_at' => $snapshot->expiresAt,
            ]);
            $resource->forceFill([
                'state' => ReceivedResourceState::PendingReview,
                'current_version' => $snapshot->version,
                'current_revision' => $snapshot->revision,
                'expires_at' => $snapshot->expiresAt,
            ])->save();

            return $version;
        }, attempts: 5);
    }

    public function accept(FederationReceivedVersion $version, int $actorUserId): FederationReceivedVersion
    {
        $accepted = DB::transaction(function () use ($version, $actorUserId): FederationReceivedVersion {
            $locked = FederationReceivedVersion::query()->lockForUpdate()->findOrFail($version->id);
            $resource = FederationReceivedResource::query()->lockForUpdate()->findOrFail(
                $locked->federation_received_resource_id
            );

            if ($locked->disposition === ReceivedDisposition::Accepted) {
                return $locked;
            }

            if ($locked->disposition !== ReceivedDisposition::Pending
                || (int) $resource->current_revision !== (int) $locked->revision
                || $resource->state === ReceivedResourceState::Revoked
                || $locked->expires_at->isPast()
                || $locked->canonical_payload === null) {
                throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
            }

            $link = FederationLink::query()->findOrFail($resource->federation_link_id);
            $coalition = FederationCoalition::query()->findOrFail($resource->coalition_id);
            $this->authorization->assertCanReceive($coalition, $link, lockForUpdate: true);
            $locked->forceFill([
                'disposition' => ReceivedDisposition::Accepted,
                'import_state' => ImportState::Queued,
                'reviewed_by' => $actorUserId,
                'accepted_at' => now(),
            ])->save();
            $resource->forceFill(['state' => ReceivedResourceState::Accepted])->save();
            $this->queueDisposition($link, $locked, ReceivedDisposition::Accepted);
            ImportFederatedWarPlanJob::dispatch($locked->id)->afterCommit();

            return $locked;
        }, attempts: 5);

        $this->audit->success('federation', 'war_plan.accepted', $accepted, [
            'publication_id' => $accepted->source_publication_id,
            'version' => $accepted->version,
            'revision' => $accepted->revision,
            'actor_id' => $actorUserId,
        ]);

        return $accepted;
    }

    public function reject(FederationReceivedVersion $version, int $actorUserId): FederationReceivedVersion
    {
        $rejected = DB::transaction(function () use ($version, $actorUserId): FederationReceivedVersion {
            $locked = FederationReceivedVersion::query()->lockForUpdate()->findOrFail($version->id);
            $resource = FederationReceivedResource::query()->lockForUpdate()->findOrFail(
                $locked->federation_received_resource_id
            );

            if ($locked->disposition === ReceivedDisposition::Rejected) {
                return $locked;
            }

            if ($locked->disposition !== ReceivedDisposition::Pending) {
                throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
            }

            $link = FederationLink::query()->findOrFail($resource->federation_link_id);
            $locked->forceFill([
                'disposition' => ReceivedDisposition::Rejected,
                'import_state' => ImportState::NotRequested,
                'reviewed_by' => $actorUserId,
                'rejected_at' => now(),
                'canonical_payload' => null,
                'payload_purged_at' => now(),
            ])->save();

            if ((int) $resource->current_version === (int) $locked->version) {
                $resource->forceFill([
                    'state' => ReceivedResourceState::Rejected,
                    'payload_purged_at' => now(),
                ])->save();
            }

            $this->queueDisposition($link, $locked, ReceivedDisposition::Rejected);

            return $locked;
        }, attempts: 5);

        $this->audit->success('federation', 'war_plan.rejected', $rejected, [
            'publication_id' => $rejected->source_publication_id,
            'version' => $rejected->version,
            'revision' => $rejected->revision,
            'actor_id' => $actorUserId,
        ]);

        return $rejected;
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveDisposition(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $publication = FederationPublication::query()->lockForUpdate()->find($payload['publication_id']);

            if (! $publication instanceof FederationPublication) {
                return;
            }

            $version = FederationPublicationVersion::query()
                ->where('federation_publication_id', $publication->id)
                ->where('id', $payload['version_id'])
                ->where('version', $payload['version'])
                ->where('revision', $payload['revision'])
                ->first();

            if (! $version instanceof FederationPublicationVersion) {
                return;
            }

            $delivery = FederationPublicationDelivery::query()
                ->where('federation_publication_version_id', $version->id)
                ->where('recipient_installation_id', $message->sender_installation_id)
                ->lockForUpdate()
                ->first();

            if (! $delivery instanceof FederationPublicationDelivery) {
                return;
            }

            $state = match ($payload['disposition']) {
                ReceivedDisposition::Accepted->value => DeliveryState::Accepted,
                ReceivedDisposition::Rejected->value => DeliveryState::Rejected,
                default => throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope),
            };

            if ($delivery->state === DeliveryState::Revoked
                || $delivery->access_revocation_revision !== null
                || $publication->status === PublicationStatus::Revoked) {
                return;
            }

            if (in_array($delivery->state, [DeliveryState::Accepted, DeliveryState::Rejected], true)) {
                if ($delivery->state === $state) {
                    return;
                }

                throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
            }

            $delivery->forceFill([
                'state' => $state,
                'acknowledged_at' => CarbonImmutable::parse($payload['acknowledged_at']),
            ])->save();
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    public function receiveDeliveryReceipt(FederationInboxMessage $message, array $payload): void
    {
        DB::transaction(function () use ($message, $payload): void {
            $outbox = FederationOutboxMessage::query()
                ->where('recipient_installation_id', $message->sender_installation_id)
                ->where('message_id', $payload['original_message_id'])
                ->lockForUpdate()
                ->first();

            if (! $outbox instanceof FederationOutboxMessage) {
                return;
            }

            if (! in_array($outbox->status, [OutboxStatus::Failed, OutboxStatus::Expired], true)) {
                $outbox->forceFill([
                    'status' => OutboxStatus::Validated,
                    'validated_at' => CarbonImmutable::parse($payload['received_at']),
                    'safe_error_code' => null,
                ])->save();
            }

            FederationPublicationDelivery::query()
                ->where('outbox_message_id', $outbox->message_id)
                ->where('recipient_installation_id', $message->sender_installation_id)
                ->whereIn('state', [
                    DeliveryState::Pending->value,
                    DeliveryState::TransportAccepted->value,
                    DeliveryState::Failed->value,
                ])
                ->update([
                    'state' => DeliveryState::Validated->value,
                    'validated_at' => CarbonImmutable::parse($payload['received_at']),
                    'updated_at' => now(),
                ]);
        }, attempts: 5);
    }

    /** @param  array<string, mixed>  $payload */
    public function revoke(FederationInboxMessage $message, array $payload, bool $accessOnly): void
    {
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();

        if ($accessOnly && ! hash_equals($payload['recipient_installation_id'], $identity->id)) {
            throw new FederationProtocolException(FederationErrorCode::RecipientMismatch, 403);
        }

        $resource = DB::transaction(function () use ($message, $payload): ?FederationReceivedResource {
            $resource = FederationReceivedResource::query()
                ->where('source_installation_id', $message->sender_installation_id)
                ->where('source_publication_id', $payload['publication_id'])
                ->lockForUpdate()
                ->first();

            if (! $resource instanceof FederationReceivedResource) {
                $link = FederationLink::query()
                    ->where('remote_installation_id', $message->sender_installation_id)
                    ->firstOrFail();

                return FederationReceivedResource::query()->create([
                    'id' => (string) Str::ulid(),
                    'federation_link_id' => $link->id,
                    'source_installation_id' => $message->sender_installation_id,
                    'source_publication_id' => $payload['publication_id'],
                    'coalition_id' => null,
                    'resource_type' => FederationResourceType::WarPlanSnapshot,
                    'state' => ReceivedResourceState::Revoked,
                    'current_version' => 0,
                    'current_revision' => $payload['revision'],
                    'expires_at' => $message->expires_at,
                    'revoked_at' => CarbonImmutable::parse($payload['revoked_at']),
                    'payload_purged_at' => now(),
                ]);
            }

            if ((int) $payload['revision'] <= (int) $resource->current_revision) {
                return null;
            }

            $resource->versions()->update([
                'canonical_payload' => null,
                'payload_purged_at' => now(),
                'updated_at' => now(),
            ]);
            $resource->forceFill([
                'state' => ReceivedResourceState::Revoked,
                'current_revision' => $payload['revision'],
                'revoked_at' => CarbonImmutable::parse($payload['revoked_at']),
                'payload_purged_at' => now(),
            ])->save();

            return $resource;
        }, attempts: 5);

        if ($resource instanceof FederationReceivedResource) {
            $this->holds->placeForResource($resource, (string) $payload['reason_code']);
        }
    }

    /** @param  array<string, mixed>  $manifestResource */
    public function recordUnknownManifestTombstone(
        FederationLink $link,
        array $manifestResource,
    ): FederationReceivedResource {
        $state = match ($manifestResource['state']) {
            'revoked' => ReceivedResourceState::Revoked,
            'expired' => ReceivedResourceState::Expired,
            default => throw new FederationProtocolException(FederationErrorCode::InvalidEnvelope),
        };

        return DB::transaction(function () use ($link, $manifestResource, $state): FederationReceivedResource {
            $resource = FederationReceivedResource::query()
                ->where('source_installation_id', $link->remote_installation_id)
                ->where('source_publication_id', $manifestResource['resource_id'])
                ->lockForUpdate()
                ->first();

            if ($resource instanceof FederationReceivedResource) {
                return $resource;
            }

            return FederationReceivedResource::query()->create([
                'id' => (string) Str::ulid(),
                'federation_link_id' => $link->id,
                'source_installation_id' => $link->remote_installation_id,
                'source_publication_id' => $manifestResource['resource_id'],
                'coalition_id' => null,
                'resource_type' => FederationResourceType::WarPlanSnapshot,
                'state' => $state,
                'current_version' => 0,
                'current_revision' => $manifestResource['highest_revision'],
                'expires_at' => $manifestResource['expires_at'] !== null
                    ? CarbonImmutable::parse($manifestResource['expires_at'])
                    : now(),
                'revoked_at' => $state === ReceivedResourceState::Revoked ? now() : null,
                'payload_purged_at' => now(),
            ]);
        }, attempts: 5);
    }

    public function invalidateCoalition(
        string $coalitionId,
        string $reasonCode,
        ?string $sourceInstallationId = null,
    ): int {
        $resources = FederationReceivedResource::query()
            ->where('coalition_id', $coalitionId)
            ->when(
                $sourceInstallationId !== null,
                fn ($query) => $query->where('source_installation_id', $sourceInstallationId)
            )
            ->whereNotIn('state', [
                ReceivedResourceState::Revoked->value,
                ReceivedResourceState::Expired->value,
            ])
            ->get();
        $affected = 0;

        foreach ($resources as $resource) {
            DB::transaction(function () use ($resource, $reasonCode, &$affected): void {
                $locked = FederationReceivedResource::query()->lockForUpdate()->findOrFail($resource->id);
                $locked->versions()->update([
                    'canonical_payload' => null,
                    'payload_purged_at' => now(),
                    'updated_at' => now(),
                ]);
                $locked->forceFill([
                    'state' => $reasonCode === 'resource_expired'
                        ? ReceivedResourceState::Expired
                        : ReceivedResourceState::Revoked,
                    'revoked_at' => $reasonCode === 'resource_expired' ? null : now(),
                    'payload_purged_at' => now(),
                ])->save();
                $this->holds->placeForResource($locked, $reasonCode);
                $affected++;
            }, attempts: 5);
        }

        return $affected;
    }

    public function revokeFromManifest(
        FederationReceivedResource $resource,
        int $revision,
        string $state,
    ): void {
        $locked = DB::transaction(function () use ($resource, $revision, $state): ?FederationReceivedResource {
            $locked = FederationReceivedResource::query()->lockForUpdate()->findOrFail($resource->id);

            if ($revision <= (int) $locked->current_revision
                || ! in_array($state, ['revoked', 'expired'], true)) {
                return null;
            }

            $locked->versions()->update([
                'canonical_payload' => null,
                'payload_purged_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->forceFill([
                'state' => $state === 'expired'
                    ? ReceivedResourceState::Expired
                    : ReceivedResourceState::Revoked,
                'current_revision' => $revision,
                'revoked_at' => $state === 'revoked' ? now() : null,
                'payload_purged_at' => now(),
            ])->save();

            return $locked;
        }, attempts: 5);

        if ($locked instanceof FederationReceivedResource) {
            $this->holds->placeForResource($locked, 'reconciliation_'.$state);
        }
    }

    private function queueDisposition(
        FederationLink $link,
        FederationReceivedVersion $version,
        ReceivedDisposition $disposition,
    ): void {
        $this->outbox->queue(
            link: $link,
            type: FederationMessageType::ResourceAcknowledged,
            payload: [
                'publication_id' => $version->source_publication_id,
                'version_id' => $version->source_version_id,
                'version' => (int) $version->version,
                'revision' => (int) $version->revision,
                'disposition' => $disposition->value,
                'acknowledged_at' => now()->utc()->toIso8601String(),
            ],
            expiresAt: CarbonImmutable::now('UTC')->addDays(7),
        );
    }
}
