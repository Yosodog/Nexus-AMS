<?php

namespace Tests\Unit\Milcom\Concerns;

use App\Domain\Milcom\ReadinessProfile;
use DateTimeImmutable;

trait BuildsReadinessProfiles
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function profile(array $overrides = []): ReadinessProfile
    {
        $now = new DateTimeImmutable('2026-08-02T12:00:00+00:00');
        $values = array_replace([
            'nationId' => 1,
            'allianceId' => 100,
            'alliancePosition' => 'MEMBER',
            'score' => 1000.0,
            'cities' => 10,
            'vacationTurns' => 0,
            'beigeTurns' => 0,
            'activeOffensiveWars' => 0,
            'reservedOffensiveSlots' => 0,
            'soldiers' => 150000,
            'tanks' => 12500,
            'aircraft' => 750,
            'ships' => 150,
            'missiles' => 0,
            'nukes' => 0,
            'lastActiveAt' => $now,
            'fetchedAt' => $now,
            'discordLinked' => true,
            'projects' => [],
        ], $overrides);

        return new ReadinessProfile(
            nationId: $values['nationId'],
            allianceId: $values['allianceId'],
            alliancePosition: $values['alliancePosition'],
            score: $values['score'],
            cities: $values['cities'],
            vacationTurns: $values['vacationTurns'],
            beigeTurns: $values['beigeTurns'],
            activeOffensiveWars: $values['activeOffensiveWars'],
            reservedOffensiveSlots: $values['reservedOffensiveSlots'],
            soldiers: $values['soldiers'],
            tanks: $values['tanks'],
            aircraft: $values['aircraft'],
            ships: $values['ships'],
            missiles: $values['missiles'],
            nukes: $values['nukes'],
            lastActiveAt: $values['lastActiveAt'],
            fetchedAt: $values['fetchedAt'],
            discordLinked: $values['discordLinked'],
            projects: $values['projects'],
        );
    }
}
