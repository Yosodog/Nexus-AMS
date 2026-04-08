@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <x-header title="Manage Roles" separator>
        <x-slot:subtitle>Review permission coverage, clean up stale roles, and keep access assignments readable.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.roles.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="o-plus" class="size-4" />
                New Role
            </a>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <x-stat title="Total Roles" :value="number_format($stats['total_roles'])" icon="o-users" color="text-primary" description="Assignable roles in the system" />
        <x-stat title="Protected Roles" :value="number_format($stats['protected_roles'])" icon="o-lock-closed" color="text-warning" description="Locked from edits and deletion" />
        <x-stat title="Unique Permissions" :value="number_format($stats['unique_permissions'])" icon="o-key" color="text-success" description="Distinct capabilities across all roles" />
    </div>

    <x-card>
        <x-slot:title>
            <div>
                Role Directory
                <div class="text-sm font-normal text-base-content/60">All role definitions with permission coverage and member counts.</div>
            </div>
        </x-slot:title>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Permissions</th>
                        <th>Members</th>
                        <th>Status</th>
                        <th data-sortable="false" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roles as $role)
                        <tr>
                            <td>
                                <div class="font-semibold text-base-content">{{ Str::headline($role->name) }}</div>
                                <div class="text-sm text-base-content/60">{{ $role->permissions->count() }} permission{{ $role->permissions->count() === 1 ? '' : 's' }}</div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @forelse($role->permissions as $permission)
                                        <x-badge :value="Str::headline($permission->permission)" class="badge-primary badge-outline badge-sm" />
                                    @empty
                                        <span class="text-sm text-base-content/50">No permissions assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <x-badge :value="(string) $role->users_count" class="badge-ghost badge-sm" />
                            </td>
                            <td>
                                @if($role->protected)
                                    <x-badge value="Protected" class="badge-warning badge-sm" />
                                @else
                                    <x-badge value="Editable" class="badge-success badge-sm" />
                                @endif
                            </td>
                            <td class="text-right">
                                @if(! $role->protected)
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-outline btn-primary btn-sm">
                                            <x-icon name="o-pencil-square" class="size-4" />
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('Are you sure you want to delete this role?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline btn-error btn-sm">
                                                <x-icon name="o-trash" class="size-4" />
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/50">Locked</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
