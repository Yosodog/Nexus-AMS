<?php

namespace Tests\Feature\Raids;

use App\Models\Alliance;
use App\Models\RaidAllianceProfile;
use App\Models\War;
use App\Services\Raids\RaidAllianceProfileBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidAllianceProfileBuilderTest extends TestCase
{
    use RefreshDatabase;

    private int $warId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    }

    public function test_counter_rate_uses_countered_raids_with_a_beta_prior(): void
    {
        $defenders = Alliance::factory()->create(['score' => 80_000]);

        foreach (range(1, 10) as $attacker) {
            $this->war($attacker, 500 + $attacker, $defenders->id, now()->subDays(5));
        }

        $this->war(900, 1, $defenders->id, now()->subDays(5), attackerAllianceId: $defenders->id, type: 'ORDINARY', defenderAllianceId: 0, dateOffsetHours: 10);
        $this->war(901, 2, $defenders->id, now()->subDays(5), attackerAllianceId: $defenders->id, type: 'ORDINARY', defenderAllianceId: 0, dateOffsetHours: 60);
        $this->war(3, 700, $defenders->id, now()->subDays(40));
        $this->war(4, 701, $defenders->id, now()->subDays(5), position: 'APPLICANT');

        $this->assertSame(1, app(RaidAllianceProfileBuilder::class)->refreshCounterRates());

        $profile = RaidAllianceProfile::query()->findOrFail($defenders->id);
        $this->assertSame(10, $profile->raids_received_30d);
        $this->assertSame(1, $profile->countered_30d);
        $this->assertSame(0.1, $profile->counter_rate);
        $this->assertSame(80_000.0, $profile->alliance_score);
    }

    public function test_alliances_with_bank_evidence_but_no_raids_get_the_prior_rate(): void
    {
        $alliance = Alliance::factory()->create(['score' => 10_000]);
        RaidAllianceProfile::factory()->withBank(['money' => 5_000_000])->create([
            'alliance_id' => $alliance->id,
            'raids_received_30d' => 7,
            'countered_30d' => 7,
            'counter_rate' => 0.8,
        ]);

        app(RaidAllianceProfileBuilder::class)->refreshCounterRates();

        $profile = RaidAllianceProfile::query()->findOrFail($alliance->id);
        $this->assertSame(0, $profile->raids_received_30d);
        $this->assertSame(0, $profile->countered_30d);
        $this->assertSame(0.1, $profile->counter_rate);
        $this->assertSame(5_000_000.0, $profile->bank_money);
    }

    private function war(
        int $attackerId,
        int $defenderId,
        int $allianceId,
        CarbonInterface $date,
        ?int $attackerAllianceId = 77,
        string $type = 'RAID',
        ?int $defenderAllianceId = null,
        int $dateOffsetHours = 0,
        string $position = 'MEMBER',
    ): void {
        War::query()->create([
            'id' => $this->warId++,
            'date' => $date->copy()->addHours($dateOffsetHours),
            'reason' => 'Counter rate test',
            'war_type' => $type,
            'turns_left' => 10,
            'att_id' => $attackerId,
            'att_alliance_id' => $attackerAllianceId,
            'att_alliance_position' => 'MEMBER',
            'def_id' => $defenderId,
            'def_alliance_id' => $defenderAllianceId ?? $allianceId,
            'def_alliance_position' => $position,
        ]);
    }
}
