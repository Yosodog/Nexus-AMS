<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class RoleDelegationService
{
    /**
     * @return Collection<int, string>
     */
    public function permissionsFor(User $user): Collection
    {
        $user->loadMissing('roles.permissions');

        return $user->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('permission'))
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function grantablePermissions(User $actor): Collection
    {
        $actorPermissions = $this->permissionsFor($actor);

        return collect(config('permissions', []))
            ->filter(fn (string $permission): bool => $actorPermissions->containsStrict($permission))
            ->values();
    }

    /**
     * @return Collection<int, Role>
     */
    public function grantableRoles(User $actor): Collection
    {
        $actorPermissions = $this->permissionsFor($actor);

        return Role::query()
            ->with('permissions')
            ->where('protected', false)
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => $this->rolePermissions($role)->diff($actorPermissions)->isEmpty())
            ->values();
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        if ($role->protected) {
            return false;
        }

        return $this->rolePermissions($role)
            ->diff($this->permissionsFor($actor))
            ->isEmpty();
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanManageRole(User $actor, Role $role): void
    {
        if (! $this->canManageRole($actor, $role)) {
            throw new AuthorizationException('You cannot manage a role above your permission level.');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     *
     * @throws AuthorizationException
     */
    public function ensureCanAssignRoles(User $actor, Collection $roles): void
    {
        if ($roles->contains(fn (Role $role): bool => $role->protected)) {
            throw new AuthorizationException('Protected roles cannot be assigned from user management.');
        }

        $this->ensureCanDelegatePermissions(
            $actor,
            $roles->flatMap(fn (Role $role) => $this->rolePermissions($role))
        );
    }

    /**
     * @param  iterable<int, string>  $permissions
     *
     * @throws AuthorizationException
     */
    public function ensureCanDelegatePermissions(User $actor, iterable $permissions): void
    {
        $unauthorizedPermissions = collect($permissions)
            ->unique()
            ->diff($this->permissionsFor($actor));

        if ($unauthorizedPermissions->isNotEmpty()) {
            throw new AuthorizationException('You cannot delegate permissions you do not hold.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanManageUser(User $actor, User $target): void
    {
        $actorPermissions = $this->permissionsFor($actor);

        if ($actor->is($target) && ! $actorPermissions->containsStrict('bypass-self-restrictions')) {
            throw new AuthorizationException('You cannot change your own administrative access.');
        }

        $target->loadMissing('roles.permissions');

        if (
            $target->roles->contains(fn (Role $role): bool => $role->protected)
            && ! $actorPermissions->containsStrict('bypass-self-restrictions')
        ) {
            throw new AuthorizationException('You cannot manage a user with protected access.');
        }

        if ($this->permissionsFor($target)->diff($actorPermissions)->isNotEmpty()) {
            throw new AuthorizationException('You cannot manage a user above your permission level.');
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function rolePermissions(Role $role): Collection
    {
        $role->loadMissing('permissions');

        return $role->permissions->pluck('permission')->unique()->values();
    }
}
