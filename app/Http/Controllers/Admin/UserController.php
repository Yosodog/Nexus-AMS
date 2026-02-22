<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\DiscordAccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @throws AuthorizationException
     */
    public function index(Request $request): View
    {
        $this->authorize('view-users');

        $statsQuery = User::query();

        $stats = [
            'total_users' => (clone $statsQuery)->count(),
            'admins' => (clone $statsQuery)->where('is_admin', true)->count(),
            'active_today' => (clone $statsQuery)->whereNotNull('last_active_at')->where('last_active_at', '>=', now()->subDay())->count(),
        ];

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'enabled');
        $verification = $request->query('verification', 'any');

        $status = in_array($status, ['enabled', 'disabled', 'all'], true) ? $status : 'enabled';
        $verification = in_array($verification, ['any', 'verified', 'unverified'], true) ? $verification : 'any';

        $filters = [
            'search' => $search,
            'status' => $status,
            'is_admin' => $request->boolean('is_admin'),
            'alliance_member' => $request->boolean('alliance_member'),
            'verification' => $verification,
        ];

        $usersQuery = User::query()
            ->with(['nation', 'roles'])
            ->latest('last_active_at');

        if ($filters['search'] !== '') {
            $usersQuery->where(function ($query) use ($filters) {
                $searchTerm = '%'.$filters['search'].'%';

                $query->where('name', 'like', $searchTerm)
                    ->orWhere('email', 'like', $searchTerm)
                    ->orWhereHas('nation', fn ($nationQuery) => $nationQuery->where('discord', 'like', $searchTerm));

                if (is_numeric($filters['search'])) {
                    $query->orWhere('nation_id', (int) $filters['search']);
                }
            });
        }

        if ($filters['status'] === 'enabled') {
            $usersQuery->where('disabled', false);
        } elseif ($filters['status'] === 'disabled') {
            $usersQuery->where('disabled', true);
        }

        if ($filters['is_admin']) {
            $usersQuery->where('is_admin', true);
        }

        if ($filters['alliance_member']) {
            /** @var AllianceMembershipService $membershipService */
            $membershipService = app(AllianceMembershipService::class);
            $allianceIds = $membershipService->getAllianceIds();

            if ($allianceIds->isEmpty()) {
                $usersQuery->whereRaw('0 = 1');
            } else {
                $usersQuery->whereHas('nation', fn ($nationQuery) => $nationQuery->whereIn('alliance_id', $allianceIds));
            }
        }

        if ($filters['verification'] === 'verified') {
            $usersQuery->whereNotNull('verified_at');
        } elseif ($filters['verification'] === 'unverified') {
            $usersQuery->whereNull('verified_at');
        }

        $users = $usersQuery
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'stats', 'filters'));
    }

    /**
     * @throws AuthorizationException
     */
    public function edit(User $user): View
    {
        $this->authorize('edit-users');

        $allRoles = Role::orderBy('name')->get();

        $user->load([
            'nation.alliance',
            'nation.resources',
            'nation.latestSignIn',
            'accounts' => fn ($query) => $query->orderBy('name'),
        ]);

        $accounts = $user->accounts;
        $accountIds = $accounts->pluck('id');

        $recentTransactions = collect();

        if ($accountIds->isNotEmpty() || $user->nation_id) {
            $recentTransactionsQuery = Transaction::with(['fromAccount', 'toAccount', 'nation'])
                ->latest('created_at')
                ->limit(10);

            if ($accountIds->isNotEmpty()) {
                $recentTransactionsQuery->where(function ($query) use ($accountIds) {
                    $query->whereIn('from_account_id', $accountIds)
                        ->orWhereIn('to_account_id', $accountIds);
                });

                if ($user->nation_id) {
                    $recentTransactionsQuery->orWhere('nation_id', $user->nation_id);
                }
            } else {
                $recentTransactionsQuery->where('nation_id', $user->nation_id);
            }

            $recentTransactions = $recentTransactionsQuery->get();
        }

        $latestSignIn = optional($user->nation)->latestSignIn;
        $discordAccount = DiscordAccountService::getActiveAccount($user);

        return view('admin.users.edit', compact(
            'user',
            'discordAccount',
            'allRoles',
            'accounts',
            'recentTransactions',
            'latestSignIn'
        ));
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('edit-users');

        $validated = $request->validate([
            'name' => ['required', 'string', Rule::unique('users', 'name')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'is_admin' => ['required', 'boolean'],
            'disabled' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', 'min:8'],
            'nation_id' => ['nullable', 'integer', 'exists:nations,id'],
            'verified_at' => ['nullable'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $before = $user->only(['name', 'email', 'is_admin', 'disabled', 'nation_id', 'verified_at']);
        $beforeRoles = $user->roles()->pluck('roles.id')->sort()->values()->all();

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->is_admin = (bool) $validated['is_admin'];
        $user->disabled = filter_var($validated['disabled'], FILTER_VALIDATE_BOOLEAN);
        $user->nation_id = $validated['nation_id'] ?? null;

        if (array_key_exists('verified_at', $validated)) {
            $user->verified_at = filled($validated['verified_at']) ? now() : null;
        }

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // Sync only non-protected roles
        if (isset($validated['roles'])) {
            $allowedRoles = Role::whereIn('id', $validated['roles'])->pluck('id');

            $user->roles()->sync($allowedRoles);
        }

        $after = $user->fresh()->only(['name', 'email', 'is_admin', 'disabled', 'nation_id', 'verified_at']);
        $afterRoles = $user->roles()->pluck('roles.id')->sort()->values()->all();
        $changes = [];

        foreach ($after as $field => $value) {
            $beforeValue = $before[$field] ?? null;

            if ((string) $beforeValue !== (string) $value) {
                $changes[$field] = [
                    'from' => $beforeValue,
                    'to' => $value,
                ];
            }
        }

        if ($beforeRoles !== $afterRoles) {
            $changes['roles'] = [
                'from' => $beforeRoles,
                'to' => $afterRoles,
            ];
        }

        $this->auditLogger->recordAfterCommit(
            category: 'admin',
            action: 'user_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $user,
            context: [
                'changes' => $changes,
            ],
            message: 'User updated.'
        );

        return redirect()->route('admin.users.index')->with([
            'alert-message' => 'User updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function unlinkDiscord(User $user): RedirectResponse
    {
        $this->authorize('edit-users');

        $discordAccount = DiscordAccountService::unlinkUser($user);

        $message = $discordAccount
            ? 'Discord account unlinked for this user.'
            : 'No Discord account was linked for this user.';

        if ($discordAccount) {
            $this->auditLogger->success(
                category: 'admin',
                action: 'discord_unlinked',
                subject: $user,
                context: [
                    'data' => [
                        'discord_id' => $discordAccount->discord_id,
                    ],
                ],
                message: 'Discord account unlinked.'
            );
        }

        return redirect()->route('admin.users.edit', $user)->with([
            'alert-message' => $message,
            'alert-type' => $discordAccount ? 'success' : 'info',
        ]);
    }
}
