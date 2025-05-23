@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Edit Role: {{ ucfirst($role->name) }}</h3>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
        @csrf
        @method('PUT')

        <div class="card mt-3 shadow-sm">
            <div class="card-header">Role Settings</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" value="{{ old('name', $role->name) }}" class="form-control" {{ $role->protected ? 'readonly disabled' : '' }}>
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
                                            {{ in_array($perm, $role->permissions->pluck('permission')->toArray()) ? 'checked' : '' }}
                                            {{ $role->protected ? 'disabled' : '' }}>
                                    <label for="perm-{{ $perm }}" class="form-check-label">{{ $perm }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <a href="{{ route('admin.roles.index') }}" class="btn btn-secondary">Back</a>
                @if(!$role->protected)
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                @endif
            </div>
        </div>
    </form>
@endsection