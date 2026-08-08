<?php

namespace App\Domain\Federation\DTO;

use App\Domain\Federation\Support\StrictJson;
use App\Domain\Milcom\Enums\PriorityTier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class WarPlanTargetV1
{
    /** @var list<string> */
    private const FIELDS = [
        'target_nation_id',
        'target_nation_name',
        'target_alliance_id',
        'target_alliance_name',
        'priority_tier',
        'war_type',
        'minimum_team_size',
        'desired_team_size',
        'deadline_at',
    ];

    public function __construct(
        public int $targetNationId,
        public string $targetNationName,
        public ?int $targetAllianceId,
        public ?string $targetAllianceName,
        public PriorityTier $priorityTier,
        public string $warType,
        public int $minimumTeamSize,
        public int $desiredTeamSize,
        public ?CarbonImmutable $deadlineAt,
    ) {
        if ($targetNationId < 1 || ($targetAllianceId !== null && $targetAllianceId < 1)) {
            throw new InvalidArgumentException('War-plan target identifiers must be positive.');
        }

        if ($priorityTier === PriorityTier::Hold) {
            throw new InvalidArgumentException('Hold objectives cannot be federated.');
        }

        if ($minimumTeamSize < 1 || $desiredTeamSize < $minimumTeamSize || $desiredTeamSize > 6) {
            throw new InvalidArgumentException('War-plan team sizes are invalid.');
        }

        if (Str::length($targetNationName) > 255
            || Str::length($targetAllianceName ?? '') > 255
            || Str::length($warType) > 32) {
            throw new InvalidArgumentException('War-plan target text exceeds protocol limits.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        StrictJson::rejectUnknown($data, self::FIELDS);
        StrictJson::requireProperties($data, self::FIELDS);

        if (! is_int($data['target_nation_id'])
            || (! is_null($data['target_alliance_id']) && ! is_int($data['target_alliance_id']))
            || ! is_string($data['target_nation_name'])
            || (! is_null($data['target_alliance_name']) && ! is_string($data['target_alliance_name']))
            || ! is_string($data['priority_tier'])
            || ! is_string($data['war_type'])
            || ! is_int($data['minimum_team_size'])
            || ! is_int($data['desired_team_size'])
            || (! is_null($data['deadline_at']) && ! is_string($data['deadline_at']))) {
            throw new InvalidArgumentException('War-plan target has invalid field types.');
        }

        return new self(
            targetNationId: $data['target_nation_id'],
            targetNationName: $data['target_nation_name'],
            targetAllianceId: $data['target_alliance_id'],
            targetAllianceName: $data['target_alliance_name'],
            priorityTier: PriorityTier::from($data['priority_tier']),
            warType: $data['war_type'],
            minimumTeamSize: $data['minimum_team_size'],
            desiredTeamSize: $data['desired_team_size'],
            deadlineAt: $data['deadline_at'] === null ? null : CarbonImmutable::parse($data['deadline_at']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target_nation_id' => $this->targetNationId,
            'target_nation_name' => $this->targetNationName,
            'target_alliance_id' => $this->targetAllianceId,
            'target_alliance_name' => $this->targetAllianceName,
            'priority_tier' => $this->priorityTier->value,
            'war_type' => $this->warType,
            'minimum_team_size' => $this->minimumTeamSize,
            'desired_team_size' => $this->desiredTeamSize,
            'deadline_at' => $this->deadlineAt?->utc()->toIso8601String(),
        ];
    }
}
