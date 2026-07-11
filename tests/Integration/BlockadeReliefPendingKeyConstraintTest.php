<?php

namespace Tests\Integration;

use App\Models\Nation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BlockadeReliefPendingKeyConstraintTest extends MySqlIntegrationTestCase
{
    public function test_only_one_active_request_is_allowed_per_requester_and_war(): void
    {
        [$requester, $blockader] = $this->nations();
        $this->insertRequest($requester->id, $blockader->id, 5001);

        $this->expectException(QueryException::class);
        $this->insertRequest($requester->id, $blockader->id, 5001);
    }

    public function test_terminal_request_clears_pending_key_and_preserves_history(): void
    {
        [$requester, $blockader] = $this->nations();
        $firstId = $this->insertRequest($requester->id, $blockader->id, 5002);

        DB::table('blockade_relief_requests')->where('id', $firstId)->update([
            'status' => 'resolved',
            'pending_key' => null,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertRequest($requester->id, $blockader->id, 5002);

        $this->assertSame(2, DB::table('blockade_relief_requests')->count());
    }

    /** @return array{0:Nation,1:Nation} */
    private function nations(): array
    {
        return [
            Nation::factory()->create(['alliance_id' => 777]),
            Nation::factory()->create(['alliance_id' => 999]),
        ];
    }

    private function insertRequest(int $requesterId, int $blockaderId, int $warId): int
    {
        return DB::table('blockade_relief_requests')->insertGetId([
            'requester_nation_id' => $requesterId,
            'war_id' => $warId,
            'blockading_nation_id' => $blockaderId,
            'status' => 'pending',
            'pending_key' => 1,
            'deadline_at' => now()->addHours(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
