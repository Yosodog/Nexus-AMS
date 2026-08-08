<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\DeliveryState;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Resources\WarPlanSnapshotFactory;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationLink;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationPublicationVersion;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarPlanPublicationService
{
    public function __construct(
        private readonly FederationAuthorizationService $authorization,
        private readonly WarPlanSnapshotFactory $snapshots,
        private readonly FederationOutboxService $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $recipientLinkIds
     * @param  list<int>  $objectiveIds
     */
    public function preview(
        MilcomOperation $operation,
        FederationCoalition $coalition,
        array $recipientLinkIds,
        array $objectiveIds,
        string $title,
        string $waveLabel,
        string $recipientInstructions,
        CarbonImmutable $expiresAt,
        int $actorUserId,
        ?FederationPublication $publication = null,
    ): FederationPublicationVersion {
        $this->assertPublishingEnabled();
        $identity = FederationIdentity::query()->where('enabled', true)->firstOrFail();
        $recipientLinkIds = array_values(array_unique($recipientLinkIds));
        $objectiveIds = array_values(array_unique(array_map('intval', $objectiveIds)));
        $isUpdate = $publication instanceof FederationPublication;
        $this->assertExpiry($expiresAt);

        return DB::transaction(function () use (
            $operation,
            $coalition,
            $recipientLinkIds,
            $objectiveIds,
            $title,
            $waveLabel,
            $recipientInstructions,
            $expiresAt,
            $actorUserId,
            $publication,
            $identity,
            $isUpdate,
        ): FederationPublicationVersion {
            $lockedOperation = MilcomOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->assertOperationEligible($lockedOperation, $isUpdate);
            $objectives = $this->selectedObjectives($lockedOperation, $objectiveIds, $isUpdate);
            $links = FederationLink::query()
                ->whereIn('id', $recipientLinkIds)
                ->with('peerKeys')
                ->orderBy('remote_installation_id')
                ->get();

            if ($links->count() !== count($recipientLinkIds) || $links->isEmpty()) {
                throw ValidationException::withMessages(['recipients' => 'Select at least one valid recipient installation.']);
            }

            foreach ($links as $link) {
                $this->authorization->assertCanPublish($coalition, $link);
            }

            $lockedPublication = $publication instanceof FederationPublication
                ? FederationPublication::query()->lockForUpdate()->findOrFail($publication->id)
                : FederationPublication::query()->create([
                    'id' => (string) Str::ulid(),
                    'milcom_operation_id' => $lockedOperation->id,
                    'federation_coalition_id' => $coalition->id,
                    'source_installation_id' => $identity->id,
                    'resource_type' => FederationResourceType::WarPlanSnapshot,
                    'status' => PublicationStatus::Draft,
                    'source_generation' => $lockedOperation->generation_version,
                    'created_by' => $actorUserId,
                    'expires_at' => $expiresAt,
                ]);

            if ((int) $lockedPublication->milcom_operation_id !== (int) $lockedOperation->id
                || ! hash_equals($lockedPublication->federation_coalition_id, $coalition->id)
                || in_array($lockedPublication->status, [PublicationStatus::Revoked, PublicationStatus::Expired], true)) {
                throw ValidationException::withMessages(['publication' => 'This publication cannot be updated.']);
            }

            $versionNumber = ((int) $lockedPublication->versions()->max('version')) + 1;
            $revision = max((int) $lockedPublication->current_revision + 1, $versionNumber);
            $publishedAt = CarbonImmutable::now('UTC');
            $version = $lockedPublication->versions()->create([
                'id' => (string) Str::ulid(),
                'version' => $versionNumber,
                'revision' => $revision,
                'source_generation' => $lockedOperation->generation_version,
                'schema_version' => '1.0',
                'recipients_hash' => str_repeat('0', 64),
                'preview_hash' => str_repeat('0', 64),
                'canonical_preview' => '{}',
                'status' => 'preview',
                'created_by' => $actorUserId,
                'expires_at' => $expiresAt,
            ]);
            $previewRecipients = [];

            foreach ($links as $link) {
                $snapshot = $this->snapshots->build(
                    operation: $lockedOperation,
                    publication: $lockedPublication,
                    version: $version,
                    coalition: $coalition,
                    recipientInstallationId: $link->remote_installation_id,
                    title: Str::limit(Str::squish($title), 255, ''),
                    waveLabel: Str::limit(Str::squish($waveLabel), 100, ''),
                    recipientInstructions: $recipientInstructions,
                    objectives: $objectives,
                    publishedAt: $publishedAt,
                    expiresAt: $expiresAt,
                );
                $payload = $snapshot->toJson();
                $delivery = $version->deliveries()->create([
                    'id' => (string) Str::ulid(),
                    'federation_link_id' => $link->id,
                    'recipient_installation_id' => $link->remote_installation_id,
                    'state' => DeliveryState::Pending,
                    'canonical_payload' => $payload,
                    'payload_hash' => hash('sha256', $payload),
                    'payload_bytes' => strlen($payload),
                ]);
                $previewRecipients[] = [
                    'recipient_installation_id' => $delivery->recipient_installation_id,
                    'payload_hash' => $delivery->payload_hash,
                    'payload_bytes' => $delivery->payload_bytes,
                    'payload' => $payload,
                ];
            }

            $recipientIds = $links->pluck('remote_installation_id')->sort()->values()->all();
            $canonicalPreview = CanonicalJson::encode([
                'publication_id' => $lockedPublication->id,
                'version_id' => $version->id,
                'version' => $versionNumber,
                'revision' => $revision,
                'source_generation' => (int) $lockedOperation->generation_version,
                'coalition_id' => $coalition->id,
                'recipients' => $previewRecipients,
            ]);
            $version->forceFill([
                'recipients_hash' => hash('sha256', CanonicalJson::encode($recipientIds)),
                'preview_hash' => hash('sha256', $canonicalPreview),
                'canonical_preview' => $canonicalPreview,
            ])->save();
            $lockedPublication->forceFill([
                'source_generation' => $lockedOperation->generation_version,
                'expires_at' => $expiresAt,
            ])->save();

            return $version->fresh(['publication', 'deliveries.link']);
        }, attempts: 5);
    }

    public function publish(FederationPublicationVersion $version, string $previewHash): FederationPublicationVersion
    {
        $this->assertPublishingEnabled();

        $published = DB::transaction(function () use ($version, $previewHash): FederationPublicationVersion {
            $lockedVersion = FederationPublicationVersion::query()
                ->with('deliveries.link')
                ->lockForUpdate()
                ->findOrFail($version->id);
            $publication = FederationPublication::query()->lockForUpdate()->findOrFail(
                $lockedVersion->federation_publication_id
            );
            $operation = MilcomOperation::query()->lockForUpdate()->findOrFail($publication->milcom_operation_id);
            $coalition = FederationCoalition::query()->lockForUpdate()->findOrFail($publication->federation_coalition_id);

            if ($lockedVersion->status !== 'preview'
                || ! hash_equals($lockedVersion->preview_hash, $previewHash)
                || (int) $operation->generation_version !== (int) $lockedVersion->source_generation) {
                throw ValidationException::withMessages([
                    'preview' => 'The operation, recipients, capabilities, or preview changed. Generate a new preview.',
                ]);
            }

            $isUpdate = (int) $publication->current_version > 0;
            $this->assertOperationEligible($operation, $isUpdate);

            foreach ($lockedVersion->deliveries as $delivery) {
                $this->authorization->assertCanPublish($coalition, $delivery->link);
                $expected = WarPlanSnapshotV1::fromJson((string) $delivery->canonical_payload);
                $objectives = $this->selectedObjectivesByTarget($operation, $expected, $isUpdate);
                $rebuilt = $this->snapshots->build(
                    operation: $operation,
                    publication: $publication,
                    version: $lockedVersion,
                    coalition: $coalition,
                    recipientInstallationId: $delivery->recipient_installation_id,
                    title: $expected->title,
                    waveLabel: $expected->waveLabel,
                    recipientInstructions: $expected->recipientInstructions,
                    objectives: $objectives,
                    publishedAt: $expected->publishedAt,
                    expiresAt: $expected->expiresAt,
                );

                if (! hash_equals((string) $delivery->canonical_payload, $rebuilt->toJson())
                    || ! hash_equals($delivery->payload_hash, $rebuilt->hash())) {
                    throw ValidationException::withMessages([
                        'preview' => 'The exact preview is stale. Generate a new preview before publishing.',
                    ]);
                }
            }

            foreach ($lockedVersion->deliveries as $delivery) {
                $message = $this->outbox->queue(
                    link: $delivery->link,
                    type: $isUpdate
                        ? FederationMessageType::ResourceUpdated
                        : FederationMessageType::ResourcePublished,
                    payload: WarPlanSnapshotV1::fromJson((string) $delivery->canonical_payload)->toArray(),
                    expiresAt: CarbonImmutable::instance($lockedVersion->expires_at),
                    resourceSchema: WarPlanSnapshotV1::SCHEMA,
                );
                $delivery->forceFill(['outbox_message_id' => $message->message_id])->save();
            }

            $lockedVersion->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();
            $publication->forceFill([
                'status' => PublicationStatus::Published,
                'current_version' => $lockedVersion->version,
                'current_revision' => $lockedVersion->revision,
                'source_generation' => $operation->generation_version,
                'expires_at' => $lockedVersion->expires_at,
                'published_at' => $publication->published_at ?? now(),
            ])->save();

            return $lockedVersion->fresh(['publication', 'deliveries']);
        }, attempts: 5);

        $this->audit->success('federation', 'war_plan.published', $published, [
            'publication_id' => $published->federation_publication_id,
            'version' => $published->version,
            'revision' => $published->revision,
            'preview_hash' => $published->preview_hash,
        ]);

        return $published;
    }

    public function revokeRecipient(
        FederationPublication $publication,
        string $recipientInstallationId,
        string $reasonCode,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($publication, $recipientInstallationId, $reasonCode): void {
            $locked = FederationPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $delivery = FederationPublicationDelivery::query()
                ->whereHas('version', fn ($query) => $query->where('federation_publication_id', $locked->id))
                ->where('recipient_installation_id', $recipientInstallationId)
                ->latest('created_at')
                ->lockForUpdate()
                ->firstOrFail();
            $revision = (int) $locked->current_revision + 1;
            $this->outbox->queue(
                $delivery->link,
                FederationMessageType::ResourceAccessRevoked,
                [
                    'publication_id' => $locked->id,
                    'recipient_installation_id' => $recipientInstallationId,
                    'revision' => $revision,
                    'reason_code' => Str::limit(Str::snake($reasonCode), 64, ''),
                    'revoked_at' => now()->utc()->toIso8601String(),
                ],
                CarbonImmutable::now('UTC')->addDays(7),
            );
            $delivery->forceFill([
                'state' => DeliveryState::Revoked,
                'access_revoked_at' => now(),
                'canonical_payload' => null,
            ])->save();
            $locked->forceFill([
                'status' => PublicationStatus::PartiallyRevoked,
                'current_revision' => $revision,
            ])->save();
        }, attempts: 5);

        $this->audit->success('federation', 'war_plan.recipient_revoked', $publication, [
            'publication_id' => $publication->id,
            'recipient_installation_id' => $recipientInstallationId,
            'actor_id' => $actorUserId,
        ]);
    }

    public function revokeAll(FederationPublication $publication, string $reasonCode, int $actorUserId): void
    {
        DB::transaction(function () use ($publication, $reasonCode): void {
            $locked = FederationPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $revision = (int) $locked->current_revision + 1;
            $latestDeliveries = FederationPublicationDelivery::query()
                ->whereHas('version', fn ($query) => $query->where('federation_publication_id', $locked->id))
                ->with('link')
                ->get()
                ->unique('recipient_installation_id');

            foreach ($latestDeliveries as $delivery) {
                $this->outbox->queue(
                    $delivery->link,
                    FederationMessageType::ResourceRevoked,
                    [
                        'publication_id' => $locked->id,
                        'revision' => $revision,
                        'reason_code' => Str::limit(Str::snake($reasonCode), 64, ''),
                        'revoked_at' => now()->utc()->toIso8601String(),
                    ],
                    CarbonImmutable::now('UTC')->addDays(7),
                );
            }

            FederationPublicationDelivery::query()
                ->whereHas('version', fn ($query) => $query->where('federation_publication_id', $locked->id))
                ->update([
                    'state' => DeliveryState::Revoked->value,
                    'access_revoked_at' => now(),
                    'canonical_payload' => null,
                    'updated_at' => now(),
                ]);
            $locked->forceFill([
                'status' => PublicationStatus::Revoked,
                'current_revision' => $revision,
                'revoked_at' => now(),
            ])->save();
        }, attempts: 5);

        $this->audit->success('federation', 'war_plan.revoked', $publication, [
            'publication_id' => $publication->id,
            'actor_id' => $actorUserId,
        ]);
    }

    private function assertPublishingEnabled(): void
    {
        if (! (bool) config('federation.enabled', false)
            || ! (bool) config('federation.features.publishing', false)) {
            throw ValidationException::withMessages(['federation' => 'Federated publishing is disabled.']);
        }
    }

    private function assertExpiry(CarbonImmutable $expiresAt): void
    {
        $now = CarbonImmutable::now('UTC');
        $maximum = $now->addDays(max((int) config('federation.publication_max_expiry_days', 30), 1));

        if (! $expiresAt->isAfter($now) || $expiresAt->isAfter($maximum)) {
            throw ValidationException::withMessages([
                'expires_at' => 'Publication expiry must be in the future and within the configured maximum.',
            ]);
        }
    }

    private function assertOperationEligible(MilcomOperation $operation, bool $isUpdate): void
    {
        if ($operation->type !== OperationType::Plan
            || in_array($operation->status, [
                OperationStatus::Generating,
                OperationStatus::Completed,
                OperationStatus::Archived,
                OperationStatus::Failed,
            ], true)
            || (! $isUpdate && ($operation->status === OperationStatus::Dispatching
                || $operation->status === OperationStatus::Active
                || filled(data_get($operation->metadata, 'finalized_at'))
                || $operation->dispatches()->exists()))
            || $operation->current_stage === 'scope'
            || ! $operation->objectives()->exists()) {
            throw ValidationException::withMessages([
                'operation' => $isUpdate
                    ? 'This operation can no longer publish a federation update.'
                    : 'Commit and review the plan scope before finalization or dispatch.',
            ]);
        }
    }

    /**
     * @param  list<int>  $objectiveIds
     * @return Collection<int, MilcomObjective>
     */
    private function selectedObjectives(
        MilcomOperation $operation,
        array $objectiveIds,
        bool $isUpdate,
    ): Collection {
        if ($objectiveIds === []
            || count($objectiveIds) > (int) config('federation.limits.targets_per_publication', 500)) {
            throw ValidationException::withMessages(['objectives' => 'Select between 1 and 500 objectives.']);
        }

        $objectives = $operation->objectives()
            ->whereIn('id', $objectiveIds)
            ->with('target.alliance')
            ->orderBy('target_nation_id')
            ->get();

        if ($objectives->count() !== count($objectiveIds)) {
            throw ValidationException::withMessages(['objectives' => 'One or more selected objectives are invalid.']);
        }

        foreach ($objectives as $objective) {
            if ($objective->priority_tier === PriorityTier::Hold
                || in_array($objective->status, [
                    ObjectiveStatus::Blocked,
                    ObjectiveStatus::Cancelled,
                    ObjectiveStatus::Expired,
                    ObjectiveStatus::Completed,
                ], true)) {
                throw ValidationException::withMessages([
                    'objectives' => 'Held, blocked, cancelled, expired, or completed objectives cannot be shared.',
                ]);
            }

            if (! $isUpdate && in_array($objective->status, [
                ObjectiveStatus::Dispatching,
                ObjectiveStatus::Dispatched,
                ObjectiveStatus::Engaged,
            ], true)) {
                throw ValidationException::withMessages([
                    'objectives' => 'Initial publication must occur before objective dispatch.',
                ]);
            }
        }

        return $objectives;
    }

    /** @return Collection<int, MilcomObjective> */
    private function selectedObjectivesByTarget(
        MilcomOperation $operation,
        WarPlanSnapshotV1 $snapshot,
        bool $isUpdate,
    ): Collection {
        $targetIds = array_map(fn ($target): int => $target->targetNationId, $snapshot->targets);
        $objectiveIds = $operation->objectives()
            ->whereIn('target_nation_id', $targetIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $this->selectedObjectives($operation, $objectiveIds, $isUpdate);
    }
}
