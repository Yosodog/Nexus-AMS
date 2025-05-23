<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view-users');

        $users = User::with('nation')
            ->paginate(25);

        return view('admin.users.index', compact('users'));
    }

    /**
     * @param User $user
     * @return View
     * @throws AuthorizationException
     */
    public function edit(User $user): View
    {
        $this->authorize('edit-users');

        $allRoles = Role::orderBy('name')->get();

        return view('admin.users.edit', compact('user', 'allRoles'));
    }

    /**
     *
     * @param Request $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     * @throws AuthorizationException
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('edit-users');

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'is_admin' => ['required', 'boolean'],
            'disabled' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', 'min:8'],
            'nation_id' => ['nullable', 'integer', 'exists:nations,id'],
            'verified_at' => ['nullable'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->is_admin = (bool) $validated['is_admin'];
        $user->disabled = filter_var($validated['disabled'], FILTER_VALIDATE_BOOLEAN);
        $user->nation_id = $validated['nation_id'] ?? null;
        $user->verified_at = $validated['verified_at'] ? now() : null;

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // Sync only non-protected roles
        if (isset($validated['roles'])) {
            $allowedRoles = Role::whereIn('id', $validated['roles'])->pluck('id');

            $user->roles()->sync($allowedRoles);
        }

        return redirect()->route('admin.users.index')->with([
            'alert-message' => 'User updated successfully.',
            'alert-type' => 'success'
        ]);
    }
}
