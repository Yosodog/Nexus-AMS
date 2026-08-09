<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\Milcom\OperationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FederatedWarPlanImporter
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly AllianceMembershipService $alliances,
        private readonly AuditLogger $audit,
    ) {}

    public function import(FederationReceivedVersion $version): FederationReceivedVersion
    {
        $claimed = false;
        $locked = DB::transaction(function () use ($version, &$claimed): FederationReceivedVersion {
            $locked = FederationReceivedVersion::query()->lockForUpdate()->findOrFail($version->id);
            $resource = FederationReceivedResource::query()
                ->lockForUpdate()
                ->findOrFail($locked->federation_received_resource_id);

            if ($locked->import_state === ImportState::Imported) {
                return $locked;
            }

            if ($locked->import_state === ImportState::Importing
                && $locked->updated_at->isAfter(now()->subMinutes(15))) {
                return $locked;
            }

            if ($locked->disposition !== ReceivedDisposition::Accepted
                || $locked->canonical_payload === null
                || $locked->expires_at->isPast()
                || in_array($resource->state, [
                    ReceivedResourceState::Revoked,
                    ReceivedResourceState::Expired,
                ], true)) {
                $locked->forceFill([
                    'import_state' => ImportState::Failed,
                    'safe_error_code' => 'source_unavailable',
                ])->save();

                return $locked;
            }

            if ($this->hasNewerAcceptedVersion($resource, $locked)) {
                $locked->forceFill([
                    'import_state' => ImportState::SourceStale,
                    'safe_error_code' => null,
                ])->save();

                return $locked;
            }

            $locked->forceFill([
                'import_state' => ImportState::Importing,
                'safe_error_code' => null,
                'missing_target_ids' => null,
            ])->save();
            $claimed = true;

            return $locked;
        }, attempts: 5);

        if ($locked->import_state === ImportState::Imported
            || $locked->import_state === ImportState::Failed
            || ! $claimed) {
            return $locked;
        }

        $snapshot = WarPlanSnapshotV1::fromJson((string) $locked->canonical_payload);
        $targetIds = collect($snapshot->targets)
            ->map(fn ($target): int => $target->targetNationId)
            ->unique()
            ->sort()
            ->values();
        $existingTargetIds = Nation::query()
            ->whereIn('id', $targetIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        $missingTargetIds = $targetIds->diff($existingTargetIds)->values()->all();

        if ($missingTargetIds !== []) {
            return DB::transaction(function () use ($locked, $missingTargetIds): FederationReceivedVersion {
                $version = FederationReceivedVersion::query()->lockForUpdate()->findOrFail($locked->id);
                $resource = FederationReceivedResource::query()
                    ->lockForUpdate()
                    ->findOrFail($version->federation_received_resource_id);

                if ($version->canonical_payload === null
                    || in_array($resource->state, [
                        ReceivedResourceState::Revoked,
                        ReceivedResourceState::Expired,
                    ], true)) {
                    $version->forceFill([
                        'import_state' => ImportState::Failed,
                        'safe_error_code' => 'source_unavailable',
                    ])->save();

                    return $version;
                }

                if ($this->hasNewerAcceptedVersion($resource, $version)) {
                    $version->forceFill([
                        'import_state' => ImportState::SourceStale,
                        'safe_error_code' => null,
                    ])->save();

                    return $version;
                }

                $version->forceFill([
                    'import_state' => ImportState::BlockedMissingTargets,
                    'missing_target_ids' => $missingTargetIds,
                    'safe_error_code' => 'missing_targets',
                ])->save();

                return $version;
            }, attempts: 5);
        }

        try {
            $imported = DB::transaction(function () use ($locked, $snapshot, $targetIds): FederationReceivedVersion {
                $version = FederationReceivedVersion::query()->lockForUpdate()->findOrFail($locked->id);
                $resource = FederationReceivedResource::query()
                    ->lockForUpdate()
                    ->findOrFail($version->federation_received_resource_id);

                if ($version->disposition !== ReceivedDisposition::Accepted
                    || $version->canonical_payload === null
                    || $version->expires_at->isPast()
                    || $resource->state !== ReceivedResourceState::Accepted) {
                    $version->forceFill([
                        'import_state' => ImportState::Failed,
                        'safe_error_code' => 'source_unavailable',
                    ])->save();

                    return $version;
                }

                if ($this->hasNewerAcceptedVersion($resource, $version)) {
                    $version->forceFill([
                        'import_state' => ImportState::SourceStale,
                        'safe_error_code' => null,
                    ])->save();

                    return $version;
                }

                $previous = $resource
                    ->versions()
                    ->where('revision', '<', $version->revision)
                    ->whereNotNull('imported_operation_id')
                    ->latest('revision')
                    ->lockForUpdate()
                    ->first();
                $operation = $previous?->importedOperation;
                $rebuild = $operation instanceof MilcomOperation && $this->isPristine($operation, $previous);

                $friendlyAllianceIds = $this->alliances->getAllianceIds()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                if ($friendlyAllianceIds === []) {
                    $version->forceFill([
                        'import_state' => ImportState::Failed,
                        'safe_error_code' => 'friendly_scope_unconfigured',
                    ])->save();

                    return $version;
                }

                if (! $rebuild) {
                    $operation = $this->operations->createPlan((int) $version->reviewed_by, [
                        'name' => Str::limit($snapshot->title, 255, ''),
                        'default_war_type' => $snapshot->targets[0]->warType,
                        'deadline_at' => null,
                    ]);
                }

                $priorityOverrides = collect($snapshot->targets)
                    ->mapWithKeys(fn ($target): array => [$target->targetNationId => $target->priorityTier->value])
                    ->all();
                $operation = $this->operations->commitScope(
                    operation: $operation,
                    generationVersion: (int) $operation->generation_version,
                    actorUserId: (int) $version->reviewed_by,
                    friendlyAllianceIds: $friendlyAllianceIds,
                    enemyAllianceIds: [],
                    includedTargetNationIds: $targetIds->all(),
                    priorityOverrides: $priorityOverrides,
                );
                $targets = collect($snapshot->targets)->keyBy(fn ($target): int => $target->targetNationId);

                foreach (MilcomObjective::query()
                    ->where('operation_id', $operation->id)
                    ->lockForUpdate()
                    ->get() as $objective) {
                    $target = $targets->get((int) $objective->target_nation_id);
                    $objective->forceFill([
                        'priority_tier' => $target->priorityTier,
                        'war_type' => $target->warType,
                        'minimum_team_depth' => $target->minimumTeamSize,
                        'desired_team_depth' => $target->desiredTeamSize,
                        'deadline_at' => $target->deadlineAt,
                    ])->save();
                }

                $operation->forceFill([
                    'metadata' => [
                        ...($operation->metadata ?? []),
                        'federation' => [
                            'source_installation_id' => $snapshot->sourceInstallationId,
                            'source_alliance_id' => $snapshot->sourceAllianceId,
                            'publication_id' => $snapshot->publicationId,
                            'version_id' => $snapshot->versionId,
                            'version' => $snapshot->version,
                            'coalition_id' => $snapshot->coalitionId,
                            'wave_label' => $snapshot->waveLabel,
                            'recipient_instructions' => $snapshot->recipientInstructions,
                        ],
                    ],
                ])->save();

                if ($previous instanceof FederationReceivedVersion) {
                    $previous->forceFill(['import_state' => ImportState::SourceStale])->save();
                }

                $version->forceFill([
                    'import_state' => ImportState::Imported,
                    'imported_operation_id' => $operation->id,
                    'import_baseline_generation' => $operation->generation_version,
                    'missing_target_ids' => null,
                    'safe_error_code' => null,
                    'imported_at' => now(),
                ])->save();

                return $version;
            }, attempts: 5);
        } catch (Throwable $exception) {
            FederationReceivedVersion::query()->whereKey($locked->id)->update([
                'import_state' => ImportState::Failed->value,
                'safe_error_code' => 'import_failed',
                'updated_at' => now(),
            ]);

            throw $exception;
        }

        if ($imported->import_state === ImportState::Imported) {
            $this->audit->success('federation', 'war_plan.imported', $imported, [
                'publication_id' => $imported->source_publication_id,
                'version' => $imported->version,
                'operation_id' => $imported->imported_operation_id,
            ]);
        }

        return $imported;
    }

    private function isPristine(
        MilcomOperation $operation,
        FederationReceivedVersion $previous,
    ): bool {
        return $operation->status === OperationStatus::Draft
            && ! $operation->federation_action_required
            && (int) $operation->generation_version === (int) $previous->import_baseline_generation
            && ! $operation->recommendationRuns()->exists()
            && ! $operation->assignmentsThroughObjectives()->exists()
            && ! $operation->dispatches()->exists()
            && ! $operation->assignmentDeliveries()->exists()
            && ! $operation->objectives()->whereNotNull('discord_channel_id')->exists();
    }

    private function hasNewerAcceptedVersion(
        FederationReceivedResource $resource,
        FederationReceivedVersion $version,
    ): bool {
        if ((int) $resource->current_revision <= (int) $version->revision) {
            return false;
        }

        return $resource->versions()
            ->where('revision', '>', $version->revision)
            ->where('disposition', ReceivedDisposition::Accepted->value)
            ->exists();
    }
}
