@extends('layouts.admin')

@section('content')
    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col">
                <h3><i class="bi bi-person-lines-fill me-2"></i>Edit User: {{ $user->name }}</h3>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.users.update', $user->id) }}" class="row g-4">
            @csrf
            @method('PUT')

            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-person-fill"></i> Basic Info
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input name="name" type="text" class="form-control" value="{{ old('name', $user->name) }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Is Admin</label>
                            <select name="is_admin" class="form-select">
                                <option value="0" @selected(!$user->is_admin)>No</option>
                                <option value="1" @selected($user->is_admin)>Yes</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Status</label>
                            <select name="disabled" class="form-select">
                                <option value="0" @selected(!$user->disabled)>Enabled</option>
                                <option value="1" @selected($user->disabled)>Disabled</option>
                            </select>
                        </div>
                        @if($user->nation)
                            <div class="mb-3">
                                <label class="form-label">Nation ID</label>
                                <input name="nation_id" type="number" class="form-control" value="{{ old('nation_id', $user->nation_id) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Verified</label>
                                <select name="verified_at" class="form-select">
                                    <option value="" @selected(!$user->verified_at)>Not Verified</option>
                                    <option value="1" @selected($user->verified_at)>Verified</option>
                                </select>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="roles" class="form-label">Roles</label>
                            <select name="roles[]" id="roles" class="form-select" multiple>
                                @foreach($allRoles as $role)
                                    <option value="{{ $role->id }}"
                                            {{ $user->roles->pluck('id')->contains($role->id) ? 'selected' : '' }}>
                                        {{ ucfirst($role->name) }}{{ $role->protected ? ' (System)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Hold Ctrl/Cmd to select multiple roles.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-lock-fill"></i> Change Password
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input name="password" type="password" class="form-control" placeholder="Leave blank to keep current">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input name="password_confirmation" type="password" class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 text-end">
                <button class="btn btn-success">
                    <i class="bi bi-save me-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
@endsection