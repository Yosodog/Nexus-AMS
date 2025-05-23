@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Create Role</h3>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.store') }}">
        @csrf

        <div class="card mt-3 shadow-sm">
            <div class="card-header">New Role</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Role Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Permissions</label>
                    <div class="row">
                        @foreach($permissions as $perm)
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="checkbox" name="permissions[]" value="{{ $perm }}"
                                           class="form-check-input"
                                           id="perm-{{ $perm }}"
                                            {{ in_array($perm, old('permissions', [])) ? 'checked' : '' }}>
                                    <label for="perm-{{ $perm }}" class="form-check-label">{{ $perm }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <a href="{{ route('admin.roles.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save me-1"></i> Create Role
                </button>
            </div>
        </div>
    </form>
@endsection