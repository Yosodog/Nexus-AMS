<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\Nation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PendingKeyConstraintsTest extends MySqlIntegrationTestCase
{
    public function test_loans_allow_only_one_pending_request_per_nation(): void
    {
        Nation::factory()->create(['id' => 2026, 'alliance_id' => null]);
        Account::query()->create([
            'id' => 88,
            'nation_id' => 2026,
            'name' => 'Main',
        ]);

        DB::table('loans')->insert([
            'nation_id' => 2026,
            'account_id' => 88,
            'amount' => 100000,
            'interest_rate' => 5,
            'term_weeks' => 4,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('loans')->insert([
            'nation_id' => 2026,
            'account_id' => 88,
            'amount' => 250000,
            'interest_rate' => 5,
            'term_weeks' => 4,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_terminal_state_can_clear_pending_key_and_allow_a_new_loan(): void
    {
        Nation::factory()->create(['id' => 2026, 'alliance_id' => null]);
        Account::query()->create([
            'id' => 88,
            'nation_id' => 2026,
            'name' => 'Main',
        ]);

        $loanId = DB::table('loans')->insertGetId([
            'nation_id' => 2026,
            'account_id' => 88,
            'amount' => 100000,
            'interest_rate' => 5,
            'term_weeks' => 4,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('loans')
            ->where('id', $loanId)
            ->update([
                'status' => 'approved',
                'pending_key' => null,
                'updated_at' => now(),
            ]);

        DB::table('loans')->insert([
            'nation_id' => 2026,
            'account_id' => 88,
            'amount' => 250000,
            'interest_rate' => 5,
            'term_weeks' => 4,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('loans')->count());
    }
}
