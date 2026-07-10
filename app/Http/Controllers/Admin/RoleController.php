<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\RoleDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RoleDelegationService $roleDelegationService,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function index(Request $request): View
    {
        $this->authorize('view-roles');

        $roles = Role::with([
            'permissions' => fn ($query) => $query->orderBy('permission'),
        ])
            ->withCount('users')
            ->orderBy('protected', 'desc')
            ->orderBy('name')
            ->get();

        $stats = [
            'total_roles' => $roles->count(),
            'protected_roles' => $roles->where('protected', true)->count(),
            'unique_permissions' => $roles->flatMap(fn ($role) => $role->permissions->pluck('permission'))->unique()->count(),
        ];

        $manageableRoleIds = $request->user()->can('edit-roles')
            ? $roles
                ->filter(fn (Role $role): bool => $this->roleDelegationService->canManageRole($request->user(), $role))
                ->pluck('id')
            : collect();

        return view('admin.roles.index', compact('roles', 'stats', 'manageableRoleIds'));
    }

    /**
     * @throws AuthorizationException
     */
    public function edit(Request $request, Role $role): View
    {
        $this->authorize('edit-roles');
        $this->roleDelegationService->ensureCanManageRole($request->user(), $role);

        $permissions = $this->roleDelegationService
            ->grantablePermissions($request->user())
            ->all();
        $role->load([
            'users' => fn ($query) => $query->select('users.id', 'users.name', 'users.email', 'users.nation_id')->orderBy('name'),
            'users.nation:id,nation_name,flag',
        ]);

        return view('admin.roles.edit', compact('role', 'permissions'));
    }

    /**
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

        $this->roleDelegationService->ensureCanManageRole($request->user(), $role);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', config('permissions'))],
        ]);

        $permissions = collect($validated['permissions'] ?? [])->unique()->values();
        $this->roleDelegationService->ensureCanDelegatePermissions($request->user(), $permissions);

        $role->loadMissing('permissions');
        $beforePermissions = $role->permissions->pluck('permission')->sort()->values()->all();
        $beforeName = $role->name;

        DB::transaction(function () use ($role, $validated, $permissions): void {
            $role->update(['name' => $validated['name']]);

            DB::table('role_permissions')->where('role_id', $role->id)->delete();

            if ($permissions->isNotEmpty()) {
                DB::table('role_permissions')->insert(
                    $permissions
                        ->map(fn (string $permission): array => [
                            'role_id' => $role->id,
                            'permission' => $permission,
                        ])
                        ->all()
                );
            }
        });

        $afterPermissions = $permissions->sort()->values()->all();
        $changes = [];

        if ($beforeName !== $role->name) {
            $changes['name'] = [
                'from' => $beforeName,
                'to' => $role->name,
            ];
        }

        if ($beforePermissions !== $afterPermissions) {
            $changes['permissions'] = [
                'from' => $beforePermissions,
                'to' => $afterPermissions,
            ];
        }

        $this->auditLogger->recordAfterCommit(
            category: 'admin',
            action: 'role_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $role,
            context: [
                'changes' => $changes,
            ],
            message: 'Role updated.'
        );

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-roles');

        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', config('permissions'))],
        ]);

        $permissions = collect($validated['permissions'] ?? [])->unique()->values();
        $this->roleDelegationService->ensureCanDelegatePermissions($request->user(), $permissions);

        $role = DB::transaction(function () use ($validated, $permissions): Role {
            $role = Role::create([
                'name' => $validated['name'],
                'protected' => false,
            ]);

            if ($permissions->isNotEmpty()) {
                DB::table('role_permissions')->insert(
                    $permissions
                        ->map(fn (string $permission): array => [
                            'role_id' => $role->id,
                            'permission' => $permission,
                        ])
                        ->all()
                );
            }

            return $role;
        });

        $this->auditLogger->success(
            category: 'admin',
            action: 'role_created',
            subject: $role,
            context: [
                'data' => [
                    'name' => $role->name,
                    'permissions' => $permissions->all(),
                ],
            ],
            message: 'Role created.'
        );

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role created successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function create(Request $request): View
    {
        $this->authorize('edit-roles');

        $permissions = $this->roleDelegationService
            ->grantablePermissions($request->user())
            ->all();

        return view('admin.roles.create', compact('permissions'));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('edit-roles');

        if ($role->protected) {
            return redirect()->route('admin.roles.index')->with([
                'alert-message' => 'Protected roles cannot be deleted.',
                'alert-type' => 'error',
            ]);
        }

        $this->roleDelegationService->ensureCanManageRole($request->user(), $role);

        $role->loadMissing('permissions');
        $permissions = $role->permissions->pluck('permission')->sort()->values()->all();

        DB::transaction(function () use ($role): void {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            $role->users()->detach();
            $role->delete();
        });

        $this->auditLogger->recordAfterCommit(
            category: 'admin',
            action: 'role_deleted',
            outcome: 'success',
            severity: 'warning',
            subject: $role,
            context: [
                'data' => [
                    'name' => $role->name,
                    'permissions' => $permissions,
                ],
            ],
            message: 'Role deleted.'
        );

        return redirect()->route('admin.roles.index')->with([
            'alert-message' => 'Role deleted successfully.',
            'alert-type' => 'success',
        ]);
    }
}
