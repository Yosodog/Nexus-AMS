<?php

namespace App\Services\Milcom;

use App\Domain\Milcom\MilcomGameRules;
use App\Domain\Milcom\ReadinessProfile;
use App\Models\MilcomAssignment;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\War;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class ReadinessSnapshotService
{
    private const SNAPSHOT_CHUNK_SIZE = 200;

    public function __construct(private readonly MilcomGameRules $rules) {}

    /**
     * @return array<int, ReadinessProfile>
     */
    public function profilesForRun(MilcomRecommendationRun $run, array $nationIds = []): array
    {
        $nationIds = array_values(array_unique(array_map('intval', $nationIds)));

        return $run->snapshots()
            ->when($nationIds !== [], fn ($query) => $query->whereIn('nation_id', $nationIds))
            ->orderBy('nation_id')
            ->get()
            ->mapWithKeys(function (MilcomReadinessSnapshot $snapshot): array {
                $payload = $snapshot->payload;
                $military = (array) ($payload['military'] ?? []);

                return [(int) $snapshot->nation_id => new ReadinessProfile(
                    nationId: (int) $snapshot->nation_id,
                    allianceId: $snapshot->alliance_id !== null ? (int) $snapshot->alliance_id : null,
                    alliancePosition: (string) $snapshot->alliance_position,
                    score: (float) $snapshot->score,
                    cities: (int) $snapshot->cities,
                    vacationTurns: (int) $snapshot->vacation_turns,
                    beigeTurns: (int) $snapshot->beige_turns,
                    activeOffensiveWars: (int) $snapshot->active_offensive_wars,
                    reservedOffensiveSlots: (int) $snapshot->reserved_offensive_slots,
                    soldiers: isset($military['soldiers']) ? (int) $military['soldiers'] : null,
                    tanks: isset($military['tanks']) ? (int) $military['tanks'] : null,
                    aircraft: isset($military['aircraft']) ? (int) $military['aircraft'] : null,
                    ships: isset($military['ships']) ? (int) $military['ships'] : null,
                    missiles: (int) ($military['missiles'] ?? 0),
                    nukes: (int) ($military['nukes'] ?? 0),
                    lastActiveAt: ! empty($payload['last_active_at'])
                        ? new DateTimeImmutable($payload['last_active_at'])
                        : null,
                    fetchedAt: new DateTimeImmutable($payload['fetched_at']),
                    discordLinked: (bool) ($payload['discord_linked'] ?? false),
                    projects: (array) ($payload['projects'] ?? []),
                )];
            })
            ->all();
    }

    /**
     * Rehydrate snapshot participants from the latest local subscription state while
     * preserving the snapshot timestamp used by freshness policy checks.
     *
     * @param  array<int, ReadinessProfile>  $snapshotProfiles
     * @param  array<int, int>  $activeWarCounts
     * @param  array<int, int>  $reservationCounts
     * @return array<int, ReadinessProfile>
     */
    public function currentProfiles(
        array $snapshotProfiles,
        array $activeWarCounts = [],
        array $reservationCounts = [],
    ): array {
        if ($snapshotProfiles === []) {
            return [];
        }

        $nations = Nation::query()
            ->with([
                'military',
                'accountProfile',
                'user.discordAccounts' => fn ($query) => $query->whereNull('unlinked_at'),
            ])
            ->whereIn('id', array_keys($snapshotProfiles))
            ->get()
            ->keyBy('id');
        $missing = array_values(array_diff(
            array_map('intval', array_keys($snapshotProfiles)),
            $nations->keys()->map('intval')->all(),
        ));

        if ($missing !== []) {
            throw new RuntimeException('Politics & War did not return current data for: '.implode(', ', array_slice($missing, 0, 20)));
        }

        $profiles = [];

        foreach ($snapshotProfiles as $nationId => $snapshotProfile) {
            $currentProfile = $this->profile(
                $nations[(int) $nationId],
                (int) ($activeWarCounts[(int) $nationId] ?? 0),
                (int) ($reservationCounts[(int) $nationId] ?? 0),
                $snapshotProfile->fetchedAt,
            );
            $profiles[(int) $nationId] = new ReadinessProfile(
                nationId: $currentProfile->nationId,
                allianceId: $currentProfile->allianceId,
                alliancePosition: $currentProfile->alliancePosition,
                score: $currentProfile->score,
                cities: $currentProfile->cities,
                vacationTurns: $currentProfile->vacationTurns,
                beigeTurns: $currentProfile->beigeTurns,
                activeOffensiveWars: $currentProfile->activeOffensiveWars,
                reservedOffensiveSlots: $currentProfile->reservedOffensiveSlots,
                soldiers: $snapshotProfile->soldiers,
                tanks: $snapshotProfile->tanks,
                aircraft: $snapshotProfile->aircraft,
                ships: $snapshotProfile->ships,
                missiles: $snapshotProfile->missiles,
                nukes: $snapshotProfile->nukes,
                lastActiveAt: $snapshotProfile->lastActiveAt,
                fetchedAt: $snapshotProfile->fetchedAt,
                discordLinked: $snapshotProfile->discordLinked,
                projects: $currentProfile->projects,
            );
        }

        return $profiles;
    }

    /**
     * @param  list<int>  $friendlyNationIds
     * @param  list<int>  $targetNationIds
     * @return array<int, ReadinessProfile>
     */
    public function capture(
        MilcomRecommendationRun $run,
        array $friendlyNationIds,
        array $targetNationIds,
        DateTimeInterface $fetchedAt,
    ): array {
        $friendlyNationIds = array_values(array_unique(array_map('intval', $friendlyNationIds)));
        $targetNationIds = array_values(array_unique(array_map('intval', $targetNationIds)));
        $nationIds = array_values(array_unique([...$friendlyNationIds, ...$targetNationIds]));
        $existingNationIds = Nation::query()
            ->whereIn('id', $nationIds)
            ->pluck('id')
            ->map('intval')
            ->all();
        $missing = array_values(array_diff($nationIds, $existingNationIds));

        if ($missing !== []) {
            throw new RuntimeException('Politics & War still did not return current data for: '.implode(', ', array_slice($missing, 0, 20)));
        }

        $activeWars = War::query()
            ->active()
            ->whereIn('att_id', $friendlyNationIds)
            ->selectRaw('att_id, COUNT(*) as aggregate')
            ->groupBy('att_id')
            ->pluck('aggregate', 'att_id');

        $reservations = MilcomAssignment::query()
            ->whereIn('friendly_nation_id', $friendlyNationIds)
            ->whereIn('status', ['approved', 'dispatched', 'engaged'])
            ->selectRaw('friendly_nation_id, COUNT(*) as aggregate')
            ->groupBy('friendly_nation_id')
            ->pluck('aggregate', 'friendly_nation_id');

        $profiles = [];
        $friendlyLookup = array_fill_keys($friendlyNationIds, true);

        Nation::query()
            ->select([
                'id',
                'alliance_id',
                'alliance_position',
                'score',
                'num_cities',
                'vacation_mode_turns',
                'beige_turns',
                'project_bits',
            ])
            ->with([
                'military:id,nation_id,soldiers,tanks,aircraft,ships,missiles,nukes',
                'accountProfile:nation_id,last_active',
                'user:id,nation_id',
                'user.discordAccounts' => fn ($query) => $query
                    ->select(['id', 'user_id'])
                    ->whereNull('unlinked_at'),
            ])
            ->whereIn('id', $nationIds)
            ->chunkById(self::SNAPSHOT_CHUNK_SIZE, function (Collection $nations) use (
                $run,
                $activeWars,
                $reservations,
                $fetchedAt,
                $friendlyLookup,
                &$profiles,
            ): void {
                $rows = [];
                $timestamp = now();

                foreach ($nations as $nation) {
                    $profile = $this->profile(
                        $nation,
                        (int) ($activeWars[(int) $nation->id] ?? 0),
                        (int) ($reservations[(int) $nation->id] ?? 0),
                        $fetchedAt,
                    );
                    $profiles[$profile->nationId] = $profile;
                    $role = isset($friendlyLookup[$profile->nationId]) ? 'friendly' : 'target';

                    $rows[] = [
                        'recommendation_run_id' => $run->id,
                        'nation_id' => $profile->nationId,
                        'role' => $role,
                        'alliance_id' => $profile->allianceId,
                        'alliance_position' => $profile->alliancePosition,
                        'score' => $profile->score,
                        'cities' => $profile->cities,
                        'vacation_turns' => $profile->vacationTurns,
                        'beige_turns' => $profile->beigeTurns,
                        'offensive_capacity' => $this->rules->baseOffensiveCapacity($profile),
                        'active_offensive_wars' => $profile->activeOffensiveWars,
                        'reserved_offensive_slots' => $profile->reservedOffensiveSlots,
                        'soldiers' => $profile->soldiers,
                        'tanks' => $profile->tanks,
                        'aircraft' => $profile->aircraft,
                        'ships' => $profile->ships,
                        'missiles' => $profile->missiles,
                        'nukes' => $profile->nukes,
                        'last_active_at' => $profile->lastActiveAt?->format('Y-m-d H:i:s'),
                        'fetched_at' => $fetchedAt->format('Y-m-d H:i:s'),
                        'completeness_percent' => $profile->hasCompleteMilitaryData() ? 100 : 50,
                        'payload' => json_encode($profile, JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                $this->upsertSnapshots($rows);
            });

        return $profiles;
    }

    private function profile(
        Nation $nation,
        int $activeOffensiveWars,
        int $reservedOffensiveSlots,
        DateTimeInterface $fetchedAt,
    ): ReadinessProfile {
        $military = $nation->military;
        $lastActive = $nation->accountProfile?->last_active;
        $discordLinked = $nation->user?->discordAccounts instanceof Collection
            && $nation->user->discordAccounts->isNotEmpty();

        return new ReadinessProfile(
            nationId: (int) $nation->id,
            allianceId: $nation->alliance_id !== null ? (int) $nation->alliance_id : null,
            alliancePosition: (string) $nation->alliance_position,
            score: (float) $nation->score,
            cities: (int) $nation->num_cities,
            vacationTurns: (int) $nation->vacation_mode_turns,
            beigeTurns: (int) $nation->beige_turns,
            activeOffensiveWars: $activeOffensiveWars,
            reservedOffensiveSlots: $reservedOffensiveSlots,
            soldiers: $military?->soldiers !== null ? (int) $military->soldiers : null,
            tanks: $military?->tanks !== null ? (int) $military->tanks : null,
            aircraft: $military?->aircraft !== null ? (int) $military->aircraft : null,
            ships: $military?->ships !== null ? (int) $military->ships : null,
            missiles: (int) ($military?->missiles ?? 0),
            nukes: (int) ($military?->nukes ?? 0),
            lastActiveAt: $lastActive !== null
                ? new DateTimeImmutable($lastActive->toIso8601String())
                : null,
            fetchedAt: new DateTimeImmutable($fetchedAt->format(DATE_ATOM)),
            discordLinked: $discordLinked,
            projects: array_intersect_key(
                $nation->projects,
                (array) config('milcom.game_rules.offensive_slot_projects', []),
            ),
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsertSnapshots(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        MilcomReadinessSnapshot::query()->upsert(
            $rows,
            ['recommendation_run_id', 'nation_id', 'role'],
            [
                'alliance_id',
                'alliance_position',
                'score',
                'cities',
                'vacation_turns',
                'beige_turns',
                'offensive_capacity',
                'active_offensive_wars',
                'reserved_offensive_slots',
                'soldiers',
                'tanks',
                'aircraft',
                'ships',
                'missiles',
                'nukes',
                'last_active_at',
                'fetched_at',
                'completeness_percent',
                'payload',
                'updated_at',
            ],
        );
    }
}
