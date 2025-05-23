<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoleController extends Controller
{
    use AuthorizesRequests;

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view-roles');

        $roles = Role::orderBy('protected', 'desc')->orderBy('name')->get();

        return view('admin.roles.index', compact('roles'));
    }

    /**
     * @param Role $role
     * @return View
     * @throws AuthorizationException
     */
    public function edit(Role $role): View
    {
        $this->authorize('edit-roles');

        $permissions = config('permissions');

        return view('admin.roles.edit', compact('role', 'permissions'));
    }

    /**
     * @param Request $request
     * @param Role $role
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('edit-roles');

        if ($role->protected) {
            return redirect()->route('admin.roles.index')->with([
                'alert-message' => 'Protected roles cannot be edited.',
                'alert-type' => 'error',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', config('permissions'))],
        ]);

        $role->update(['name' => $validated['name']]);

        DB::table('role_permissions')->where('role_id', $role->id)->delete();

        foreach ($validated['permissions'] ?? [] as $permission) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-roles');

        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', config('permissions'))],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'protected' => false,
        ]);

        foreach ($validated['permissions'] ?? [] as $permission) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role created successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function create(): View
    {
        $this->authorize('edit-roles');

        $permissions = config('permissions');

        return view('admin.roles.create', compact('permissions'));
    }

    /**
     * @param Role $role
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('edit-roles');

        if ($role->protected) {
            return redirect()->route('admin.roles.index')->with([
                'alert-message' => 'Protected roles cannot be deleted.',
                'alert-type' => 'error',
            ]);
        }

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        $role->users()->detach();
        $role->delete();

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role deleted successfully.',
            'alert-type' => 'success',
        ]);
    }
}
