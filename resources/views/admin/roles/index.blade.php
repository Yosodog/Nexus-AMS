@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Manage Roles</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>All Roles</span>
            <a href="{{ route('admin.roles.create') }}" class="btn btn-sm btn-success">
                <i class="bi bi-plus-circle me-1"></i> New Role
            </a>
        </div>
        <div class="card-body table-responsive">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Protected</th>
                    <th>Permissions</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($roles as $role)
                    <tr>
                        <td>{{ ucfirst($role->name) }}</td>
                        <td>
                            @if($role->protected)
                                <span class="badge bg-secondary">Yes</span>
                            @else
                                <span class="badge bg-light text-muted">No</span>
                            @endif
                        </td>
                        <td>
                            @foreach($role->permissions() as $perm)
                                <span class="badge bg-info text-dark me-1">{{ $perm }}</span>
                            @endforeach
                        </td>
                        <td class="text-end">
                            @if(!$role->protected)
                                <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>

                                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="d-inline"
                                      onsubmit="return confirm('Are you sure you want to delete this role?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            @else
                                <span class="text-muted">Locked</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection