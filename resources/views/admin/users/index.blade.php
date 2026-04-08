@extends('layouts.admin')

@section('content')
    @php
        use Illuminate\Support\Str;

        $filters = $filters ?? [
            'search' => '',
            'status' => 'enabled',
            'is_admin' => false,
            'alliance_member' => false,
            'verification' => 'any',
        ];
        $mfaRequirements = $mfaRequirements ?? [
            'all_users' => false,
            'admins' => false,
        ];
        $membershipService = app(\App\Services\AllianceMembershipService::class);
    @endphp

    <x-header title="Manage Users" separator>
        <x-slot:subtitle>Filter accounts, review access posture, and jump directly into member management.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline btn-primary btn-sm">Manage Roles</a>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <x-stat title="Total Users" :value="number_format($stats['total_users'])" icon="o-users" color="text-primary" description="All application accounts" />
        <x-stat title="Admins" :value="number_format($stats['admins'])" icon="o-shield-check" color="text-error" description="Elevated access holders" />
        <x-stat title="Active Today" :value="number_format($stats['active_today'])" icon="o-bolt" color="text-success" description="Seen within the last 24 hours" />
    </div>

    <div class="mb-6">
        <x-card>
            <x-slot:title>
                <div>
                    User Directory
                    <div class="text-sm font-normal text-base-content/60">Showing {{ $users->firstItem() ?? 0 }}-{{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users.</div>
                </div>
            </x-slot:title>

            <form method="GET" class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                <x-input label="Search" name="search" :value="$filters['search']" placeholder="Name, email, Discord, or nation ID" class="xl:col-span-2" />

                <div>
                    <label for="filter-status" class="form-label">Account Status</label>
                    <select id="filter-status" name="status" class="select select-bordered w-full">
                        <option value="enabled" @selected($filters['status'] === 'enabled')>Enabled</option>
                        <option value="disabled" @selected($filters['status'] === 'disabled')>Disabled</option>
                        <option value="all" @selected($filters['status'] === 'all')>All accounts</option>
                    </select>
                </div>

                <div>
                    <label for="filter-verification" class="form-label">Verification</label>
                    <select id="filter-verification" name="verification" class="select select-bordered w-full">
                        <option value="any" @selected($filters['verification'] === 'any')>Any</option>
                        <option value="verified" @selected($filters['verification'] === 'verified')>Verified</option>
                        <option value="unverified" @selected($filters['verification'] === 'unverified')>Unverified</option>
                    </select>
                </div>

                <label class="label cursor-pointer justify-start gap-3 rounded-box border border-base-300 px-4 py-3">
                    <input class="checkbox checkbox-primary" type="checkbox" name="is_admin" value="1" @checked($filters['is_admin'])>
                    <span class="label-text font-medium">Admins only</span>
                </label>

                <label class="label cursor-pointer justify-start gap-3 rounded-box border border-base-300 px-4 py-3">
                    <input class="checkbox checkbox-primary" type="checkbox" name="alliance_member" value="1" @checked($filters['alliance_member'])>
                    <span class="label-text font-medium">Alliance members</span>
                </label>

                <div class="flex items-end gap-2 xl:col-span-6 xl:justify-end">
                    <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm">Reset</a>
                </div>
            </form>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Discord</th>
                            <th>Nation</th>
                            <th>Alliance</th>
                            <th>Roles</th>
                            <th>Last Active</th>
                            <th data-sortable="false" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $user)
                            @php
                                $isAllianceMember = $user->nation && $membershipService->contains($user->nation->alliance_id);
                            @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold text-base-content">{{ $user->name }}</div>
                                    <div class="text-sm text-base-content/60">{{ $user->email }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if($user->is_admin)
                                            <x-badge value="Admin" class="badge-primary badge-sm" />
                                        @endif
                                        @if($user->disabled)
                                            <x-badge value="Disabled" class="badge-warning badge-sm" />
                                        @endif
                                        @if(is_null($user->verified_at))
                                            <x-badge value="Unverified" class="badge-ghost badge-sm" />
                                        @endif
                                        @if($user->last_active_at && $user->last_active_at->greaterThanOrEqualTo(now()->subMinutes(5)))
                                            <x-badge value="Online" class="badge-success badge-sm" />
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $user->nation->discord ?? '—' }}</td>
                                <td>
                                    @if($user->nation_id)
                                        <a href="https://politicsandwar.com/nation/id={{ $user->nation_id }}" target="_blank" rel="noopener" class="link link-primary font-medium">
                                            {{ $user->nation_id }}
                                        </a>
                                    @else
                                        <span class="text-base-content/50">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($isAllianceMember)
                                        <x-badge value="Member" class="badge-primary badge-sm" />
                                    @else
                                        <x-badge value="Outside" class="badge-ghost badge-sm" />
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        @forelse($user->roles as $role)
                                            <x-badge :value="Str::title($role->name)" class="badge-primary badge-outline badge-sm" />
                                        @empty
                                            <span class="text-sm text-base-content/50">No roles assigned</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>{{ $user->last_active_at ? $user->last_active_at->diffForHumans() : 'Never' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-primary btn-sm">
                                        <x-icon name="o-pencil-square" class="size-4" />
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <span class="text-sm text-base-content/60">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
                {{ $users->onEachSide(1)->links() }}
            </div>
        </x-card>
    </div>

    <x-card>
        <x-slot:title>
            <div>
                MFA Requirements
                <div class="text-sm font-normal text-base-content/60">Control Fortify enrollment requirements without leaving this screen.</div>
            </div>
        </x-slot:title>

        <form method="POST" action="{{ route('admin.users.mfa-requirements') }}" class="grid gap-4 lg:grid-cols-3">
            @csrf

            <label class="flex items-start gap-3 rounded-box border border-base-300 px-4 py-4">
                <input class="toggle toggle-primary mt-1" type="checkbox" id="require-mfa-all-users" name="require_mfa_all_users" value="1" @checked($mfaRequirements['all_users'])>
                <span>
                    <span class="block font-semibold text-base-content">Require MFA for all users</span>
                    <span class="mt-1 block text-sm text-base-content/60">Force every authenticated user to enroll before using the app.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-box border border-base-300 px-4 py-4">
                <input class="toggle toggle-primary mt-1" type="checkbox" id="require-mfa-admins" name="require_mfa_admins" value="1" @checked($mfaRequirements['admins'])>
                <span>
                    <span class="block font-semibold text-base-content">Require MFA for admins</span>
                    <span class="mt-1 block text-sm text-base-content/60">Protect privileged accounts even when the global requirement stays off.</span>
                </span>
            </label>

            <div class="flex h-full flex-col justify-between rounded-box bg-base-200/70 px-4 py-4">
                <div class="text-sm text-base-content/70">
                    Administrators should enable MFA first before turning on the all-user requirement.
                </div>
                <button type="submit" class="btn btn-primary mt-4 w-full">Save MFA Policy</button>
            </div>
        </form>
    </div>
@endsection
