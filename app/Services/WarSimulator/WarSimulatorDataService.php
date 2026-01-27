<?php

namespace App\Services\WarSimulator;

use App\Models\City;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class WarSimulatorDataService
{
    /**
     * @return array<string, mixed>
     */
    public function buildDefaults(User $user): array
    {
        $nation = $user->nation;

        if (! $nation) {
            return [
                'nation' => null,
                'active_wars' => [],
            ];
        }

        $nation->loadMissing(['military']);

        $activeWars = War::query()
            ->active()
            ->where(function ($query) use ($nation) {
                $query->where('att_id', $nation->id)
                    ->orWhere('def_id', $nation->id);
            })
            ->with(['attacker', 'defender'])
            ->orderByDesc('date')
            ->get();

        return [
            'nation' => $this->buildNationSnapshot($nation),
            'active_wars' => $activeWars->map(fn (War $war) => $this->buildWarListItem($war, $nation->id))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildNationSnapshot(Nation $nation, ?bool $fortified = null): array
    {
        $nation->loadMissing(['military']);
        $military = $nation->military;
        $cities = City::query()
            ->where('nation_id', $nation->id)
            ->orderByDesc('infrastructure')
            ->get(['infrastructure']);
        $highestCity = $cities->first();
        $highestInfra = (float) ($highestCity?->infrastructure ?? 0.0);
        $highestPopulation = $this->resolveHighestCityPopulation($nation, $highestCity, $cities);
        $avgInfra = $cities->avg('infrastructure');
        $lastUpdated = $nation->updated_at?->toIso8601String();

        return [
            'nation_id' => $nation->id,
            'nation_name' => $nation->nation_name,
            'leader_name' => $nation->leader_name,
            'flag' => $nation->flag,
            'soldiers' => (int) ($military?->soldiers ?? 0),
            'tanks' => (int) ($military?->tanks ?? 0),
            'aircraft' => (int) ($military?->aircraft ?? 0),
            'ships' => (int) ($military?->ships ?? 0),
            'war_policy' => (string) ($nation->war_policy ?? 'NONE'),
            'is_fortified' => $fortified ?? false,
            'cities' => (int) ($nation->num_cities ?? $cities->count()),
            'highest_city_infra' => $highestInfra,
            'highest_city_population' => $highestPopulation,
            'avg_infra' => $avgInfra !== null ? round((float) $avgInfra, 2) : null,
            'last_updated' => $lastUpdated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWarPayload(War $war): array
    {
        $war->loadMissing(['attacker', 'defender']);

        return [
            'attacker' => $war->attacker ? $this->buildNationSnapshot($war->attacker, (bool) $war->att_fortify) : null,
            'defender' => $war->defender ? $this->buildNationSnapshot($war->defender, (bool) $war->def_fortify) : null,
            'context' => [
                'war_type' => (string) $war->war_type,
                'attacker_policy' => (string) ($war->attacker?->war_policy ?? 'NONE'),
                'defender_policy' => (string) ($war->defender?->war_policy ?? 'NONE'),
                'air_superiority_owner' => $this->resolveControlOwner($war->air_superiority, $war),
                'ground_control_owner' => $this->resolveControlOwner($war->ground_control, $war),
                'blockade_owner' => $this->resolveControlOwner($war->naval_blockade, $war),
                'blitz_active_attacker' => $this->isBlitzActive($war),
                'blitz_active_defender' => $this->isBlitzActive($war),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWarListItem(War $war, int $nationId): array
    {
        $isAttacker = (int) $war->att_id === $nationId;
        $opponent = $isAttacker ? $war->defender : $war->attacker;

        return [
            'war_id' => $war->id,
            'opponent_nation_id' => $opponent?->id,
            'opponent_nation_name' => $opponent?->nation_name,
            'opponent_leader_name' => $opponent?->leader_name,
            'war_type' => (string) $war->war_type,
            'start_date' => $war->date ? Carbon::parse($war->date)->toDateTimeString() : null,
            'att_fortify' => (bool) $war->att_fortify,
            'def_fortify' => (bool) $war->def_fortify,
            'air_superiority_owner' => $this->resolveControlOwner($war->air_superiority, $war),
            'ground_control_owner' => $this->resolveControlOwner($war->ground_control, $war),
            'blockade_owner' => $this->resolveControlOwner($war->naval_blockade, $war),
        ];
    }

    private function resolveControlOwner(?int $ownerId, War $war): string
    {
        if ($ownerId === null) {
            return 'none';
        }

        if ((int) $ownerId === (int) $war->att_id) {
            return 'attacker';
        }

        if ((int) $ownerId === (int) $war->def_id) {
            return 'defender';
        }

        return 'none';
    }

    private function isBlitzActive(War $war): bool
    {
        if (! $war->date) {
            return false;
        }

        return Carbon::parse($war->date)->diffInHours() < 24;
    }

    private function resolveHighestCityPopulation(Nation $nation, ?City $highestCity, Collection $cities): int
    {
        $population = (int) ($nation->population ?? 0);
        if ($population <= 0) {
            return 0;
        }

        if ($highestCity && $cities->count() > 0) {
            $totalInfra = $cities->sum('infrastructure');
            if ($totalInfra > 0) {
                return (int) round($population * ($highestCity->infrastructure / $totalInfra));
            }
        }

        $cityCount = (int) ($nation->num_cities ?? $cities->count() ?: 1);

        return (int) round($population / max($cityCount, 1));
    }
}
