<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Nation;
use App\Models\NationSignIn;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminUserDataAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_user_editor_does_not_query_or_see_finance_or_mmr_sections(): void
    {
        [$target, $account] = $this->createTargetUserWithSensitiveData();
        $admin = $this->createAdmin(['edit-users']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertDontSee('Account Overview')
            ->assertDontSee('Associated Accounts')
            ->assertDontSee('Recent Transactions')
            ->assertDontSee('Latest Nation Sign-In Snapshot')
            ->assertDontSee($account->name)
            ->assertDontSee('$987,654.32', false);

        $this->assertSame(0, $this->queryCountSelectingFrom($queries, 'accounts'));
        $this->assertSame(0, $this->queryCountSelectingFrom($queries, 'nation_sign_ins'));
        $this->assertSame(0, $this->queryCountContaining($queries, 'from_account_id'));
    }

    public function test_user_editor_with_finance_and_mmr_permissions_queries_and_sees_sections(): void
    {
        [$target, $account] = $this->createTargetUserWithSensitiveData();
        $admin = $this->createAdmin(['edit-users', 'view-accounts', 'view-mmr']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Account Overview')
            ->assertSee('Associated Accounts')
            ->assertSee('Recent Transactions')
            ->assertSee('Latest Nation Sign-In Snapshot')
            ->assertSee($account->name)
            ->assertSee('$987,654.32', false);

        $this->assertGreaterThan(0, $this->queryCountSelectingFrom($queries, 'accounts'));
        $this->assertGreaterThan(0, $this->queryCountSelectingFrom($queries, 'nation_sign_ins'));
        $this->assertGreaterThan(0, $this->queryCountContaining($queries, 'from_account_id'));
    }

    /**
     * @return array{User, Account}
     */
    private function createTargetUserWithSensitiveData(): array
    {
        $nation = Nation::factory()->create([
            'id' => fake()->unique()->numberBetween(500_000, 549_999),
            'leader_name' => 'User Editor Target',
        ]);
        $target = $this->createVerifiedUser(['nation_id' => $nation->id]);

        $account = new Account;
        $account->forceFill([
            'nation_id' => $nation->id,
            'name' => 'User Editor Secret Account',
            'money' => 123_456.78,
        ])->save();

        $signIn = new NationSignIn;
        $attributes = collect($signIn->getFillable())
            ->reject(fn (string $attribute): bool => in_array($attribute, ['mmr_score', 'created_at'], true))
            ->mapWithKeys(fn (string $attribute): array => [$attribute => 0])
            ->all();

        NationSignIn::query()->create([
            ...$attributes,
            'nation_id' => $nation->id,
            'num_cities' => 10,
            'score' => 2_500,
            'money' => 987_654.32,
            'created_at' => now(),
        ]);

        return [$target, $account];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(550_000, 599_999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
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
    private function queryCountContaining(Collection $queries, string $needle): int
    {
        return $queries->filter(fn (string $query): bool => str_contains($query, $needle))->count();
    }

    /** @param Collection<int, string> $queries */
    private function queryCountSelectingFrom(Collection $queries, string $table): int
    {
        $pattern = '/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i';

        return $queries->filter(fn (string $query): bool => preg_match($pattern, $query) === 1)->count();
    }
}
