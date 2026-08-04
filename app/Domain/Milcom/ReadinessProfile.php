<?php

namespace App\Domain\Milcom;

use DateTimeImmutable;
use JsonSerializable;

final readonly class ReadinessProfile implements JsonSerializable
{
    /**
     * @param  array<string, bool>  $projects
     */
    public function __construct(
        public int $nationId,
        public ?int $allianceId,
        public string $alliancePosition,
        public float $score,
        public int $cities,
        public int $vacationTurns,
        public int $beigeTurns,
        public int $activeOffensiveWars,
        public int $reservedOffensiveSlots,
        public ?int $soldiers,
        public ?int $tanks,
        public ?int $aircraft,
        public ?int $ships,
        public int $missiles,
        public int $nukes,
        public ?DateTimeImmutable $lastActiveAt,
        public DateTimeImmutable $fetchedAt,
        public bool $discordLinked,
        public array $projects = [],
    ) {}

    public function hasCompleteMilitaryData(): bool
    {
        return $this->soldiers !== null
            && $this->tanks !== null
            && $this->aircraft !== null
            && $this->ships !== null;
    }

    public function ownsProject(string $project): bool
    {
        return (bool) ($this->projects[$project] ?? false);
    }

    public function jsonSerialize(): array
    {
        return [
            'nation_id' => $this->nationId,
            'alliance_id' => $this->allianceId,
            'alliance_position' => $this->alliancePosition,
            'score' => $this->score,
            'cities' => $this->cities,
            'vacation_turns' => $this->vacationTurns,
            'beige_turns' => $this->beigeTurns,
            'active_offensive_wars' => $this->activeOffensiveWars,
            'reserved_offensive_slots' => $this->reservedOffensiveSlots,
            'military' => [
                'soldiers' => $this->soldiers,
                'tanks' => $this->tanks,
                'aircraft' => $this->aircraft,
                'ships' => $this->ships,
                'missiles' => $this->missiles,
                'nukes' => $this->nukes,
            ],
            'last_active_at' => $this->lastActiveAt?->format(DATE_ATOM),
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
            'discord_linked' => $this->discordLinked,
            'projects' => $this->projects,
        ];
    }
}
