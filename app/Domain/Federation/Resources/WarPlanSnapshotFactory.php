<?php

namespace App\Domain\Federation\Resources;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\DTO\WarPlanTargetV1;
use App\Models\FederationCoalition;
use App\Models\FederationPublication;
use App\Models\FederationPublicationVersion;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Services\AllianceMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WarPlanSnapshotFactory
{
    public function __construct(private readonly AllianceMembershipService $membership) {}

    /**
     * @param  Collection<int, MilcomObjective>  $objectives
     */
    public function build(
        MilcomOperation $operation,
        FederationPublication $publication,
        FederationPublicationVersion $version,
        FederationCoalition $coalition,
        string $recipientInstallationId,
        string $title,
        string $waveLabel,
        string $recipientInstructions,
        Collection $objectives,
        CarbonImmutable $publishedAt,
        CarbonImmutable $expiresAt,
    ): WarPlanSnapshotV1 {
        $sourceAllianceId = $this->membership->getPrimaryAllianceId();

        if ($sourceAllianceId < 1) {
            $sourceAllianceId = (int) $this->membership->getAllianceIds()->first();
        }

        if ($sourceAllianceId < 1) {
            throw ValidationException::withMessages([
                'source_alliance' => 'Configure at least one local alliance before publishing.',
            ]);
        }

        return new WarPlanSnapshotV1(
            publicationId: $publication->id,
            versionId: $version->id,
            version: (int) $version->version,
            revision: (int) $version->revision,
            sourceInstallationId: $publication->source_installation_id,
            sourceAllianceId: $sourceAllianceId,
            coalitionId: $coalition->id,
            rosterRevision: (int) $coalition->roster_revision,
            sourceGeneration: (int) $operation->generation_version,
            publishedAt: $publishedAt,
            expiresAt: $expiresAt,
            recipientInstallationId: $recipientInstallationId,
            title: $title,
            waveLabel: $waveLabel,
            recipientInstructions: $recipientInstructions,
            targets: $objectives->map(
                fn (MilcomObjective $objective): WarPlanTargetV1 => new WarPlanTargetV1(
                    targetNationId: (int) $objective->target_nation_id,
                    targetNationName: (string) $objective->target->nation_name,
                    targetAllianceId: $objective->target->alliance_id !== null
                        ? (int) $objective->target->alliance_id
                        : null,
                    targetAllianceName: $objective->target->alliance?->name,
                    priorityTier: $objective->priority_tier,
                    warType: (string) $objective->war_type,
                    minimumTeamSize: (int) $objective->minimum_team_depth,
                    desiredTeamSize: (int) $objective->desired_team_depth,
                    deadlineAt: $objective->deadline_at
                        ? CarbonImmutable::instance($objective->deadline_at)
                        : null,
                )
            )->values()->all(),
        );
    }
}
