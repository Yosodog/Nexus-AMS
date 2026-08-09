<?php

namespace App\Http\Resources\Discord;

use App\Domain\Milcom\ReadinessProfile;
use App\Models\MilcomReadinessSnapshot;
use App\Models\Nation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MilcomReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{nation: Nation, profile: ReadinessProfile, snapshot: MilcomReadinessSnapshot} $readiness */
        $readiness = $this->resource;
        $nation = $readiness['nation'];
        $profile = $readiness['profile'];
        $snapshot = $readiness['snapshot'];

        return [
            'nation' => [
                'id' => (int) $nation->id,
                'nation_name' => (string) $nation->nation_name,
                'leader_name' => (string) $nation->leader_name,
                'alliance_position' => $profile->alliancePosition,
                'alliance' => $nation->alliance !== null ? [
                    'id' => (int) $nation->alliance->id,
                    'name' => (string) $nation->alliance->name,
                    'acronym' => filled($nation->alliance->acronym)
                        ? (string) $nation->alliance->acronym
                        : null,
                ] : null,
            ],
            'score' => $profile->score,
            'cities' => $profile->cities,
            'vacation_turns' => $profile->vacationTurns,
            'beige_turns' => $profile->beigeTurns,
            'offensive_slots' => [
                'capacity_at_snapshot' => $snapshot->offensive_capacity !== null
                    ? (int) $snapshot->offensive_capacity
                    : null,
                'active_wars_at_snapshot' => $profile->activeOffensiveWars,
                'reserved_at_snapshot' => $profile->reservedOffensiveSlots,
            ],
            'military' => [
                'soldiers' => $profile->soldiers,
                'tanks' => $profile->tanks,
                'aircraft' => $profile->aircraft,
                'ships' => $profile->ships,
                'missiles' => $profile->missiles,
                'nukes' => $profile->nukes,
            ],
            'freshness' => [
                'fetched_at' => $profile->fetchedAt->format(DATE_ATOM),
                'last_active_at' => $profile->lastActiveAt?->format(DATE_ATOM),
                'completeness_percent' => (int) $snapshot->completeness_percent,
            ],
        ];
    }
}
