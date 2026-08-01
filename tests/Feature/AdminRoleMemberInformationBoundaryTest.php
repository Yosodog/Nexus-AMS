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

class AdminRoleMemberInformationBoundaryTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_role_editor_without_user_access_does_not_query_or_render_assigned_members(): void
    {
        [$role, $assignedUser, $nation] = $this->createRoleWithAssignedUser();
        $admin = $this->createAdmin(['edit-roles']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Edit Role: Operations Planner')
            ->assertDontSee('Assigned Members')
            ->assertDontSee($assignedUser->name)
            ->assertDontSee($nation->nation_name)
            ->assertDontSee('href="'.route('admin.users.edit', $assignedUser).'"', false);

        $this->assertSame(0, $this->assignedUserQueryCount($queries));
    }

    public function test_role_editor_with_user_access_can_query_and_render_assigned_members(): void
    {
        [$role, $assignedUser, $nation] = $this->createRoleWithAssignedUser();
        $admin = $this->createAdmin(['edit-roles', 'view-users']);
        $queries = $this->captureQueries();

        $this->actingAs($admin)
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Assigned Members')
            ->assertSee($assignedUser->name)
            ->assertSee($nation->nation_name)
            ->assertSee('href="'.route('admin.users.edit', $assignedUser).'"', false);

        $this->assertGreaterThan(0, $this->assignedUserQueryCount($queries));
        $this->assertTrue($this->assignedUserQueries($queries)->every(
            fn (string $query): bool => preg_match('/[`"]email[`"]/', $query) !== 1
        ));
    }

    /**
     * @return array{Role, User, Nation}
     */
    private function createRoleWithAssignedUser(): array
    {
        $role = Role::query()->create([
            'name' => 'operations planner',
            'protected' => false,
        ]);
        $nation = Nation::factory()->create([
            'nation_name' => 'Assigned Nation Boundary',
            'flag' => 'https://example.test/assigned-flag.png',
        ]);
        $assignedUser = $this->createVerifiedUser([
            'name' => 'Assigned Role Holder',
            'email' => 'assigned-role-holder@example.test',
            'nation_id' => $nation->id,
        ]);
        $assignedUser->roles()->attach($role);

        return [$role, $assignedUser, $nation];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(800_000, 849_999),
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

    /**
     * @param  Collection<int, string>  $queries
     * @return Collection<int, string>
     */
    private function assignedUserQueries(Collection $queries): Collection
    {
        return $queries->filter(
            fn (string $query): bool => preg_match(
                '/from\s+[`"]users[`"]\s+inner join\s+[`"]role_user[`"]/',
                $query
            ) === 1
        );
    }

    /**
     * @param  Collection<int, string>  $queries
     */
    private function assignedUserQueryCount(Collection $queries): int
    {
        return $this->assignedUserQueries($queries)->count();
    }
}
