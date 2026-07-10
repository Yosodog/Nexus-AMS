<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminRoleDelegationAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_limited_admin_cannot_change_their_own_administrative_access(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users', 'edit-roles']);
        $protectedRole = $this->createRole('default admin', ['bypass-self-restrictions'], true);
        $originalEmail = $actor->email;

        $this->actingAs($actor)
            ->put(route('admin.users.update', $actor), $this->userPayload($actor, [
                'email' => 'attacker@example.test',
                'roles' => [$protectedRole->id],
            ]))
            ->assertForbidden();

        $actor->refresh();

        $this->assertSame($originalEmail, $actor->email);
        $this->assertFalse($actor->roles->contains($protectedRole));
    }

    public function test_limited_admin_cannot_mutate_a_user_with_protected_access(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users', 'edit-roles']);
        $target = $this->createUser(true);
        $protectedRole = $this->createRole('protected operator', ['view-users'], true);
        $target->roles()->attach($protectedRole);
        $originalPassword = $target->password;

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                'name' => 'Locked Out Root',
                'is_admin' => '0',
                'disabled' => '1',
                'password' => 'malicious-password',
                'password_confirmation' => 'malicious-password',
                'roles' => [],
            ]))
            ->assertForbidden();

        $target->refresh();

        $this->assertNotSame('Locked Out Root', $target->name);
        $this->assertTrue((bool) $target->is_admin);
        $this->assertFalse($target->disabled);
        $this->assertSame($originalPassword, $target->password);
        $this->assertTrue($target->roles->contains($protectedRole));
    }

    public function test_admin_cannot_assign_a_role_containing_permissions_they_do_not_hold(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users', 'edit-roles']);
        $target = $this->createUser();
        $superiorRole = $this->createRole('loan administrator', ['manage-loans']);
        $originalName = $target->name;

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                'name' => 'Partially Mutated',
                'roles' => [$superiorRole->id],
            ]))
            ->assertForbidden();

        $target->refresh();

        $this->assertSame($originalName, $target->name);
        $this->assertFalse($target->roles->contains($superiorRole));
    }

    public function test_admin_can_assign_a_non_protected_role_within_their_permission_ceiling(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users', 'edit-roles', 'view-users']);
        $target = $this->createUser();
        $grantableRole = $this->createRole('user viewer', ['view-users']);

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                'roles' => [$grantableRole->id],
            ]))
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->roles->contains($grantableRole));
    }

    public function test_protected_memberships_are_preserved_during_an_authorized_role_update(): void
    {
        $actor = $this->createAdminWithPermissions(config('permissions'));
        $target = $this->createUser(true);
        $protectedRole = $this->createRole('protected operator', ['view-users'], true);
        $grantableRole = $this->createRole('user viewer', ['view-users']);
        $target->roles()->attach($protectedRole);

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                'roles' => [$grantableRole->id],
            ]))
            ->assertRedirect(route('admin.users.index'));

        $assignedRoleIds = $target->fresh()->roles->pluck('id');

        $this->assertTrue($assignedRoleIds->contains($protectedRole->id));
        $this->assertTrue($assignedRoleIds->contains($grantableRole->id));
    }

    public function test_user_editor_without_role_permission_can_update_profile_without_changing_roles(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users']);
        $target = $this->createUser();
        $existingRole = $this->createRole('existing assignment');
        $target->roles()->attach($existingRole);

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                'name' => 'Updated Member',
            ]))
            ->assertRedirect(route('admin.users.index'));

        $target->refresh();

        $this->assertSame('Updated Member', $target->name);
        $this->assertTrue($target->roles->contains($existingRole));
    }

    public function test_role_editor_cannot_create_or_expand_roles_above_their_permission_ceiling(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-roles', 'view-roles']);
        $editableRole = $this->createRole('editable role', ['view-roles']);

        $this->actingAs($actor)
            ->post(route('admin.roles.store'), [
                'name' => 'escalated role',
                'permissions' => ['manage-loans'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'escalated role']);

        $this->actingAs($actor)
            ->put(route('admin.roles.update', $editableRole), [
                'name' => 'mutated role',
                'permissions' => ['view-roles', 'manage-loans'],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $editableRole->id,
            'name' => 'editable role',
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $editableRole->id,
            'permission' => 'manage-loans',
        ]);
    }

    public function test_role_editor_cannot_edit_or_delete_a_superior_role(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-roles', 'view-roles']);
        $superiorRole = $this->createRole('superior role', ['manage-loans']);

        $this->actingAs($actor)
            ->get(route('admin.roles.edit', $superiorRole))
            ->assertForbidden();

        $this->actingAs($actor)
            ->delete(route('admin.roles.destroy', $superiorRole))
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $superiorRole->id]);
    }

    public function test_role_and_user_forms_only_show_delegable_access(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users', 'edit-roles', 'view-users']);
        $target = $this->createUser();
        $grantableRole = $this->createRole('grantable role', ['view-users']);
        $superiorRole = $this->createRole('superior role', ['manage-loans']);
        $protectedRole = $this->createRole('protected role', ['view-users'], true);

        $this->actingAs($actor)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('<option value="'.$grantableRole->id.'"', false)
            ->assertDontSee('<option value="'.$superiorRole->id.'"', false)
            ->assertDontSee('<option value="'.$protectedRole->id.'"', false);

        $this->actingAs($actor)
            ->get(route('admin.roles.create'))
            ->assertOk()
            ->assertSee('value="view-users"', false)
            ->assertDontSee('value="manage-loans"', false);
    }

    public function test_user_editor_cannot_change_global_mfa_policy_without_break_glass_permission(): void
    {
        $actor = $this->createAdminWithPermissions(['edit-users']);

        $this->actingAs($actor)
            ->post(route('admin.users.mfa-requirements'), [
                'require_mfa_all_users' => '1',
                'require_mfa_admins' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse(SettingService::isMfaRequiredForAllUsers());
        $this->assertFalse(SettingService::isMfaRequiredForAdmins());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): User
    {
        $admin = $this->createUser(true);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    private function createUser(bool $admin = false): User
    {
        $attributes = ['nation_id' => Nation::factory()->create()->id];

        return $admin
            ? $this->createVerifiedAdmin($attributes)
            : $this->createVerifiedUser($attributes);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createRole(string $name, array $permissions = [], bool $protected = false): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'protected' => $protected,
        ]);

        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }

        return $role->load('permissions');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin ? '1' : '0',
            'disabled' => $user->disabled ? '1' : '0',
            'nation_id' => $user->nation_id,
            ...$overrides,
        ];
    }
}
