<?php

namespace Tests\Integration;

use App\Enums\ApplicationStatus;
use App\Models\Account;
use App\Models\Grants;
use App\Models\Nation;
use App\Services\GrantService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PendingKeyConstraintsTest extends MySqlIntegrationTestCase
{
    private function createNationWithAccount(int $nationId, int $accountId): void
    {
        Nation::factory()->create(['id' => $nationId, 'alliance_id' => null]);
        Account::query()->create([
            'id' => $accountId,
            'nation_id' => $nationId,
            'name' => 'Main',
        ]);
    }

    public function test_loans_allow_only_one_pending_request_per_nation(): void
    {
        $this->createNationWithAccount(2026, 88);

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
        $this->createNationWithAccount(2026, 88);

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

    public function test_grant_applications_allow_only_one_pending_request_per_grant_and_nation(): void
    {
        $this->createNationWithAccount(2030, 90);

        DB::table('grant_applications')->insert([
            'grant_id' => 44,
            'nation_id' => 2030,
            'account_id' => 90,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('grant_applications')->insert([
            'grant_id' => 44,
            'nation_id' => 2030,
            'account_id' => 90,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_city_grant_requests_allow_only_one_pending_request_per_nation(): void
    {
        $this->createNationWithAccount(2031, 91);

        DB::table('city_grant_requests')->insert([
            'city_number' => 12,
            'grant_amount' => 750000,
            'nation_id' => 2031,
            'account_id' => 91,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('city_grant_requests')->insert([
            'city_number' => 13,
            'grant_amount' => 900000,
            'nation_id' => 2031,
            'account_id' => 91,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_war_aid_requests_allow_only_one_pending_request_per_nation(): void
    {
        $this->createNationWithAccount(2032, 92);

        DB::table('war_aid_requests')->insert([
            'nation_id' => 2032,
            'account_id' => 92,
            'note' => 'First request',
            'money' => 500000,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('war_aid_requests')->insert([
            'nation_id' => 2032,
            'account_id' => 92,
            'note' => 'Second request',
            'money' => 600000,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rebuilding_requests_allow_only_one_pending_request_per_nation_and_cycle(): void
    {
        $this->createNationWithAccount(2033, 93);

        DB::table('rebuilding_requests')->insert([
            'cycle_id' => 7,
            'nation_id' => 2033,
            'account_id' => 93,
            'city_count_snapshot' => 12,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 4500000,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('rebuilding_requests')->insert([
            'cycle_id' => 7,
            'nation_id' => 2033,
            'account_id' => 93,
            'city_count_snapshot' => 12,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 4500000,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deposit_requests_allow_only_one_pending_request_per_account(): void
    {
        $this->createNationWithAccount(2034, 94);

        DB::table('deposit_requests')->insert([
            'account_id' => 94,
            'deposit_code' => 'DEPOSIT1',
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('deposit_requests')->insert([
            'account_id' => 94,
            'deposit_code' => 'DEPOSIT2',
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_applications_allow_only_one_pending_request_per_nation(): void
    {
        DB::table('applications')->insert([
            'nation_id' => 2035,
            'leader_name_snapshot' => 'Leader One',
            'discord_user_id' => 'discord-2035-a',
            'discord_username' => 'user-a',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('applications')->insert([
            'nation_id' => 2035,
            'leader_name_snapshot' => 'Leader One Again',
            'discord_user_id' => 'discord-2035-b',
            'discord_username' => 'user-b',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_applications_allow_only_one_pending_request_per_discord_user(): void
    {
        DB::table('applications')->insert([
            'nation_id' => 2036,
            'leader_name_snapshot' => 'Leader Two',
            'discord_user_id' => 'discord-shared',
            'discord_username' => 'shared-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('applications')->insert([
            'nation_id' => 2037,
            'leader_name_snapshot' => 'Leader Three',
            'discord_user_id' => 'discord-shared',
            'discord_username' => 'shared-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_pending_grant_insert_is_translated_into_a_validation_error(): void
    {
        $this->createNationWithAccount(2040, 95);

        $grant = Grants::query()->create([
            'name' => 'Integration Grant',
            'slug' => 'integration-grant',
            'description' => 'Grant',
            'money' => 1000,
            'coal' => 0,
            'oil' => 0,
            'uranium' => 0,
            'iron' => 0,
            'bauxite' => 0,
            'lead' => 0,
            'gasoline' => 0,
            'munitions' => 0,
            'steel' => 0,
            'aluminum' => 0,
            'food' => 0,
            'validation_rules' => [],
            'is_enabled' => true,
            'is_one_time' => false,
        ]);

        DB::table('grant_applications')->insert([
            'grant_id' => $grant->id,
            'nation_id' => 2040,
            'account_id' => 95,
            'status' => 'pending',
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You already have a pending application for this grant.');

        GrantService::createApplication($grant, 2040, 95);
    }
}
