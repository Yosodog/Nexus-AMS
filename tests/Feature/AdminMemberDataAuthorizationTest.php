<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminMemberDataAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_member_only_admin_does_not_query_or_see_financial_member_data(): void
    {
        $admin = $this->createAdmin(['view-members']);
        $nation = $this->createMemberNation();
        $this->createAccount($nation, 'Restricted Member Vault');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($admin)
            ->get(route('admin.members'))
            ->assertOk()
            ->assertSee($nation->leader_name)
            ->assertDontSee('Build Profitability');

        $this->actingAs($admin)
            ->get(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertOk()
            ->assertDontSee('Total Taxes (30d)')
            ->assertDontSee('Recent Loan Requests')
            ->assertDontSee('Recent Grant Requests')
            ->assertDontSee('Recent City Grant Requests')
            ->assertDontSee('Recent Taxes Paid')
            ->assertDontSee('Money History (30 Days)')
            ->assertDontSee('Account Overview')
            ->assertDontSee('Restricted Member Vault');

        foreach (['accounts', 'taxes', 'nation_resources', 'nation_military'] as $table) {
            $this->assertSame(0, $this->queryCountSelectingFrom($queries, $table), "Unexpected query against {$table}.");
        }

        foreach (['loans', 'grant_applications', 'city_grant_requests'] as $table) {
            $this->assertSame(0, $this->queryCountSelectingByNation($queries, $table), "Unexpected member-detail query against {$table}.");
        }

        $this->assertSame(0, $this->queryCountContaining($queries, 'nation_profitability_snapshots'));
        $this->assertSame(0, $this->queryCountSelectingColumnFrom($queries, 'nation_sign_ins', 'money'));
    }

    public function test_admin_with_financial_permissions_can_query_and_see_member_sections(): void
    {
        $admin = $this->createAdmin([
            'view-members',
            'view-accounts',
            'view-city-grants',
            'view-financial-reports',
            'view-grants',
            'view-loans',
            'view-mmr',
            'view-taxes',
        ]);
        $nation = $this->createMemberNation();
        $this->createAccount($nation, 'Visible Member Vault');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($admin)
            ->get(route('admin.members'))
            ->assertOk()
            ->assertSee('Build Profitability');

        $this->actingAs($admin)
            ->get(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertOk()
            ->assertSee('Total Taxes (30d)')
            ->assertSee('Recent Loan Requests')
            ->assertSee('Recent Grant Requests')
            ->assertSee('Recent City Grant Requests')
            ->assertSee('Recent Taxes Paid')
            ->assertSee('Money History (30 Days)')
            ->assertSee('Account Overview')
            ->assertSee('Visible Member Vault');

        foreach (['accounts', 'taxes', 'nation_resources', 'nation_military'] as $table) {
            $this->assertGreaterThan(0, $this->queryCountSelectingFrom($queries, $table), "Expected query against {$table}.");
        }

        foreach (['loans', 'grant_applications', 'city_grant_requests'] as $table) {
            $this->assertGreaterThan(0, $this->queryCountSelectingByNation($queries, $table), "Expected member-detail query against {$table}.");
        }

        $this->assertGreaterThan(0, $this->queryCountSelectingColumnFrom($queries, 'nation_sign_ins', 'money'));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(800_000, 899_999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    private function createMemberNation(): Nation
    {
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'leader_name' => 'Authorization Test Leader',
        ]);
    }

    private function createAccount(Nation $nation, string $name): Account
    {
        $account = new Account;
        $account->forceFill([
            'nation_id' => $nation->id,
            'name' => $name,
            'money' => 123456.78,
        ])->save();

        return $account;
    }

    /** @param array<int, string> $queries */
    private function queryCountContaining(array $queries, string $needle): int
    {
        return collect($queries)->filter(fn (string $query): bool => str_contains($query, $needle))->count();
    }

    /** @param array<int, string> $queries */
    private function queryCountSelectingFrom(array $queries, string $table): int
    {
        $pattern = '/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i';

        return collect($queries)->filter(fn (string $query): bool => preg_match($pattern, $query) === 1)->count();
    }

    /** @param array<int, string> $queries */
    private function queryCountSelectingByNation(array $queries, string $table): int
    {
        return collect($queries)->filter(
            fn (string $query): bool => $this->querySelectsFrom($query, $table)
                && preg_match('/where\s+[`"]?nation_id[`"]?\s*=/', $query) === 1
        )->count();
    }

    /** @param array<int, string> $queries */
    private function queryCountSelectingColumnFrom(array $queries, string $table, string $column): int
    {
        return collect($queries)->filter(
            fn (string $query): bool => $this->querySelectsFrom($query, $table)
                && preg_match('/[`"]?'.preg_quote($column, '/').'[`"]?/', $query) === 1
        )->count();
    }

    private function querySelectsFrom(string $query, string $table): bool
    {
        return preg_match('/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i', $query) === 1;
    }
}
