<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\DTO\WarPlanTargetV1;
use App\Domain\Milcom\Enums\PriorityTier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class WarPlanSnapshotV1Test extends TestCase
{
    public function test_snapshot_round_trip_contains_only_the_v1_allowlist(): void
    {
        $snapshot = $this->snapshot();
        $json = $snapshot->toJson();
        $decoded = WarPlanSnapshotV1::fromJson($json);

        $this->assertSame($json, $decoded->toJson());
        $this->assertSame(hash('sha256', $json), $snapshot->hash());

        foreach ([
            'friendly_alliances', 'participants', 'assignments', 'priority_score', 'confidence',
            'recommendations', 'military', 'resources', 'war_reason', 'discord', 'creator_id',
            'local_id', 'metadata', 'internal_url',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, $json);
        }
    }

    public function test_unknown_or_hold_target_fields_are_rejected(): void
    {
        $payload = $this->snapshot()->toArray();
        $payload['targets'][0]['war_reason'] = 'must not cross the boundary';

        try {
            WarPlanSnapshotV1::fromArray($payload);
            $this->fail('Unknown target property was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $payload = $this->snapshot()->toArray();
        $payload['targets'][0]['priority_tier'] = PriorityTier::Hold->value;

        $this->expectException(InvalidArgumentException::class);
        WarPlanSnapshotV1::fromArray($payload);
    }

    private function snapshot(): WarPlanSnapshotV1
    {
        $publishedAt = CarbonImmutable::parse('2026-08-08T12:00:00Z');

        return new WarPlanSnapshotV1(
            publicationId: (string) Str::ulid(),
            versionId: (string) Str::ulid(),
            version: 1,
            revision: 1,
            sourceInstallationId: (string) Str::ulid(),
            sourceAllianceId: 123,
            coalitionId: (string) Str::ulid(),
            rosterRevision: 1,
            sourceGeneration: 2,
            publishedAt: $publishedAt,
            expiresAt: $publishedAt->addDays(7),
            recipientInstallationId: (string) Str::ulid(),
            title: 'August defensive wave',
            waveLabel: 'Wave 1',
            recipientInstructions: 'Coordinate timing out of band.',
            targets: [new WarPlanTargetV1(
                targetNationId: 456,
                targetNationName: 'Target Nation',
                targetAllianceId: 789,
                targetAllianceName: 'Target Alliance',
                priorityTier: PriorityTier::High,
                warType: 'ORDINARY',
                minimumTeamSize: 1,
                desiredTeamSize: 2,
                deadlineAt: $publishedAt->addDay(),
            )],
        );
    }
}
