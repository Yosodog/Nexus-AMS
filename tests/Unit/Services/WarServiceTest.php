<?php

namespace Tests\Unit\Services;

use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\WarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.pw.alliance_id' => 777]);
        app(AllianceMembershipService::class)->clear();
    }

    public function test_active_war_counts_preserve_nation_ids_and_sum_both_sides(): void
    {
        $this->createWar(1, attackerId: 101, defenderId: 201, attackerAllianceId: 777);
        $this->createWar(2, attackerId: 101, defenderId: 202, attackerAllianceId: 777);
        $this->createWar(3, attackerId: 203, defenderId: 101, defenderAllianceId: 777);

        $counts = app(WarService::class)->getTopNationsWithActiveWars(10);

        $this->assertSame(3, $counts[101]);
        $this->assertSame(1, $counts[201]);
        $this->assertSame(1, $counts[202]);
        $this->assertSame(1, $counts[203]);
    }

    private function createWar(
        int $id,
        int $attackerId,
        int $defenderId,
        ?int $attackerAllianceId = null,
        ?int $defenderAllianceId = null,
    ): void {
        War::query()->create([
            'id' => $id,
            'date' => now(),
            'reason' => "War {$id}",
            'war_type' => 'ORDINARY',
            'turns_left' => 10,
            'att_id' => $attackerId,
            'att_alliance_id' => $attackerAllianceId,
            'att_alliance_position' => $attackerAllianceId ? 'MEMBER' : 'NOALLIANCE',
            'def_id' => $defenderId,
            'def_alliance_id' => $defenderAllianceId,
            'def_alliance_position' => $defenderAllianceId ? 'MEMBER' : 'NOALLIANCE',
        ]);
    }
}
