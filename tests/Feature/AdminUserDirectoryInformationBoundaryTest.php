<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminUserDirectoryInformationBoundaryTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_user_viewer_does_not_query_or_render_emails_or_role_assignments(): void
    {
        [$target] = $this->createTargetUser();
        $admin = $this->createAdmin(['view-users']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($target->name)
            ->assertDontSee($target->email)
            ->assertDontSee('Restricted Access Role')
            ->assertDontSee('Manage Roles')
            ->assertSee('Name, Discord, or nation ID');

        $directoryQueries = $queries->filter(fn (string $query): bool => $this->isDirectoryUserQuery($query));

        $this->assertNotEmpty($directoryQueries);
        $this->assertTrue($directoryQueries->every(
            fn (string $query): bool => preg_match('/[`"]email[`"]/', $query) !== 1
        ));
        $assignedRoleQueries = $this->assignedRoleQueriesAfterDirectory($queries);
        $this->assertSame(0, $assignedRoleQueries->count());

        $this->actingAs($admin)
            ->get(route('admin.users.index', [
                'search' => $target->email,
                'status' => 'all',
            ]))
            ->assertOk()
            ->assertDontSee($target->name);
    }

    public function test_privileged_user_viewer_can_query_search_and_render_emails_and_roles(): void
    {
        [$target] = $this->createTargetUser();
        $admin = $this->createAdmin(['view-users', 'edit-users', 'view-roles']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.users.index', [
                'search' => $target->email,
                'status' => 'all',
            ]))
            ->assertOk()
            ->assertSee($target->name)
            ->assertSee($target->email)
            ->assertSee('Restricted Access Role')
            ->assertSee('Manage Roles')
            ->assertSee('Name, email, Discord, or nation ID');

        $directoryQuery = $queries->first(fn (string $query): bool => $this->isDirectoryUserQuery($query));

        $this->assertNotNull($directoryQuery);
        $this->assertMatchesRegularExpression('/[`"]email[`"]/', $directoryQuery);
        $this->assertGreaterThan(0, $this->assignedRoleQueriesAfterDirectory($queries)->count());
    }

    /**
     * @return array{User, Role}
     */
    private function createTargetUser(): array
    {
        $nation = Nation::factory()->create([
            'leader_name' => 'Directory Boundary Nation',
            'discord' => 'directory-boundary-discord',
        ]);
        $target = $this->createVerifiedUser([
            'name' => 'Directory Boundary User',
            'email' => 'directory-boundary@example.test',
            'nation_id' => $nation->id,
        ]);
        $role = Role::query()->create([
            'name' => 'restricted access role',
            'protected' => false,
        ]);
        $target->roles()->attach($role);

        return [$target, $role];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(750_000, 799_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions)->load('roles.permissions');
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

    private function isDirectoryUserQuery(string $query): bool
    {
        return preg_match('/from\s+[`"]users[`"]/', $query) === 1
            && str_contains($query, 'order by')
            && str_contains($query, 'last_active_at');
    }

    /**
     * @param  Collection<int, string>  $queries
     */
    private function assignedRoleQueriesAfterDirectory(Collection $queries): Collection
    {
        $directoryQueryIndex = $queries->search(fn (string $query): bool => $this->isDirectoryUserQuery($query));

        if ($directoryQueryIndex === false) {
            return collect();
        }

        return $queries->slice($directoryQueryIndex + 1)->filter(
            fn (string $query): bool => preg_match(
                '/from\s+[`"]roles[`"]\s+inner join\s+[`"]role_user[`"]/',
                $query
            ) === 1
        );
    }
}
