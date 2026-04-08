@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <div class="mb-6">
        <div class="w-full">
            <div class="row">
                <div class="col">
                    <h3 class="mb-1">Manage Roles</h3>
                    <p class="text-base-content/50 mb-0">Review role coverage, update permissions, and keep your access model organized.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="w-full">
        <div class="row g-3 mt-1">
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-base-content/50 small font-semibold">Total roles</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['total_roles'] }}</div>
                            </div>
                            <span class="text-primary fs-3"><i class="o-users"></i></span>
                        </div>
                        <p class="text-base-content/50 small mb-0 mt-3">All roles currently available to assign.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-base-content/50 small font-semibold">Protected roles</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['protected_roles'] }}</div>
                            </div>
                            <span class="text-warning fs-3"><i class="o-shield-check-lock"></i></span>
                        </div>
                        <p class="text-base-content/50 small mb-0 mt-3">Locked roles that cannot be edited or removed.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-base-content/50 small font-semibold">Unique permissions</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['unique_permissions'] }}</div>
                            </div>
                            <span class="text-success fs-3"><i class="o-key"></i></span>
                        </div>
                        <p class="text-base-content/50 small mb-0 mt-3">Distinct capabilities currently in use across roles.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Role directory</h5>
                    <span class="text-base-content/50 small">Assign, edit, or retire roles as your governance evolves.</span>
                </div>
                <a href="{{ route('admin.roles.create') }}" class="btn btn-success">
                    <i class="o-plus-circle me-1"></i>
                    New Role
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Permissions</th>
                        <th scope="col" class="text-center">Members</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($roles as $role)
                        <tr>
                            <td class="font-semibold text-capitalize">{{ $role->name }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @forelse($role->permissions as $permission)
                                        <span class="badge rounded-pill bg-info text-dark">
                                            {{ Str::headline($permission->permission) }}
                                        </span>
                                    @empty
                                        <span class="text-base-content/50">No permissions assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">{{ $role->users_count }}</span>
                            </td>
                            <td class="text-center">
                                @if($role->protected)
                                    <span class="badge bg-secondary"><i class="o-lock-closed-fill me-1"></i>Protected</span>
                                @else
                                    <span class="badge bg-success"><i class="o-lock-open me-1"></i>Editable</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if(!$role->protected)
                                    <div class="btn-group" role="group">
                                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="o-pencil"></i> Edit
                                        </a>
                                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to delete this role?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="o-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-base-content/50">Locked</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

