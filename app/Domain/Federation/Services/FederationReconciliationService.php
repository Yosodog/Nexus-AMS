<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\DeliveryState;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationReceivedResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FederationReconciliationService
{
    public function __construct(
        private readonly FederationOutboxService $outbox,
        private readonly FederationReceivedWarPlanService $receivedPlans,
        private readonly FederationAuthorizationService $authorization,
        private readonly WarPlanPublicationService $publications,
    ) {}

    public function send(FederationLink $link): void
    {
        if ($link->status !== FederationLinkStatus::Active) {
            return;
        }

        $this->outbox->queue(
            link: $link,
            type: FederationMessageType::ReconciliationManifest,
            payload: [
                'generated_at' => now()->utc()->toIso8601String(),
                'resources' => $this->resourcesFor($link),
            ],
            expiresAt: CarbonImmutable::now('UTC')->addMinutes(30),
        );
    }

    /** @param  array<string, mixed>  $payload */
    public function receive(FederationInboxMessage $message, array $payload): void
    {
        $link = FederationLink::query()
            ->where('remote_installation_id', $message->sender_installation_id)
            ->whereIn('status', [
                FederationLinkStatus::Active->value,
                FederationLinkStatus::Suspended->value,
            ])
            ->first();

        if (! $link instanceof FederationLink) {
            throw new FederationProtocolException(FederationErrorCode::UnknownPeer, 404);
        }

        foreach ($payload['resources'] as $remote) {
            if ($remote['resource_type'] !== FederationResourceType::WarPlanSnapshot->value
                || (int) $remote['highest_revision'] < 1) {
                throw new FederationProtocolException(FederationErrorCode::SchemaUnsupported, 422);
            }

            $publication = FederationPublication::query()->find($remote['resource_id']);

            if ($publication instanceof FederationPublication) {
                $this->reconcilePublication($link, $publication, $remote);

                continue;
            }

            $received = FederationReceivedResource::query()
                ->where('federation_link_id', $link->id)
                ->where('source_publication_id', $remote['resource_id'])
                ->first();

            if (! $received instanceof FederationReceivedResource) {
                if (in_array($remote['state'], ['revoked', 'expired'], true)) {
                    $this->receivedPlans->recordUnknownManifestTombstone($link, $remote);
                }

                continue;
            }

            if ((int) $remote['highest_revision'] === (int) $received->current_revision) {
                $localHash = $this->receivedHash($received);

                if (! hash_equals($localHash, $remote['hash'])) {
                    throw new FederationProtocolException(FederationErrorCode::VersionConflict, 409);
                }
            }

            if (in_array($remote['state'], ['revoked', 'expired'], true)) {
                $this->receivedPlans->revokeFromManifest(
                    $received,
                    (int) $remote['highest_revision'],
                    $remote['state'],
                );
            }
        }

        $link->forceFill([
            'last_contact_at' => now(),
            'last_reconciled_at' => now(),
        ])->save();
    }

    /** @return list<array<string, mixed>> */
    private function resourcesFor(FederationLink $link): array
    {
        $published = FederationPublication::query()
            ->whereHas('versions', fn ($query) => $query
                ->where('status', 'published')
                ->whereHas('deliveries', fn ($deliveryQuery) => $deliveryQuery
                    ->where('federation_link_id', $link->id)))
            ->with([
                'versions' => fn ($query) => $query->where('status', 'published')->orderBy('version'),
                'versions.deliveries.version',
            ])
            ->get()
            ->map(function (FederationPublication $publication) use ($link): array {
                $delivery = $publication->versions
                    ->flatMap->deliveries
                    ->where('federation_link_id', $link->id)
                    ->sortByDesc(fn (FederationPublicationDelivery $candidate): int => (int) $candidate->version->revision)
                    ->first();
                $state = $delivery?->state === DeliveryState::Revoked
                    ? 'revoked'
                    : $publication->status->value;
                $revision = $state === 'revoked'
                    ? (int) ($delivery?->access_revocation_revision ?? $publication->current_revision)
                    : (int) ($delivery?->version->revision ?? 0);

                return [
                    'resource_type' => $publication->resource_type->value,
                    'resource_id' => $publication->id,
                    'highest_revision' => $revision,
                    'hash' => $state === 'revoked'
                        ? hash('sha256', $publication->id.':'.$revision.':'.$state)
                        : (string) $delivery?->payload_hash,
                    'state' => $state,
                    'expires_at' => $delivery?->version->expires_at?->utc()->toIso8601String()
                        ?? $publication->expires_at?->utc()->toIso8601String(),
                    'missing_versions' => [],
                ];
            });
        $received = FederationReceivedResource::query()
            ->where('federation_link_id', $link->id)
            ->with('versions')
            ->get()
            ->map(function (FederationReceivedResource $resource): array {
                $missing = $this->missingVersions($resource);

                return [
                    'resource_type' => $resource->resource_type->value,
                    'resource_id' => $resource->source_publication_id,
                    'highest_revision' => (int) $resource->current_revision,
                    'hash' => $this->receivedHash($resource),
                    'state' => $resource->state->value,
                    'expires_at' => $resource->expires_at?->utc()->toIso8601String(),
                    'missing_versions' => $missing,
                ];
            });

        return $published
            ->concat($received)
            ->sortBy(fn (array $resource): string => $resource['resource_type'].':'.$resource['resource_id'])
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $remote */
    private function reconcilePublication(
        FederationLink $link,
        FederationPublication $publication,
        array $remote,
    ): void {
        $deliveries = FederationPublicationDelivery::query()
            ->whereHas('version', fn ($query) => $query
                ->where('federation_publication_id', $publication->id)
                ->where('status', 'published'))
            ->where('federation_link_id', $link->id)
            ->with('version')
            ->get();

        if ($deliveries->isEmpty()) {
            return;
        }

        if ($publication->status === PublicationStatus::Revoked) {
            if ((int) $remote['highest_revision'] < (int) $publication->current_revision
                || $remote['state'] !== 'revoked') {
                $this->outbox->queue(
                    $link,
                    FederationMessageType::ResourceRevoked,
                    [
                        'publication_id' => $publication->id,
                        'revision' => (int) $publication->current_revision,
                        'reason_code' => 'reconciliation_tombstone',
                        'revoked_at' => $publication->revoked_at?->utc()->toIso8601String()
                            ?? now()->utc()->toIso8601String(),
                    ],
                    CarbonImmutable::now('UTC')->addDays(7),
                );
            }

            return;
        }

        $latestDelivery = $deliveries->sortByDesc(fn ($delivery): int => (int) $delivery->version->revision)->first();

        if ($latestDelivery?->state === DeliveryState::Revoked) {
            $revision = (int) ($latestDelivery->access_revocation_revision ?? $publication->current_revision);

            if ((int) $remote['highest_revision'] < $revision || $remote['state'] !== 'revoked') {
                $this->outbox->queue(
                    $link,
                    FederationMessageType::ResourceAccessRevoked,
                    [
                        'publication_id' => $publication->id,
                        'recipient_installation_id' => $link->remote_installation_id,
                        'revision' => $revision,
                        'reason_code' => 'reconciliation_tombstone',
                        'revoked_at' => $latestDelivery->access_revoked_at?->utc()->toIso8601String()
                            ?? now()->utc()->toIso8601String(),
                    ],
                    CarbonImmutable::now('UTC')->addDays(7),
                );
            }

            return;
        }

        $missingVersions = collect($remote['missing_versions'])
            ->map(fn (mixed $version): int => (int) $version);
        $toResend = $deliveries->filter(function ($delivery) use ($remote, $missingVersions): bool {
            return (int) $delivery->version->revision > (int) $remote['highest_revision']
                || $missingVersions->contains((int) $delivery->version->version);
        });

        if ($toResend->isEmpty()) {
            return;
        }

        try {
            DB::transaction(function () use ($link, $publication): void {
                $lockedPublication = FederationPublication::query()->lockForUpdate()->findOrFail($publication->id);
                $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($link->id);
                $lockedCoalition = $lockedPublication->coalition()->lockForUpdate()->firstOrFail();
                $this->authorization->assertCanPublish($lockedCoalition, $lockedLink, lockForUpdate: true);
            }, attempts: 5);
        } catch (FederationProtocolException) {
            $this->publications->revokeRecipient(
                $publication,
                $link->remote_installation_id,
                'authorization_invalidated',
                0,
            );

            return;
        }

        foreach ($toResend as $delivery) {
            if ($delivery->canonical_payload === null || $delivery->version->expires_at->isPast()) {
                continue;
            }

            try {
                DB::transaction(function () use ($delivery, $link, $publication): void {
                    $lockedPublication = FederationPublication::query()->lockForUpdate()->findOrFail($publication->id);
                    $lockedLink = FederationLink::query()->lockForUpdate()->findOrFail($link->id);
                    $lockedCoalition = $lockedPublication->coalition()->lockForUpdate()->firstOrFail();
                    $lockedDelivery = FederationPublicationDelivery::query()
                        ->with('version')
                        ->lockForUpdate()
                        ->findOrFail($delivery->id);
                    $this->authorization->assertCanPublish($lockedCoalition, $lockedLink, lockForUpdate: true);

                    if ($lockedDelivery->state === DeliveryState::Revoked
                        || $lockedDelivery->canonical_payload === null
                        || $lockedDelivery->version->expires_at->isPast()
                        || $lockedDelivery->version->status !== 'published') {
                        return;
                    }

                    $snapshot = WarPlanSnapshotV1::fromJson((string) $lockedDelivery->canonical_payload);
                    $message = $this->outbox->queue(
                        link: $lockedLink,
                        type: (int) $lockedDelivery->version->version === 1
                            ? FederationMessageType::ResourcePublished
                            : FederationMessageType::ResourceUpdated,
                        payload: $snapshot->toArray(),
                        expiresAt: CarbonImmutable::instance($lockedDelivery->version->expires_at),
                        resourceSchema: WarPlanSnapshotV1::SCHEMA,
                    );
                    $lockedDelivery->forceFill(['outbox_message_id' => $message->message_id])->save();
                }, attempts: 5);
            } catch (FederationProtocolException) {
                $this->publications->revokeRecipient(
                    $publication,
                    $link->remote_installation_id,
                    'authorization_invalidated',
                    0,
                );

                return;
            }
        }
    }

    private function receivedHash(FederationReceivedResource $resource): string
    {
        if (in_array($resource->state, [ReceivedResourceState::Revoked, ReceivedResourceState::Expired], true)) {
            return hash(
                'sha256',
                $resource->source_publication_id.':'.$resource->current_revision.':'.$resource->state->value,
            );
        }

        $latest = $resource->versions->sortByDesc('revision')->first();

        return $latest?->payload_hash
            ?? hash('sha256', $resource->source_publication_id.':'.$resource->current_revision.':'.$resource->state->value);
    }

    /** @return list<int> */
    private function missingVersions(FederationReceivedResource $resource): array
    {
        $currentVersion = min(
            max((int) $resource->current_version, 0),
            (int) config('federation.limits.max_resource_version', 1000000000),
        );
        $present = $resource->versions
            ->pluck('version')
            ->map(fn (mixed $version): int => (int) $version)
            ->filter(fn (int $version): bool => $version > 0 && $version <= $currentVersion)
            ->unique()
            ->sort()
            ->values();
        $missing = [];
        $expected = 1;

        foreach ($present as $version) {
            while ($expected < $version && count($missing) < 500) {
                $missing[] = $expected++;
            }

            $expected = max($expected, $version + 1);

            if (count($missing) >= 500) {
                break;
            }
        }

        while ($expected <= $currentVersion && count($missing) < 500) {
            $missing[] = $expected++;
        }

        return $missing;
    }
}
