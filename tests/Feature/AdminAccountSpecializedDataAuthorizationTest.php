<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminAccountSpecializedDataAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_account_viewer_does_not_query_or_see_direct_deposit_or_mmr_data(): void
    {
        $admin = $this->createAdmin(['view-accounts']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.accounts.dashboard'))
            ->assertOk()
            ->assertDontSee('Direct Deposit Settings')
            ->assertDontSee('Direct Deposit Logs')
            ->assertDontSee('MMR Assistant Purchases');

        foreach ($this->directDepositTables() as $table) {
            $this->assertSame(0, $this->queryCountSelectingFrom($queries, $table), "Unexpected query against {$table}.");
        }

        $this->assertSame(0, $this->queryCountSelectingFrom($queries, 'mmr_assistant_purchases'));
    }

    public function test_direct_deposit_viewer_queries_and_sees_only_direct_deposit_data(): void
    {
        $admin = $this->createAdmin(['view-accounts', 'view-dd']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.accounts.dashboard'))
            ->assertOk()
            ->assertSee('Direct Deposit Settings')
            ->assertSee('Direct Deposit Logs')
            ->assertDontSee('MMR Assistant Purchases');

        foreach ($this->directDepositTables() as $table) {
            $this->assertGreaterThan(0, $this->queryCountSelectingFrom($queries, $table), "Expected query against {$table}.");
        }

        $this->assertSame(0, $this->queryCountSelectingFrom($queries, 'mmr_assistant_purchases'));
    }

    public function test_mmr_viewer_queries_and_sees_only_mmr_purchase_data(): void
    {
        $admin = $this->createAdmin(['view-accounts', 'view-mmr']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.accounts.dashboard'))
            ->assertOk()
            ->assertDontSee('Direct Deposit Settings')
            ->assertDontSee('Direct Deposit Logs')
            ->assertSee('MMR Assistant Purchases');

        foreach ($this->directDepositTables() as $table) {
            $this->assertSame(0, $this->queryCountSelectingFrom($queries, $table), "Unexpected query against {$table}.");
        }

        $this->assertGreaterThan(0, $this->queryCountSelectingFrom($queries, 'mmr_assistant_purchases'));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(600_000, 699_999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    /**
     * @return array<int, string>
     */
    private function directDepositTables(): array
    {
        return [
            'direct_deposit_tax_brackets',
            'direct_deposit_enrollments',
            'direct_deposit_logs',
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function captureQueries(): Collection
    {
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        return $queries;
    }

    /** @param Collection<int, string> $queries */
    private function queryCountSelectingFrom(Collection $queries, string $table): int
    {
        $pattern = '/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i';

        return $queries->filter(fn (string $query): bool => preg_match($pattern, $query) === 1)->count();
    }
}
